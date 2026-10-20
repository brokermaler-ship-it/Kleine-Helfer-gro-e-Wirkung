<?php

declare(strict_types=1);

namespace Emc\Personen;

use Emc\Core\DomainFehler;
use Emc\Dateien\DokumentRepository;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\OneDrive\Uebertragung;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Löscht eine Person nach DSGVO vollständig: Konto, eigene Fahrzeuge samt Dateien (außer an den Verein übergebene),
 * Bezüge in Vereinsdaten und Personendaten im Änderungsprotokoll. Datenbankteil in einer Transaktion,
 * Dateien vom Webspace erst nach dem Commit.
 */
final class DsgvoLoeschung
{
    public const EIGENE_PERSON = 'Die eigene Person kann nicht nach DSGVO gelöscht werden. Bitte einen anderen Administrator darum bitten.';
    public const LETZTER_ADMIN = 'Diese Person hat den letzten aktiven Administrator-Zugang. Es muss mindestens ein aktiver Administrator bleiben.';
    public const NACHWEIS = 'nach DSGVO gelöscht';

    public function __construct(
        private \PDO $pdo,
        private DokumentRepository $dokumente,
        private Aenderungsprotokoll $protokoll,
        private FahrzeugRepository $fahrzeuge,
        private ?Uebertragung $uebertragung = null,
    ) {
    }

    /**
     * Was beim Löschen passieren würde; ändert nichts.
     *
     * @return array{person: array<string,mixed>, benutzer: ?array<string,mixed>, fahrzeuge: list<array{id:int, inventarnummer:string, bezeichnung:string, dokumente:int}>, protokolleintraege:int, verweigert:?string}
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
            ];
        }
        $benutzerId = $benutzer === null ? null : (int) $benutzer['id'];
        if ($benutzer !== null) {
            unset($benutzer['passwort_hash']);
        }
        return [
            'person' => $person,
            'benutzer' => $benutzer,
            'fahrzeuge' => $fahrzeuge,
            'protokolleintraege' => $this->protokoll->zaehleBetroffene(
                self::begriffe($person, $benutzer),
                $benutzerId,
                self::eigeneObjekte($personId, $benutzerId)
            ),
            'verweigert' => $this->verweigerung($personId, $benutzer, $ausfuehrenderBenutzerId),
        ];
    }

    /**
     * @param list<int> $anVereinUebergeben IDs eigener Fahrzeuge, die erhalten bleiben und dem Verein gehören sollen
     * @throws DomainFehler bei Verweigerung, unbekannter Person oder fremden Fahrzeug-IDs
     */
    public function loesche(int $personId, array $anVereinUebergeben, int $ausfuehrenderBenutzerId): void
    {
        $uebergeben = array_values(array_unique(array_map('intval', $anVereinUebergeben)));
        $pfade = [];
        $this->pdo->beginTransaction();
        try {
            // 1. Verweigerungsgründe – Admin-Zugänge gesperrt, damit nicht gleichzeitig der letzte Admin verschwindet.
            $this->pdo->query("SELECT id FROM benutzer WHERE rolle = 'admin' AND aktiv = 1 FOR UPDATE")->fetchAll();
            $person = $this->ladePerson($personId, true);
            $benutzer = $this->ladeBenutzer($personId, true);
            $grund = $this->verweigerung($personId, $benutzer, $ausfuehrenderBenutzerId);
            if ($grund !== null) {
                throw new DomainFehler($grund);
            }
            $eigene = array_map(fn(array $fz): int => (int) $fz['id'], $this->eigeneFahrzeuge($personId, true));
            if (array_diff($uebergeben, $eigene) !== []) {
                throw new DomainFehler('Es können nur Fahrzeuge dieser Person an den Verein übergeben werden.');
            }
            $benutzerId = $benutzer === null ? null : (int) $benutzer['id'];
            $eigeneObjekte = self::eigeneObjekte($personId, $benutzerId);

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
                foreach ($this->dokumente->idsFuerObjekt('fahrzeug', $fzId) as $dokId) {
                    $pfad = $this->dokumente->loescheDatensatz($dokId);
                    if ($pfad !== null) {
                        $pfade[] = $pfad;
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

            // 4. Benutzerkonto und Anmeldeversuche (Konto- und Personen-E-Mail).
            //    Fremdschlüssel: dokument.hochgeladen_von und fahrzeug.angelegt_von_benutzer_id werden NULL.
            $mails = array_values(array_filter([
                trim((string) ($benutzer['email'] ?? '')),
                trim((string) ($person['email'] ?? '')),
            ], fn(string $m): bool => $m !== ''));
            if ($mails !== []) {
                $this->pdo->prepare('DELETE FROM login_versuch WHERE email IN (' . implode(', ', array_fill(0, count($mails), '?')) . ')')
                    ->execute($mails);
            }
            if ($benutzerId !== null) {
                $this->pdo->prepare('DELETE FROM benutzer WHERE id = ?')->execute([$benutzerId]);
            }

            // 5. Änderungsprotokoll bereinigen.
            $this->protokoll->anonymisiere(self::begriffe($person, $benutzer), $benutzerId, $eigeneObjekte);

            // 6. Person löschen und Nachweis ohne personenbezogene Daten.
            $this->pdo->prepare('DELETE FROM person WHERE id = ?')->execute([$personId]);
            $this->protokoll->schreibe($ausfuehrenderBenutzerId, 'person', $personId, 'geloescht', [
                'dsgvo' => ['alt' => null, 'neu' => self::NACHWEIS],
            ]);

            $this->pdo->commit();
        } catch (\Throwable $t) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $t;
        }

        // 7. Erst nach dem Commit: Dateien vom Webspace, dann OneDrive-Lösch-Aufträge gleich nebenbei ausführen.
        foreach ($pfade as $pfad) {
            $this->dokumente->entferneDateien($pfad);
        }
        $this->uebertragung?->nebenbei();
    }

    /** @param ?array<string,mixed> $benutzer */
    private function verweigerung(int $personId, ?array $benutzer, int $ausfuehrenderBenutzerId): ?string
    {
        $st = $this->pdo->prepare('SELECT person_id FROM benutzer WHERE id = ?');
        $st->execute([$ausfuehrenderBenutzerId]);
        $eigenePerson = $st->fetchColumn();
        if ($eigenePerson !== false && (int) $eigenePerson === $personId) {
            return self::EIGENE_PERSON;
        }
        if ($benutzer !== null && $benutzer['rolle'] === 'admin' && (int) $benutzer['aktiv'] === 1) {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM benutzer WHERE id <> ? AND rolle = 'admin' AND aktiv = 1");
            $st->execute([(int) $benutzer['id']]);
            if ((int) $st->fetchColumn() === 0) {
                return self::LETZTER_ADMIN;
            }
        }
        return null;
    }

    /**
     * Namensbegriffe nur, wenn Vor- und Nachname gefüllt sind (sonst träfe der Nachname allein z. B. Angehörige).
     *
     * @param array<string,mixed> $person
     * @param ?array<string,mixed> $benutzer
     * @return list<string>
     */
    private static function begriffe(array $person, ?array $benutzer): array
    {
        $vorname = trim((string) ($person['vorname'] ?? ''));
        $nachname = trim((string) ($person['nachname'] ?? ''));
        $begriffe = [];
        if ($vorname !== '' && $nachname !== '') {
            $begriffe[] = $vorname . ' ' . $nachname;
            $begriffe[] = $nachname . ', ' . $vorname;
            $begriffe[] = $nachname . ' ' . $vorname;
        }
        foreach ([$person['email'] ?? null, $benutzer['email'] ?? null, $person['telefon'] ?? null] as $wert) {
            $wert = trim((string) ($wert ?? ''));
            if ($wert !== '') {
                $begriffe[] = $wert;
            }
        }
        return $begriffe;
    }

    /** @return list<array{0:string,1:int}> */
    private static function eigeneObjekte(int $personId, ?int $benutzerId): array
    {
        $objekte = [['person', $personId]];
        if ($benutzerId !== null) {
            $objekte[] = ['benutzer', $benutzerId];
        }
        return $objekte;
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
