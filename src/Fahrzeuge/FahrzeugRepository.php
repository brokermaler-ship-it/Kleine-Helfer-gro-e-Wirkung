<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

final class FahrzeugRepository
{
    private const FELDER = [
        'bezeichnung', 'baureihe', 'bahnverwaltung', 'epoche', 'gattung', 'hersteller', 'artikelnummer',
        'spurweite_id', 'stromsystem', 'eigentuemer_person_id', 'status', 'betriebsstunden', 'notiz',
    ];
    /**
     * Neues ausgemustert_am zum künftigen Status (Platzhalter): bleibt beim Speichern eines schon ausgemusterten
     * Fahrzeugs erhalten, wird beim Wechsel auf „ausgemustert“ heute und sonst geleert. Muss in SET vor „status = ?“
     * stehen, weil MySQL die Zuweisungen von links nach rechts mit den neuen Werten auswertet.
     */
    private const AUSGEMUSTERT_AM = "CASE WHEN ? <> 'ausgemustert' THEN NULL WHEN status = 'ausgemustert' THEN COALESCE(ausgemustert_am, CURDATE()) ELSE CURDATE() END";
    private const STANDARD = ['gattung' => 'lok', 'stromsystem' => '2L', 'status' => 'einsatzbereit', 'betriebsstunden' => 0];

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function suche(FahrzeugFilter $f): array
    {
        $wo = [];
        $p = [];
        if ($f->text !== null) {
            $teile = [];
            foreach (['inventarnummer', 'bezeichnung', 'baureihe', 'hersteller', 'artikelnummer'] as $spalte) {
                $teile[] = "f.$spalte LIKE ? ESCAPE '|'";
                $p[] = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $f->text) . '%';
            }
            $wo[] = '(' . implode(' OR ', $teile) . ')';
        }
        if ($f->spurweiteId !== null) {
            $wo[] = 'f.spurweite_id = ?';
            $p[] = $f->spurweiteId;
        }
        if ($f->eigentuemer === 'verein') {
            $wo[] = 'f.eigentuemer_person_id IS NULL';
        } elseif ($f->eigentuemer === 'mitglied') {
            $wo[] = 'f.eigentuemer_person_id IS NOT NULL';
        }
        if ($f->personId !== null) {
            $wo[] = 'f.eigentuemer_person_id = ?';
            $p[] = $f->personId;
        }
        if ($f->status !== null) {
            $wo[] = 'f.status = ?';
            $p[] = $f->status;
        } elseif ($f->text === null) {
            // Ohne Statusfilter stehen ausgemusterte Fahrzeuge auf dem Abstellgleis; die Textsuche findet sie trotzdem.
            $wo[] = "f.status <> 'ausgemustert'";
        }
        if ($f->gattung !== null) {
            $wo[] = 'f.gattung = ?';
            $p[] = $f->gattung;
        }
        $sql = 'SELECT f.*, s.name AS spurweite_name, '
            . "TRIM(CONCAT(p.vorname, ' ', p.nachname)) AS eigentuemer_name, "
            . "(SELECT GROUP_CONCAT(d.adresse ORDER BY d.protokoll, d.adresse SEPARATOR ', ') FROM decoder d "
            . 'WHERE d.fahrzeug_id = f.id AND d.adresse IS NOT NULL) AS adressen '
            . 'FROM fahrzeug f JOIN spurweite s ON s.id = f.spurweite_id LEFT JOIN person p ON p.id = f.eigentuemer_person_id '
            . ($wo === [] ? '' : 'WHERE ' . implode(' AND ', $wo) . ' ')
            . 'ORDER BY f.inventarnummer, f.id';
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Nicht ausgemusterte Fahrzeuge, die einer Person gehören (für „Meine Fahrzeuge“). @return list<array<string,mixed>> */
    public function sucheEigene(int $personId): array
    {
        $filter = new FahrzeugFilter(personId: $personId);
        return $this->suche($filter);
    }

    /** Anzahl der Fahrzeuge auf dem Abstellgleis (für das Menü). */
    public function anzahlAbgestellt(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM fahrzeug WHERE status = 'ausgemustert'")->fetchColumn();
    }

    /**
     * Aufs Abstellgleis (Status „ausgemustert“, seit heute) bzw. zurück in Betrieb („einsatzbereit“, ohne Datum).
     * Ein bereits ausgemustertes Fahrzeug behält beim erneuten Abstellen sein Datum.
     */
    public function setzeAbgestellt(int $id, bool $abgestellt): void
    {
        if ($abgestellt) {
            $sql = "UPDATE fahrzeug SET ausgemustert_am = " . self::AUSGEMUSTERT_AM . ", status = 'ausgemustert' WHERE id = ?";
            $this->pdo->prepare($sql)->execute(['ausgemustert', $id]);
            return;
        }
        $this->pdo->prepare("UPDATE fahrzeug SET ausgemustert_am = NULL, status = 'einsatzbereit' WHERE id = ?")->execute([$id]);
    }

    /** @return ?array<string,mixed> */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT f.*, s.name AS spurweite_name, '
            . "TRIM(CONCAT(p.vorname, ' ', p.nachname)) AS eigentuemer_name "
            . 'FROM fahrzeug f JOIN spurweite s ON s.id = f.spurweite_id LEFT JOIN person p ON p.id = f.eigentuemer_person_id WHERE f.id = ?'
        );
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /** @param array<string,mixed> $d */
    public function anlegen(array $d, ?int $angelegtVonBenutzerId = null): int
    {
        $werte = $this->werte($d);
        $spalten = implode(', ', self::FELDER);
        $platzhalter = implode(', ', array_fill(0, count(self::FELDER) + 2, '?'));
        $status = $werte[array_search('status', self::FELDER, true)];
        $letzter = null;
        for ($versuch = 0; $versuch < 3; $versuch++) {
            $nummer = Inventarnummer::naechste($this->pdo);
            try {
                $this->pdo->prepare("INSERT INTO fahrzeug (inventarnummer, angelegt_von_benutzer_id, $spalten, ausgemustert_am) "
                    . "VALUES ($platzhalter, IF(? = 'ausgemustert', CURDATE(), NULL))")
                    ->execute([$nummer, $angelegtVonBenutzerId, ...$werte, $status]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_fahrzeug_inventarnummer')) {
                    $letzter = $e; // gleichzeitig vergeben: neu versuchen
                    continue;
                }
                throw $e;
            }
            $id = (int) $this->pdo->lastInsertId();
            Inventarnummer::merke($this->pdo, $nummer);
            return $id;
        }
        throw $letzter ?? new \RuntimeException('Inventarnummer konnte nicht vergeben werden.');
    }

    /**
     * @param array<string,mixed> $d
     * @throws KonfliktFehler wenn der Datensatz inzwischen geändert oder gelöscht wurde
     */
    public function aktualisieren(int $id, array $d, string $erwarteterStand): void
    {
        $zuweisung = implode(', ', array_map(fn(string $f) => "$f = ?", self::FELDER));
        // geaendert_am wird immer explizit gesetzt, damit auch ein unverändert gespeichertes Formular 1 Zeile trifft.
        $werte = $this->werte($d);
        $status = $werte[array_search('status', self::FELDER, true)];
        $st = $this->pdo->prepare('UPDATE fahrzeug SET ausgemustert_am = ' . self::AUSGEMUSTERT_AM . ", $zuweisung, geaendert_am = NOW(6) WHERE id = ? AND geaendert_am = ?");
        $st->execute([$status, ...$werte, $id, $erwarteterStand]);
        if ($st->rowCount() === 0) {
            throw new KonfliktFehler();
        }
    }

    public function loeschen(int $id): void
    {
        $this->pdo->prepare('DELETE FROM fahrzeug WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string,mixed> $d
     * @return list<mixed>
     */
    private function werte(array $d): array
    {
        $w = [];
        foreach (self::FELDER as $f) {
            $w[] = $d[$f] ?? self::STANDARD[$f] ?? null;
        }
        return $w;
    }
}
