<?php

declare(strict_types=1);

namespace Emc\Personen;

use Emc\Auth\KennwortVergessen;
use Emc\Boerse\AngebotRepository;
use Emc\Core\DomainFehler;
use Emc\Dateien\DokumentRepository;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\OneDrive\Auftraege;
use Emc\OneDrive\Uebertragung;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturRepository;
use Emc\Wartung\AusnahmeRepository;
use Emc\Wartung\WartungRepository;

/**
 * Löscht eine Person nach DSGVO vollständig: Konto (auch frühere Konten im Protokoll), eigene Fahrzeuge samt Dateien,
 * Wartungen (mit Fotos), Wartungsplan-Ausnahmen und Reparaturen (mit Ersatzteilen und Fotos) – außer an den Verein
 * übergebene –, Bezüge in Vereinsdaten (auch „ausgeführt von“/„erfasst von“ an Wartungen, „gemeldet von“/„zuständig“ an
 * Reparaturen, Anzeige „ehemaliges Mitglied“) und Personendaten im Änderungsprotokoll. Börse: nicht verkaufte Angebote
 * der Person samt Fotos; verkaufte bleiben ohne Person in der Abrechnung, Angebote mit storniertem Verkauf bleiben ohne
 * Person, Fotos, Beschreibung und Notiz (die Verkaufsposition verweist darauf). Name, E-Mail und Telefon in Freitexten
 * von Magazin und Börse (FREITEXTE) werden wie im Protokoll durch „ehemaliges Mitglied“ ersetzt.
 * „Kennwort vergessen“: Tokens und Anfragen per CASCADE mit dem Konto, Versuche über den E-Mail-Hash.
 * Datenbankteil in einer Transaktion, Dateien vom Webspace erst nach dem Commit.
 */
final class DsgvoLoeschung
{
    public const EIGENE_PERSON = 'Die eigene Person kann nicht nach DSGVO gelöscht werden. Bitte einen anderen Administrator darum bitten.';
    public const KEIN_ADMIN = 'Nur ein aktiver Administrator darf Personen nach DSGVO löschen.';
    public const LETZTER_ADMIN = 'Diese Person hat den letzten aktiven Administrator-Zugang. Es muss mindestens ein aktiver Administrator bleiben.';
    public const NACHWEIS = 'nach DSGVO gelöscht';

    /**
     * Freitexte, in denen Name, E-Mail oder Telefon der Person stehen können (z. B. „Spende von …“, Lagerort „bei …“),
     * mit Spaltenlänge (null = TEXT) und dem Objekt, an dem der Nachweis der Bereinigung ins Protokoll geht (Typ und
     * Spalte mit dessen ID). Buchungen sind sonst unveränderlich – die DSGVO-Löschung ist die einzige Ausnahme.
     */
    private const FREITEXTE = [
        ['magazin_buchung', 'freitext', 200, 'magazin_artikel', 'artikel_id'],
        ['magazin_buchung', 'lagerort_neu', 100, 'magazin_artikel', 'artikel_id'],
        ['magazin_artikel', 'lagerort', 100, 'magazin_artikel', 'id'],
        ['magazin_artikel', 'notiz', null, 'magazin_artikel', 'id'],
        ['magazin_sammlung', 'name', 150, 'magazin_sammlung', 'id'],
        ['magazin_sammlung', 'notiz', null, 'magazin_sammlung', 'id'],
        ['boerse_angebot', 'beschreibung', null, 'boerse_angebot', 'id'],
        ['boerse_angebot', 'notiz', null, 'boerse_angebot', 'id'],
        ['boerse_verkauf', 'storno_grund', 300, 'boerse', 'boerse_id'],
        ['boerse', 'ort', 150, 'boerse', 'id'],
    ];
    /** Protokolleintrag an jedem Objekt mit bereinigtem Freitext (ohne alte Werte). */
    public const FREITEXT_NACHWEIS = 'Freitexte nach DSGVO bereinigt';

    public function __construct(
        private \PDO $pdo,
        private DokumentRepository $dokumente,
        private Aenderungsprotokoll $protokoll,
        private FahrzeugRepository $fahrzeuge,
        private Auftraege $auftraege,
        private ?Uebertragung $uebertragung = null,
        private ?WartungRepository $wartungen = null,
        private ?AusnahmeRepository $ausnahmen = null,
        private ?ReparaturRepository $reparaturen = null,
        private ?AngebotRepository $angebote = null,
    ) {
        $this->wartungen ??= new WartungRepository($pdo);
        $this->ausnahmen ??= new AusnahmeRepository($pdo);
        $this->reparaturen ??= new ReparaturRepository($pdo);
        $this->angebote ??= new AngebotRepository($pdo);
    }

    /**
     * Was beim Löschen passieren würde; ändert nichts.
     *
     * `onedrive_konto_der_person`: die OneDrive-Verbindung läuft über ein Microsoft-Konto mit einer E-Mail der Person.
     *
     * `wartungen_in_vereinsdaten`: Wartungen an anderen Objekten (Vereins- und fremde Fahrzeuge, Anlagen), die die Person
     * ausgeführt oder eingetragen hat – sie bleiben, der Bezug wird „ehemaliges Mitglied“.
     *
     * `reparaturen_in_vereinsdaten`: ebenso Reparaturen an anderen Objekten, die die Person gemeldet hat (über ihr Konto)
     * oder für die sie zuständig ist.
     *
     * `angebote_geloescht`: nicht verkaufte Börsenangebote ohne Verkaufsposition (werden gelöscht); `angebote_verkauft`:
     * verkaufte (bleiben ohne Namen); `angebote_storniert`: nicht verkaufte mit storniertem Verkauf (bleiben ohne Person,
     * Fotos, Beschreibung und Notiz); `freitexte`: Freitexte in Magazin und Börse mit Name, E-Mail oder Telefon;
     * `anfragen`: öffentliche Börsenanfragen mit E-Mail oder Telefon der Person (werden gelöscht).
     *
     * @return array{person: array<string,mixed>, benutzer: ?array<string,mixed>, fahrzeuge: list<array{id:int, inventarnummer:string, bezeichnung:string, dokumente:int, rechnungen:int, wartungen:int, reparaturen:int}>, wartungen_in_vereinsdaten:int, reparaturen_in_vereinsdaten:int, protokolleintraege:int, verweigert:?string, onedrive_konto_der_person:bool, angebote_geloescht:int, angebote_verkauft:int, angebote_storniert:int, freitexte:int, anfragen:int}
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
                'wartungen' => count($this->wartungIds((int) $fz['id'])),
                'reparaturen' => count($this->reparaturIds((int) $fz['id'])),
            ];
        }
        $k = $this->kennungen($personId, $person, $benutzer);
        $angebote = $this->angeboteDerPerson($personId, false);
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
            'wartungen_in_vereinsdaten' => $this->wartungenInVereinsdaten($personId, $benutzer === null ? null : (int) $benutzer['id']),
            'reparaturen_in_vereinsdaten' => $this->reparaturenInVereinsdaten($personId, $benutzer === null ? null : (int) $benutzer['id']),
            'protokolleintraege' => $this->protokoll->zaehleBetroffene($k['begriffe'], $k['konten'], $k['objekte']),
            'verweigert' => $verweigert,
            'onedrive_konto_der_person' => $onedriveKonto !== '' && in_array($onedriveKonto, $mails, true),
            'angebote_geloescht' => count(array_filter($angebote, fn(array $a): bool => self::angebotWirdGeloescht($a))),
            'angebote_verkauft' => count(array_filter($angebote, fn(array $a): bool => $a['status'] === 'verkauft')),
            'angebote_storniert' => count(array_filter($angebote, fn(array $a): bool => $a['status'] !== 'verkauft' && $a['positionen'] > 0)),
            'freitexte' => $this->freitexte($k['begriffe'], null),
            'anfragen' => count($this->anfragenDerPerson($k['mails'], $k['telefone'], false)),
        ];
    }

    /**
     * Öffentliche Börsenanfragen, deren E-Mail oder Telefonnummer (nur Ziffern verglichen) zur Person gehört – z. B. wenn
     * ein Mitglied selbst eine Sammlung über die Webseite angeboten hat. Mit $sperren je Treffer gesperrt (aktueller
     * Stand). Vor Migration 019 keine.
     *
     * @param list<string> $mails
     * @param list<string> $telefone
     * @return list<int>
     */
    private function anfragenDerPerson(array $mails, array $telefone, bool $sperren): array
    {
        $mails = array_map('mb_strtolower', array_map('trim', $mails));
        $ziffern = array_values(array_filter(array_map(fn(string $t): string => (string) preg_replace('/\D/', '', $t), $telefone), fn(string $t): bool => strlen($t) >= 6));
        if ($mails === [] && $ziffern === []) {
            return [];
        }
        try {
            $zeilen = $this->pdo->query('SELECT id, email, telefon FROM boerse_anfrage WHERE email IS NOT NULL OR telefon IS NOT NULL ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
                return [];
            }
            throw $e;
        }
        $sperre = $this->pdo->prepare('SELECT id FROM boerse_anfrage WHERE id = ? FOR UPDATE');
        $ids = [];
        foreach ($zeilen as $z) {
            $mail = mb_strtolower(trim((string) $z['email']));
            $tel = (string) preg_replace('/\D/', '', (string) $z['telefon']);
            if (($mail !== '' && in_array($mail, $mails, true)) || ($tel !== '' && in_array($tel, $ziffern, true))) {
                if ($sperren) {
                    $sperre->execute([(int) $z['id']]);
                    if ($sperre->fetchColumn() === false) {
                        continue; // inzwischen gelöscht (z. B. 12-Monats-Löschung)
                    }
                }
                $ids[] = (int) $z['id'];
            }
        }
        return $ids;
    }

    /** @param array{id:int, status:string, positionen:int} $a */
    private static function angebotWirdGeloescht(array $a): bool
    {
        return $a['status'] !== 'verkauft' && $a['positionen'] === 0;
    }

    /**
     * Börsenangebote der Person; vor Migration 017 keine. Mit $sperren nach id aufsteigend gesperrt.
     *
     * @return list<array{id:int, status:string, positionen:int}>
     */
    private function angeboteDerPerson(int $personId, bool $sperren): array
    {
        try {
            return $this->angebote->fuerPerson($personId, $sperren);
        } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Zählt bzw. ersetzt Name, E-Mail und Telefon der Person in den Freitexten von Magazin und Börse (Vorauswahl per
     * LIKE, Ersetzung ganzer Fundstellen wie im Protokoll, gekürzt auf die Spaltenlänge). Beim Ersetzen wird jede
     * gefundene Zeile vorher gesperrt neu gelesen (FOR UPDATE: aktueller Stand, keine gleichzeitige Änderung wird mit
     * einem alten Schnappschuss überschrieben), und je Objekt entsteht ein Protokolleintrag ohne alte Werte. Tabellen,
     * die es vor 016/017 noch nicht gibt, werden übersprungen.
     *
     * @param list<string> $begriffe
     * @param ?int $ausfuehrender null = nur zählen (Vorschau), sonst ersetzen und protokollieren
     * @return int Anzahl betroffener Felder
     */
    private function freitexte(array $begriffe, ?int $ausfuehrender): int
    {
        $ersetzen = $ausfuehrender !== null;
        $nachweise = [];
        $liste = Aenderungsprotokoll::suchbegriffe($begriffe);
        if ($liste === []) {
            return 0;
        }
        $werte = array_map(fn(string $b): string => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $b) . '%', $liste);
        $anzahl = 0;
        foreach (self::FREITEXTE as [$tabelle, $spalte, $laenge, $objektTyp, $objektSpalte]) {
            try {
                $st = $this->pdo->prepare(
                    "SELECT id, $objektSpalte AS objekt_id, $spalte AS text FROM $tabelle WHERE $spalte IS NOT NULL AND ("
                    . implode(' OR ', array_fill(0, count($werte), "$spalte LIKE ? ESCAPE '!'")) . ') ORDER BY id'
                );
                $st->execute($werte);
                $zeilen = $st->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                if (in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
                    continue;
                }
                throw $e;
            }
            $aendern = $this->pdo->prepare("UPDATE $tabelle SET $spalte = ? WHERE id = ?");
            $lies = $this->pdo->prepare("SELECT $spalte FROM $tabelle WHERE id = ? FOR UPDATE");
            foreach ($zeilen as $z) {
                $text = (string) $z['text'];
                if ($ersetzen) {
                    $lies->execute([(int) $z['id']]);
                    $aktuell = $lies->fetchColumn();
                    if ($aktuell === false || $aktuell === null) {
                        continue;
                    }
                    $text = (string) $aktuell;
                }
                $neu = Aenderungsprotokoll::ersetzeBegriffe($text, $begriffe);
                if ($neu === $text) {
                    continue;
                }
                $anzahl++;
                if ($ersetzen) {
                    $aendern->execute([$laenge === null ? $neu : mb_substr($neu, 0, $laenge), (int) $z['id']]);
                    $nachweise[$objektTyp . ':' . (int) $z['objekt_id']] = [$objektTyp, (int) $z['objekt_id']];
                }
            }
        }
        foreach ($nachweise as [$typ, $id]) {
            $this->protokoll->schreibe($ausfuehrender, $typ, $id, 'geaendert', ['dsgvo' => ['alt' => null, 'neu' => self::FREITEXT_NACHWEIS]]);
        }
        return $anzahl;
    }

    /** Wartungen am Fahrzeug; vor Migration 013 keine. @return list<int> */
    private function wartungIds(int $fahrzeugId): array
    {
        try {
            return $this->wartungen->idsFuerObjekt('fahrzeug', $fahrzeugId);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Reparaturen am Fahrzeug; vor Migration 014 keine. Mit $sperren als sperrende Lesung (in loesche(): aktueller Stand,
     * auch eine eben von jemand anderem festgeschriebene Meldung).
     *
     * @return list<int>
     */
    private function reparaturIds(int $fahrzeugId, bool $sperren = false): array
    {
        try {
            return $this->reparaturen->idsFuerObjekt('fahrzeug', $fahrzeugId, $sperren);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return [];
            }
            throw $e;
        }
    }

    private function reparaturenInVereinsdaten(int $personId, ?int $benutzerId): int
    {
        try {
            $st = $this->pdo->prepare(
                'SELECT COUNT(*) FROM reparatur r WHERE (r.zustaendig_person_id = ? OR r.gemeldet_von_benutzer_id = ?) '
                . "AND NOT (r.objekt_typ = 'fahrzeug' AND r.objekt_id IN (SELECT f.id FROM fahrzeug f WHERE f.eigentuemer_person_id = ?))"
            );
            $st->execute([$personId, $benutzerId ?? 0, $personId]);
            return (int) $st->fetchColumn();
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return 0;
            }
            throw $e;
        }
    }

    private function wartungenInVereinsdaten(int $personId, ?int $benutzerId): int
    {
        try {
            $st = $this->pdo->prepare(
                'SELECT COUNT(*) FROM wartung w WHERE (w.ausgefuehrt_von_person_id = ? OR w.erfasst_von_benutzer_id = ?) '
                . "AND NOT (w.objekt_typ = 'fahrzeug' AND w.objekt_id IN (SELECT f.id FROM fahrzeug f WHERE f.eigentuemer_person_id = ?))"
            );
            $st->execute([$personId, $benutzerId ?? 0, $personId]);
            return (int) $st->fetchColumn();
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return 0;
            }
            throw $e;
        }
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
            // 0. ZUERST die Objekte sperren (vor jeder anderen Lesung), dann Person und Konto, zuletzt die Reparaturzeilen –
            //    dieselbe Reihenfolge wie beim Speichern einer Reparatur (ReparaturDienst::inTransaktion: Objekt, dann
            //    zuständige Person, dann Reparatur). So wartet die Löschung auf ein gleichzeitiges Speichern, ohne vorher
            //    etwas zu halten, worauf dieses wartet, und sieht danach dessen Meldungen.
            //    a) Die eigenen Fahrzeuge; die sperrende Lesung über eigentuemer_person_id hält auch neu zugeordnete
            //       Fahrzeuge fern.
            //    b) Vereinsobjekte, die die Löschung später über Fremdschlüssel ändert (ON DELETE SET NULL): Fahrzeuge, die
            //       das Konto angelegt hat, und Fahrzeuge bzw. Anlagen mit Reparaturen, für die die Person zuständig ist oder
            //       die das Konto gemeldet hat. Sonst hielte ein Speichern dort das Objekt und wartete auf die Person,
            //       während die Löschung die Person hält und beim Leeren am Objekt wartet (Deadlock, 2c-Review M1).
            //    Ausgeschlossen ist ein Deadlock damit nur in diesen Fällen. Ein Rest bleibt (z. B. eine Reparatur, die
            //    erst nach der Lesung in b) der Person zugewiesen wird, oder Sperren im Änderungsprotokoll): Dann bricht
            //    MySQL bzw. MariaDB eine der beiden Transaktionen mit Fehler 1213 ab, nichts ist halb gelöscht, und die
            //    DSGVO-Seite meldet „Bitte erneut versuchen“ (PersonController::DSGVO_ERNEUT, Test in DsgvoHttpTest).
            $eigene = array_map(fn(array $fz): int => (int) $fz['id'], $this->eigeneFahrzeuge($personId, true));
            $this->sperreVereinsobjekte($personId);
            //    c) Die Börsenangebote der Person, nach id aufsteigend (Reihenfolge Fahrzeuge → Angebote → Person). Eine
            //       Kasse sperrt zwar zuerst die Börse, die Löschung braucht die Börse aber nicht; ein Rest-Deadlock
            //       (Kasse hält schon die Person per Fremdschlüssel und wartet auf ein zweites Angebot) endet mit 1213
            //       und „Bitte erneut versuchen“.
            $angebote = $this->angeboteDerPerson($personId, true);

            // 1. Verweigerungsgründe – Admin-Zugänge gesperrt, damit nicht gleichzeitig der letzte Admin verschwindet;
            //    entschieden wird mit genau den gesperrten IDs (kein späterer Snapshot-Read).
            $admins = array_map('intval', $this->pdo->query("SELECT id FROM benutzer WHERE rolle = 'admin' AND aktiv = 1 FOR UPDATE")->fetchAll(\PDO::FETCH_COLUMN));
            $person = $this->ladePerson($personId, true);
            $benutzer = $this->ladeBenutzer($personId, true);
            $grund = $this->verweigerung($personId, $benutzer, $ausfuehrenderBenutzerId, $admins);
            if ($grund !== null) {
                throw new DomainFehler($grund);
            }
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
            //    Vorher Wartungen (Fotos wie Dokumente, wartung_art per CASCADE) und Ausnahmen des Fahrzeugs.
            //    Wartungen an übergebenen und Vereinsfahrzeugen bleiben; „ausgeführt von“ (Person) und „erfasst von“
            //    (Konto) leeren die Fremdschlüssel ON DELETE SET NULL in den Schritten 4 und 6.
            //    Ebenso Reparaturen: Fotos wie Dokumente, Ersatzteile und Gesehen-Zeilen per CASCADE; an anderen Objekten
            //    leeren sich „gemeldet von“ (Konto, Schritt 4) und „zuständig“ (Person, Schritt 6) per ON DELETE SET NULL.
            foreach (array_diff($eigene, $uebergeben) as $fzId) {
                foreach ($this->reparaturIds($fzId, true) as $reparaturId) {
                    foreach ($this->dokumenteNurHier($reparaturId, 'reparatur') as $dokId) {
                        $pfad = $this->loescheDokument($dokId);
                        if ($pfad !== null) {
                            $pfade[$dokId] = $pfad;
                        }
                    }
                    $this->pdo->prepare("DELETE FROM dokument_zuordnung WHERE objekt_typ = 'reparatur' AND objekt_id = ?")->execute([$reparaturId]);
                    $this->reparaturen->loeschen($reparaturId);
                    $eigeneObjekte[] = ['reparatur', $reparaturId];
                }
                foreach ($this->wartungIds($fzId) as $wartungId) {
                    foreach ($this->dokumenteNurHier($wartungId, 'wartung') as $dokId) {
                        $pfad = $this->loescheDokument($dokId);
                        if ($pfad !== null) {
                            $pfade[$dokId] = $pfad;
                        }
                    }
                    $this->pdo->prepare("DELETE FROM dokument_zuordnung WHERE objekt_typ = 'wartung' AND objekt_id = ?")->execute([$wartungId]);
                    $this->wartungen->loeschen($wartungId);
                    $eigeneObjekte[] = ['wartung', $wartungId];
                }
                $this->loescheAusnahmen($fzId);
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

            // 3b. Börsenangebote: ohne Verkaufsposition samt nur ihnen zugeordneter Fotos löschen (eine Kaufanfrage dazu
            //     verliert per ON DELETE SET NULL nur den Verweis); mit storniertem Verkauf (RESTRICT der Position)
            //     ohne Personenbezug behalten; verkaufte bleiben – person_id an Angebot, Position und Auszahlung leert
            //     sich in Schritt 6 per ON DELETE SET NULL („ehemaliges Mitglied“, Beträge unverändert); auch sie werden
            //     nicht öffentlich und verlieren Beschreibung, Notiz und Fotos (die Abrechnung braucht nur Nummer,
            //     Bezeichnung und Preis). Angebote ohne Person lassen sich danach weder anbieten noch verkaufen
            //     (AngebotDienst::vonGeloeschtemMitglied).
            foreach ($angebote as $a) {
                foreach ($this->dokumenteNurHier($a['id'], 'boerse_angebot') as $dokId) {
                    $pfad = $this->loescheDokument($dokId);
                    if ($pfad !== null) {
                        $pfade[$dokId] = $pfad;
                    }
                }
                $this->pdo->prepare("DELETE FROM dokument_zuordnung WHERE objekt_typ = 'boerse_angebot' AND objekt_id = ?")->execute([$a['id']]);
                if (self::angebotWirdGeloescht($a)) {
                    $this->angebote->loesche($a['id']);
                    $eigeneObjekte[] = ['boerse_angebot', $a['id']];
                } else {
                    $this->angebote->anonymisiere($a['id']);
                }
            }
            // 3c. Öffentliche Börsenanfragen mit E-Mail oder Telefon der Person samt nur ihnen zugeordneter Fotos.
            foreach ($this->anfragenDerPerson($k['mails'], $k['telefone'], true) as $anfrageId) {
                foreach ($this->dokumenteNurHier($anfrageId, 'boerse_anfrage') as $dokId) {
                    $pfad = $this->loescheDokument($dokId);
                    if ($pfad !== null) {
                        $pfade[$dokId] = $pfad;
                    }
                }
                $this->pdo->prepare("DELETE FROM dokument_zuordnung WHERE objekt_typ = 'boerse_anfrage' AND objekt_id = ?")->execute([$anfrageId]);
                $this->pdo->prepare('DELETE FROM boerse_anfrage WHERE id = ?')->execute([$anfrageId]);
                $eigeneObjekte[] = ['boerse_anfrage', $anfrageId];
            }
            // 3d. Freitexte in Magazin und Börse („Spende von …“, Lagerorte, Notizen, Ort, Storno-Grund).
            $this->freitexte($k['begriffe'], $ausfuehrenderBenutzerId);

            // 4. Anmelde- und Kennwort-Versuche aller bekannten E-Mails (Person, Konto, frühere) und das Benutzerkonto.
            //    Fremdschlüssel: dokument.hochgeladen_von und fahrzeug.angelegt_von_benutzer_id werden NULL.
            if ($k['mails'] !== []) {
                $this->pdo->prepare('DELETE FROM login_versuch WHERE email IN (' . implode(', ', array_fill(0, count($k['mails']), '?')) . ')')
                    ->execute($k['mails']);
                // „Kennwort vergessen“-Versuche stehen nur gehasht (HMAC mit dem Salz der Instanz) da; ohne Salz gab es
                // noch keine. Tokens und Anfragen gehen per CASCADE mit dem Konto.
                $st = $this->pdo->prepare('SELECT wert FROM einstellung WHERE schluessel = ?');
                $st->execute([KennwortVergessen::SALZ]);
                $salz = (string) $st->fetchColumn();
                if ($salz !== '') {
                    $hashes = array_values(array_unique(array_map(fn(string $m): string => KennwortVergessen::emailHash($m, $salz), $k['mails'])));
                    $this->pdo->prepare('DELETE FROM kennwort_versuch WHERE email_hash IN (' . implode(', ', array_fill(0, count($hashes), '?')) . ')')
                        ->execute($hashes);
                }
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

    /** Ausnahmen im Wartungsplan des Fahrzeugs; vor Migration 013 gibt es keine. */
    private function loescheAusnahmen(int $fahrzeugId): void
    {
        try {
            $this->ausnahmen->loescheFuerObjekt('fahrzeug', $fahrzeugId);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '42S02') {
                throw $e;
            }
        }
    }

    /**
     * Dokumente, die nur diesem Objekt (Fahrzeug, Wartung bzw. Reparatur) zugeordnet sind – mit sperrenden Reads, damit eine
     * gleichzeitig angelegte Zuordnung weder übersehen noch ohne ihr Dokument gelöscht wird.
     *
     * @return list<int>
     */
    private function dokumenteNurHier(int $objektId, string $objektTyp = 'fahrzeug'): array
    {
        $st = $this->pdo->prepare('SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = ? AND objekt_id = ? ORDER BY dokument_id FOR UPDATE');
        $st->execute([$objektTyp, $objektId]);
        $andere = $this->pdo->prepare('SELECT COUNT(*) FROM dokument_zuordnung WHERE dokument_id = ? AND NOT (objekt_typ = ? AND objekt_id = ?) FOR UPDATE');
        $ids = [];
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $dokId) {
            $andere->execute([(int) $dokId, $objektTyp, $objektId]);
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
     * @return array{begriffe:list<string>, mails:list<string>, konten:list<int>, objekte:list<array{0:string,1:int}>, telefone:list<string>}
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
        return ['begriffe' => $begriffe, 'mails' => $mails, 'konten' => $konten, 'objekte' => $objekte, 'telefone' => array_values($werte['telefon'])];
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

    /**
     * Schritt 0 b) in loesche(): sperrt (FOR UPDATE, nach ID) Fahrzeuge, die das Konto der Person angelegt hat, und die
     * Fahrzeuge und Anlagen der Reparaturen, für die die Person zuständig ist oder die das Konto gemeldet hat. Die
     * Reparaturen selbst werden hier nur gelesen, nicht gesperrt (ihre Sperre käme sonst vor der des Objekts).
     */
    private function sperreVereinsobjekte(int $personId): void
    {
        $st = $this->pdo->prepare('SELECT id FROM benutzer WHERE person_id = ?');
        $st->execute([$personId]);
        $konten = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        if ($konten !== []) {
            $platz = implode(', ', array_fill(0, count($konten), '?'));
            $this->pdo->prepare("SELECT id FROM fahrzeug WHERE angelegt_von_benutzer_id IN ($platz) ORDER BY id FOR UPDATE")->execute($konten);
        }
        try {
            $st = $this->pdo->prepare(
                'SELECT DISTINCT objekt_typ, objekt_id FROM reparatur WHERE zustaendig_person_id = ?'
                . ($konten === [] ? '' : ' OR gemeldet_von_benutzer_id IN (' . implode(', ', array_fill(0, count($konten), '?')) . ')')
            );
            $st->execute([$personId, ...$konten]);
            $objekte = $st->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return; // vor Migration 014 gibt es keine Reparaturen
            }
            throw $e;
        }
        foreach (['fahrzeug', 'anlage'] as $typ) {
            $ids = array_values(array_unique(array_map(
                fn(array $o): int => (int) $o['objekt_id'],
                array_filter($objekte, fn(array $o): bool => $o['objekt_typ'] === $typ)
            )));
            sort($ids);
            if ($ids !== []) {
                $this->pdo->prepare("SELECT id FROM $typ WHERE id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE')
                    ->execute($ids);
            }
        }
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
