<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

use Emc\Core\DomainFehler;
use Emc\Core\Transaktion;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Stammdaten\SpurweiteRepository;

/**
 * Einmaliger Import einer Fahrzeugliste (FahrzeugCsvImport): prüfen (Vorschau) und in einer Transaktion anlegen.
 * Jede Datei (SHA-256 über den Rohinhalt) nur einmal: Tabelle fahrzeug_import mit eindeutigem Hash (Migration 020).
 */
final class FahrzeugImport
{
    public const KORRIGIEREN = 'Bitte Datei korrigieren und neu hochladen.';

    public function __construct(
        private \PDO $pdo,
        private FahrzeugRepository $fahrzeuge,
        private SpurweiteRepository $spurweiten,
        private Aenderungsprotokoll $protokoll,
    ) {
    }

    public static function hash(string $inhalt): string
    {
        return hash('sha256', $inhalt);
    }

    /** Prüft die Datei gegen die vorhandenen Spurweiten und Fahrzeuge und gegen frühere Importe. */
    public function pruefe(string $inhalt, string $dateiname = 'import.csv'): FahrzeugImportErgebnis
    {
        $spur = [];
        foreach ($this->spurweiten->alle() as $s) {
            $spur[(string) $s['name']] = (int) $s['id'];
        }
        $ergebnis = FahrzeugCsvImport::parse($inhalt, $spur, $this->fahrzeuge->alleBezeichnungen(), FahrzeugCsvImport::dateiname($dateiname));
        $frueher = $this->frueherImport(self::hash($inhalt));
        if ($frueher !== null) {
            $ergebnis = $ergebnis->alsBereitsImportiert(self::schonImportiert($frueher));
        }
        return $ergebnis;
    }

    /**
     * Legt alle fehlerfreien Fahrzeuge der Datei in EINER Transaktion an (neue Inventarnummern, ungeprüft, Eigentum
     * Verein) und trägt die Datei in fahrzeug_import ein – zuerst, damit ein gleichzeitiger zweiter Import an der
     * eindeutigen Prüfsumme scheitert (und wartet, bis dieser fertig ist).
     *
     * @return int Anzahl angelegter Fahrzeuge
     * @throws DomainFehler wenn die Datei Fehler hat oder schon importiert wurde (nichts angelegt)
     */
    public function importiere(string $inhalt, string $dateiname, ?int $benutzerId, \DateTimeImmutable $jetzt): int
    {
        $name = FahrzeugCsvImport::dateiname($dateiname);
        $ergebnis = $this->pruefe($inhalt, $name);
        if ($ergebnis->bereitsImportiert !== null) {
            throw new DomainFehler($ergebnis->bereitsImportiert);
        }
        if (!$ergebnis->istImportierbar()) {
            throw new DomainFehler(($ergebnis->fehler === [] ? '' : implode(' ', $ergebnis->fehler) . ' ') . self::KORRIGIEREN);
        }
        $zeilen = $ergebnis->anzulegende();
        $hash = self::hash($inhalt);
        try {
            return $this->legeAn($zeilen, $hash, $name, $benutzerId, $jetzt);
        } catch (DomainFehler $e) {
            if ($e->getMessage() === Transaktion::SPERRKONFLIKT) {
                // Die Vorschau ist mit dem Bestätigen verbraucht: neu hochladen.
                throw new DomainFehler(Transaktion::SPERRKONFLIKT . ' Bitte die Datei erneut hochladen.', 0, $e);
            }
            throw $e;
        }
    }

    /** @param list<array<string,mixed>> $zeilen */
    private function legeAn(array $zeilen, string $hash, string $name, ?int $benutzerId, \DateTimeImmutable $jetzt): int
    {
        return Transaktion::readCommitted($this->pdo, function () use ($zeilen, $hash, $name, $benutzerId, $jetzt): int {
            try {
                $this->pdo->prepare('INSERT INTO fahrzeug_import (datei_sha256, dateiname, anzahl, benutzer_id, erstellt_am) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$hash, $name, count($zeilen), $benutzerId, $jetzt->format('Y-m-d H:i:s')]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_fahrzeug_import_sha256')) {
                    $frueher = $this->frueherImport($hash);
                    throw new DomainFehler($frueher === null ? 'Diese Datei wurde bereits importiert.' : self::schonImportiert($frueher));
                }
                throw $e;
            }
            foreach ($zeilen as $z) {
                $daten = $z['daten'] + [
                    'eigentuemer_person_id' => null,
                    'status' => 'einsatzbereit',
                    'betriebsstunden' => 0.0,
                    'notiz' => FahrzeugCsvImport::notiz($z, $name, $jetzt),
                ];
                $id = $this->fahrzeuge->anlegen($daten, $benutzerId, true);
                $this->protokoll->schreibe($benutzerId, 'fahrzeug', $id, 'angelegt', Aenderungsprotokoll::diff([], $daten) + [
                    'import' => ['alt' => null, 'neu' => 'Import aus „' . $name . '“'],
                ]);
            }
            return count($zeilen);
        });
    }

    /** @return list<array{dateiname:string, anzahl:int, erstellt_am:string, anzeigename:?string}> die letzten Importe */
    public function letzte(int $anzahl = 10): array
    {
        $st = $this->pdo->query(
            "SELECT i.dateiname, i.anzahl, i.erstellt_am, TRIM(CONCAT(p.vorname, ' ', p.nachname)) AS anzeigename FROM fahrzeug_import i "
            . 'LEFT JOIN benutzer b ON b.id = i.benutzer_id LEFT JOIN person p ON p.id = b.person_id ORDER BY i.id DESC LIMIT ' . max(1, $anzahl)
        );
        return array_map(fn(array $z): array => [
            'dateiname' => (string) $z['dateiname'],
            'anzahl' => (int) $z['anzahl'],
            'erstellt_am' => (string) $z['erstellt_am'],
            'anzeigename' => $z['anzeigename'] === null ? null : (string) $z['anzeigename'],
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return ?array{erstellt_am:string, anzahl:int} */
    private function frueherImport(string $hash): ?array
    {
        $st = $this->pdo->prepare('SELECT erstellt_am, anzahl FROM fahrzeug_import WHERE datei_sha256 = ?');
        $st->execute([$hash]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : ['erstellt_am' => (string) $z['erstellt_am'], 'anzahl' => (int) $z['anzahl']];
    }

    /** @param array{erstellt_am:string, anzahl:int} $frueher */
    private static function schonImportiert(array $frueher): string
    {
        return sprintf(
            'Diese Datei wurde am %s bereits importiert (%d Fahrzeug%s).',
            date('d.m.Y', (int) strtotime($frueher['erstellt_am'])),
            $frueher['anzahl'],
            $frueher['anzahl'] === 1 ? '' : 'e'
        );
    }
}
