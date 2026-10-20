<?php

declare(strict_types=1);

namespace Emc\Personen;

use Emc\Core\DomainFehler;
use Emc\Dateien\DokumentRepository;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\OneDrive\Auftraege;
use Emc\OneDrive\Uebertragung;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Löscht eine Person nach DSGVO vollständig: Konto (auch frühere Konten im Protokoll), eigene Fahrzeuge samt Dateien
 * (außer an den Verein übergebene), Bezüge in Vereinsdaten und Personendaten im Änderungsprotokoll.
 * Datenbankteil in einer Transaktion, Dateien vom Webspace erst nach dem Commit.
 */
final class DsgvoLoeschung
{
    public const EIGENE_PERSON = 'Die eigene Person kann nicht nach DSGVO gelöscht werden. Bitte einen anderen Administrator darum bitten.';
    public const KEIN_ADMIN = 'Nur ein aktiver Administrator darf Personen nach DSGVO löschen.';
    public const LETZTER_ADMIN = 'Diese Person hat den letzten aktiven Administrator-Zugang. Es muss mindestens ein aktiver Administrator bleiben.';
    public const NACHWEIS = 'nach DSGVO gelöscht';

    public function __construct(
        private \PDO $pdo,
        private DokumentRepository $dokumente,
        private Aenderungsprotokoll $protokoll,
        private FahrzeugRepository $fahrzeuge,
        private Auftraege $auftraege,
        private ?Uebertragung $uebertragung = null,
    ) {
    }

    /**
     * Was beim Löschen passieren würde; ändert nichts.
     *
     * `onedrive_konto_der_person`: die OneDrive-Verbindung läuft über ein Microsoft-Konto mit einer E-Mail der Person.
     *
     * @return array{person: array<string,mixed>, benutzer: ?array<string,mixed>, fahrzeuge: list<array{id:int, inventarnummer:string, bezeichnung:string, dokumente:int, rechnungen:int}>, protokolleintraege:int, verweigert:?string, onedrive_konto_der_person:bool}
     */
    public function vorschau(int $personId, int $ausfuehrenderBenutzerId): array
    {
        $person = $this->ladePerson($personId, false);
        $benutzer = $this->ladeBenutzer($personId, false);
        $fahrzeuge = [];
        foreach ($this->eigeneFahrzeuge($personId, false) as $fz) {
            $fahrzeuge[] = [
                'id' => (int) $fz['id'],
                'inventarnummer' => (string) $fz['inventarnummer'],
                'bezeichnung' => (string) $fz['bezeichnung'],
                'dokumente' => count($this->dokumente->idsFuerObjekt('fahrzeug', (int) $fz['id'])),
                'rechnungen' => $this->rechnungen((int) $fz['id']),
            ];
        }
        $k = $this->kennungen($personId, $person, $benutzer);
        $mails = array_map('mb_strtolower', array_filter(
            [...$k['mails'], trim((string) ($person['email'] ?? '')), trim((string) ($benutzer['email'] ?? ''))],
            fn(string $m): bool => $m !== ''
        ));
        $st = $this->pdo->prepare("SELECT wert FROM einstellung WHERE schluessel = 'onedrive_konto'");
        $st->execute();
        $onedriveKonto = mb_strtolower(trim((string) $st->fetchColumn()));
        $admins = array_map('intval', $this->pdo->query("SELECT id FROM benutzer WHERE rolle = 'admin' AND aktiv = 1")->fetchAll(\PDO::FETCH_COLUMN));
        $verweigert = $this->verweigerung($personId, $benutzer, $ausfuehrenderBenutzerId, $admins);
        if ($benutzer !== null) {
            unset($benutzer['passwort_hash']);
        }
        return [
            'person' => $person,
            'benutzer' => $benutzer,
            'fahrzeuge' => $fahrzeuge,
            'protokolleintraege' => $this->protokoll->zaehleBetroffene($k['begriffe'], $k['konten'], $k['objekte']),
            'verweigert' => $verweigert,
            'onedrive_konto_der_person' => $onedriveKonto !== '' && in_array($onedriveKonto, $mails, true),
        ];
    }

    /** Anzahl der Rechnungen am Fahrzeug (bleiben bei Übergabe an den Verein erhalten, oft mit Name und Adresse). */
    private function rechnungen(int $fahrzeugId): int
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM dokument d JOIN dokument_zuordnung z ON z.dokument_id = d.id WHERE z.objekt_typ = 'fahrzeug' AND z.objekt_id = ? AND d.kategorie = 'rechnung'"
        );
        $st->execute([$fahrzeugId]);
        return (int) $st->fetchColumn();
    }

    /**
     * @param list<int> $anVereinUebergeben IDs eigener Fahrzeuge, die erhalten bleiben und dem Verein gehören sollen
     * @throws DomainFehler bei Verweigerung, unbekannter Person oder fremden Fahrzeug-IDs
     */
    public function loesche(int $personId, array $anVereinUebergeben, int $ausfuehrenderBenutzerId): void
    {
        $uebergeben = array_values(array_unique(array_map('intval', $anVereinUebergeben)));
        /** @var array<int,string> $pfade Dokument-ID => relativer Pfad */
        $pfade = [];
        $this->pdo->beginTransaction();
        try {
            // 1. Verweigerungsgründe – Admin-Zugänge gesperrt, damit nicht gleichzeitig der letzte Admin verschwindet;
            //    entschieden wird mit genau den gesperrten IDs (kein späterer Snapshot-Read).
            $admins = array_map('intval', $this->pdo->query("SELECT id FROM benutzer WHERE rolle = 'admin' AND aktiv = 1 FOR UPDATE")->fetchAll(\PDO::FETCH_COLUMN));
            $person = $this->ladePerson($personId, true);
            $benutzer = $this->ladeBenutzer($personId, true);
            $grund = $this->verweigerung($personId, $benutzer, $ausfuehrenderBenutzerId, $admins);
            if ($grund !== null) {
                throw new DomainFehler($grund);
            }
            $eigene = array_map(fn(array $fz): int => (int) $fz['id'], $this->eigeneFahrzeuge($personId, true));
            if (array_diff($uebergeben, $eigene) !== []) {
                throw new DomainFehler('Es können nur Fahrzeuge dieser Person an den Verein übergeben werden.');
            }
            $benutzerId = $benutzer === null ? null : (int) $benutzer['id'];
            // Vor allen Änderungen: auch frühere Namen, E-Mails, Telefonnummern und Konten aus dem Protokoll.
            $k = $this->kennungen($personId, $person, $benutzer);
            $eigeneObjekte = $k['objekte'];

            // 2. Übergabe an den Verein.
            $setzeVerein = $this->pdo->prepare('UPDATE fahrzeug SET eigentuemer_person_id = NULL WHERE id = ?');
            foreach ($uebergeben as $fzId) {
                $setzeVerein->execute([$fzId]);
                $this->protokoll->schreibe($ausfuehrenderBenutzerId, 'fahrzeug', $fzId, 'geaendert', [
                    'eigentuemer' => ['alt' => Aenderungsprotokoll::EHEMALIGES_MITGLIED, 'neu' => 'Verein'],
                ]);
            }

            // 3. Übrige eigene Fahrzeuge samt nur ihnen zugeordneter Dokumente; geteilte verlieren nur diese Zuordnung.
            //    Nie DokumentRepository::loeschen()/entferneVonObjekt() hier: die öffnen eine eigene Transaktion.
            foreach (array_diff($eigene, $uebergeben) as $fzId) {
                foreach ($this->dokumenteNurHier($fzId) as $dokId) {
                    $pfad = $this->loescheDokument($dokId);
                    if ($pfad !== null) {
                        $pfade[$dokId] = $pfad;
                    }
                }
                $this->pdo->prepare("DELETE FROM dokument_zuordnung WHERE objekt_typ = 'fahrzeug' AND objekt_id = ?")->execute([$fzId]);
                $st = $this->pdo->prepare('SELECT id FROM decoder WHERE fahrzeug_id = ?');
                $st->execute([$fzId]);
                foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $decId) {
                    $eigeneObjekte[] = ['decoder', (int) $decId];
                }
                $this->fahrzeuge->loeschen($fzId); // Decoder, Funktionen, CVs per CASCADE
                $eigeneObjekte[] = ['fahrzeug', $fzId];
            }

            // 4. Anmeldeversuche aller bekannten E-Mails (Person, Konto, frühere) und das Benutzerkonto.
            //    Fremdschlüssel: dokument.hochgeladen_von und fahrzeug.angelegt_von_benutzer_id werden NULL.
            if ($k['mails'] !== []) {
                $this->pdo->prepare('DELETE FROM login_versuch WHERE email IN (' . implode(', ', array_fill(0, count($k['mails']), '?')) . ')')
                    ->execute($k['mails']);
            }
            if ($benutzerId !== null) {
                $this->pdo->prepare('DELETE FROM benutzer WHERE id = ?')->execute([$benutzerId]);
            }

            // 5. Änderungsprotokoll bereinigen (Konto und frühere Konten).
            $this->protokoll->anonymisiere($k['begriffe'], $k['konten'], $eigeneObjekte);

            // 6. Person löschen und Nachweis ohne personenbezogene Daten.
            $this->pdo->prepare('DELETE FROM person WHERE id = ?')->execute([$personId]);
            $this->protokoll->schreibe($ausfuehrenderBenutzerId, 'person', $personId, 'geloescht', [
                'dsgvo' => ['alt' => null, 'neu' => self::NACHWEIS],
            ]);

            // Ab dem Commit müssen die Dateien weg, auch wenn der Browser die Anfrage abbricht.
            ignore_user_abort(true);
            $this->pdo->commit();
        } catch (\Throwable $t) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $t;
        }

        // 7. Erst nach dem Commit: Dateien vom Webspace, dann OneDrive-Lösch-Aufträge gleich nebenbei ausführen.
        foreach ($pfade as $dokId => $pfad) {
            if (!$this->dokumente->entferneDateien($pfad)) {
                error_log('DSGVO-Löschung: Dateien von Dokument Nr. ' . $dokId . ' konnten nicht vom Webspace entfernt werden.');
            }
        }
        $this->uebertragung?->nebenbei();
    }

    /**
     * Dokumente, die nur diesem Fahrzeug zugeordnet sind – mit sperrenden Reads, damit eine gleichzeitig angelegte
     * Zuordnung weder übersehen noch ohne ihr Dokument gelöscht wird.
     *
     * @return list<int>
     */
    private function dokumenteNurHier(int $fahrzeugId): array
    {
        $st = $this->pdo->prepare("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'fahrzeug' AND objekt_id = ? ORDER BY dokument_id FOR UPDATE");
        $st->execute([$fahrzeugId]);
        $andere = $this->pdo->prepare("SELECT COUNT(*) FROM dokument_zuordnung WHERE dokument_id = ? AND NOT (objekt_typ = 'fahrzeug' AND objekt_id = ?) FOR UPDATE");
        $ids = [];
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $dokId) {
            $andere->execute([(int) $dokId, $fahrzeugId]);
            if ((int) $andere->fetchColumn() === 0) {
                $ids[] = (int) $dokId;
            }
        }
        return $ids;
    }

    /**
     * Datensatz löschen (ohne Dateien). Bei OneDrive-Ablage immer genau ein wartender Lösch-Auftrag – auch wenn
     * DokumentRepository ohne Aufträge läuft (OneDrive derzeit nicht konfiguriert); Hochlade-Aufträge entfallen.
     */
    private function loescheDokument(int $dokId): ?string
    {
        $dok = $this->dokumente->finde($dokId);
        if ($dok === null) {
            return null;
        }
        $pfad = $this->dokumente->loescheDatensatz($dokId);
        if (!array_key_exists('ablage', $dok)) {
            return $pfad; // vor Migration 004: weder OneDrive-Spalten noch Auftragstabelle
        }
        $itemId = is_string($dok['onedrive_item_id'] ?? null) ? $dok['onedrive_item_id'] : '';
        if ($dok['ablage'] === 'onedrive' && $itemId !== '') {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'loeschen' AND status = 'wartet' AND item_id = ?");
            $st->execute([$itemId]);
            if ((int) $st->fetchColumn() === 0) {
                $drive = is_string($dok['onedrive_drive_id'] ?? null) ? $dok['onedrive_drive_id'] : null;
                $this->auftraege->neu('loeschen', null, $itemId, null, $drive);
            }
        }
        // Hochlade-Aufträge (auch fehlgeschlagene: letzter_fehler kann Dateinamen enthalten) haben kein Ziel mehr.
        $this->pdo->prepare("DELETE FROM onedrive_auftrag WHERE art = 'hochladen' AND dokument_id = ?")->execute([$dokId]);
        return $pfad;
    }

    /**
     * @param ?array<string,mixed> $benutzer
     * @param list<int> $aktiveAdmins
     */
    private function verweigerung(int $personId, ?array $benutzer, int $ausfuehrenderBenutzerId, array $aktiveAdmins): ?string
    {
        $st = $this->pdo->prepare('SELECT person_id FROM benutzer WHERE id = ?');
        $st->execute([$ausfuehrenderBenutzerId]);
        $eigenePerson = $st->fetchColumn();
        if ($eigenePerson !== false && (int) $eigenePerson === $personId) {
            return self::EIGENE_PERSON;
        }
        if (!in_array($ausfuehrenderBenutzerId, $aktiveAdmins, true)) {
            return self::KEIN_ADMIN;
        }
        // Mit den Prüfungen oben eigentlich ausgeschlossen (der Ausführende bleibt), aber als letzte Sicherung.
        if ($benutzer !== null && array_diff($aktiveAdmins, [(int) $benutzer['id']]) === []) {
            return self::LETZTER_ADMIN;
        }
        return null;
    }

    /**
     * Alle bekannten Kennungen der Person: aktuelle und frühere Namen, E-Mails und Telefonnummern (aus den
     * Personen-Einträgen im Protokoll), das Konto sowie frühere, schon gelöschte Konten (Einträge `benutzer` mit
     * `person_id` dieser Person) samt deren E-Mails. Namensbegriffe nur, wenn Vor- und Nachname bekannt sind
     * (sonst träfe der Nachname allein z. B. Angehörige).
     *
     * @param array<string,mixed> $person
     * @param ?array<string,mixed> $benutzer
     * @return array{begriffe:list<string>, mails:list<string>, konten:list<int>, objekte:list<array{0:string,1:int}>}
     */
    private function kennungen(int $personId, array $person, ?array $benutzer): array
    {
        $werte = ['vorname' => [], 'nachname' => [], 'email' => [], 'telefon' => []];
        $merke = function (string $feld, mixed $wert) use (&$werte): void {
            if (is_scalar($wert)) {
                $w = trim((string) $wert);
                if ($w !== '') {
                    $werte[$feld][mb_strtolower($w)] ??= $w;
                }
            }
        };
        foreach (array_keys($werte) as $feld) {
            $merke($feld, $person[$feld] ?? null);
        }
        foreach ($this->eintraege('person', $personId) as $aenderungen) {
            foreach (array_keys($werte) as $feld) {
                $merke($feld, $aenderungen[$feld]['alt'] ?? null);
                $merke($feld, $aenderungen[$feld]['neu'] ?? null);
            }
        }

        $konten = $benutzer === null ? [] : [(int) $benutzer['id']];
        $merke('email', $benutzer['email'] ?? null);
        foreach ($this->frueherKonten($personId) as $konto) {
            $konten[] = $konto;
        }
        $konten = array_values(array_unique($konten));
        foreach ($konten as $konto) {
            foreach ($this->eintraege('benutzer', $konto) as $aenderungen) {
                $merke('email', $aenderungen['email']['alt'] ?? null);
                $merke('email', $aenderungen['email']['neu'] ?? null);
            }
        }

        $begriffe = [];
        foreach ($werte['vorname'] as $vorname) {
            foreach ($werte['nachname'] as $nachname) {
                $begriffe[] = $vorname . ' ' . $nachname;
                $begriffe[] = $nachname . ', ' . $vorname;
                $begriffe[] = $nachname . ' ' . $vorname;
            }
        }
        $mails = $this->ohneFremdeMails(array_values($werte['email']), $personId, $benutzer);
        array_push($begriffe, ...$mails, ...array_values($werte['telefon']));

        $objekte = [['person', $personId]];
        foreach ($konten as $konto) {
            $objekte[] = ['benutzer', $konto];
        }
        return ['begriffe' => $begriffe, 'mails' => $mails, 'konten' => $konten, 'objekte' => $objekte];
    }

    /**
     * Ohne E-Mails, die heute einer anderen Person oder dem Konto einer anderen Person gehören (z. B. eine geteilte
     * Familienadresse): deren Anmeldeversuche und Fundstellen im Protokoll gehören dem anderen. Die E-Mail des
     * eigenen Kontos bleibt immer (sie ist eindeutig und wird mit dem Konto gelöscht).
     *
     * @param list<string> $mails
     * @param ?array<string,mixed> $benutzer
     * @return list<string>
     */
    private function ohneFremdeMails(array $mails, int $personId, ?array $benutzer): array
    {
        $konto = mb_strtolower(trim((string) ($benutzer['email'] ?? '')));
        $person = $this->pdo->prepare('SELECT COUNT(*) FROM person WHERE email = ? AND id <> ?');
        $fremdesKonto = $this->pdo->prepare('SELECT COUNT(*) FROM benutzer WHERE email = ? AND person_id <> ?');
        $behalten = [];
        foreach ($mails as $mail) {
            if ($konto !== '' && mb_strtolower($mail) === $konto) {
                $behalten[] = $mail;
                continue;
            }
            $person->execute([$mail, $personId]);
            $fremdesKonto->execute([$mail, $personId]);
            if ((int) $person->fetchColumn() === 0 && (int) $fremdesKonto->fetchColumn() === 0) {
                $behalten[] = $mail;
            }
        }
        return $behalten;
    }

    /**
     * IDs früherer Konten der Person laut Protokoll („angelegt“ mit person_id). Konten, die es noch gibt und die
     * einer anderen Person gehören, werden ausgelassen.
     *
     * @return list<int>
     */
    private function frueherKonten(int $personId): array
    {
        $st = $this->pdo->query("SELECT objekt_id, aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'benutzer' AND aenderungen LIKE '%person_id%'");
        $besitzer = $this->pdo->prepare('SELECT person_id FROM benutzer WHERE id = ?');
        $konten = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $daten = json_decode((string) $z['aenderungen'], true);
            if (!is_array($daten) || !is_array($daten['person_id'] ?? null)) {
                continue;
            }
            $treffer = false;
            foreach (['alt', 'neu'] as $seite) {
                $w = $daten['person_id'][$seite] ?? null;
                if (is_scalar($w) && (string) $w === (string) $personId) {
                    $treffer = true;
                }
            }
            if (!$treffer) {
                continue;
            }
            $konto = (int) $z['objekt_id'];
            $besitzer->execute([$konto]);
            $jetzt = $besitzer->fetchColumn();
            if ($jetzt === false || (int) $jetzt === $personId) {
                $konten[$konto] = $konto;
            }
        }
        return array_values($konten);
    }

    /** @return list<array<string,mixed>> dekodierte `aenderungen` aller Einträge des Objekts */
    private function eintraege(string $typ, int $id): array
    {
        $st = $this->pdo->prepare('SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = ? AND objekt_id = ? AND aenderungen IS NOT NULL ORDER BY id');
        $st->execute([$typ, $id]);
        $liste = [];
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $roh) {
            $daten = json_decode((string) $roh, true);
            if (is_array($daten)) {
                $liste[] = array_filter($daten, 'is_array');
            }
        }
        return $liste;
    }

    /** @return array<string,mixed> */
    private function ladePerson(int $personId, bool $sperren): array
    {
        $st = $this->pdo->prepare('SELECT * FROM person WHERE id = ?' . ($sperren ? ' FOR UPDATE' : ''));
        $st->execute([$personId]);
        $person = $st->fetch(\PDO::FETCH_ASSOC);
        if ($person === false) {
            throw new DomainFehler('Die Person wurde nicht gefunden.');
        }
        return $person;
    }

    /** @return ?array<string,mixed> */
    private function ladeBenutzer(int $personId, bool $sperren): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM benutzer WHERE person_id = ?' . ($sperren ? ' FOR UPDATE' : ''));
        $st->execute([$personId]);
        $benutzer = $st->fetch(\PDO::FETCH_ASSOC);
        return $benutzer === false ? null : $benutzer;
    }

    /** @return list<array<string,mixed>> */
    private function eigeneFahrzeuge(int $personId, bool $sperren): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, inventarnummer, bezeichnung FROM fahrzeug WHERE eigentuemer_person_id = ? ORDER BY inventarnummer, id' . ($sperren ? ' FOR UPDATE' : '')
        );
        $st->execute([$personId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
}
