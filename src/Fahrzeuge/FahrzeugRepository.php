<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

final class FahrzeugRepository
{
    private const FELDER = [
        'bezeichnung', 'baureihe', 'bahnverwaltung', 'epoche', 'gattung', 'hersteller', 'artikelnummer',
        'spurweite_id', 'stromsystem', 'eigentuemer_person_id', 'status', 'betriebsstunden', 'notiz',
    ];
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
        } else {
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
        $letzter = null;
        for ($versuch = 0; $versuch < 3; $versuch++) {
            $nummer = Inventarnummer::naechste($this->pdo);
            try {
                $this->pdo->prepare("INSERT INTO fahrzeug (inventarnummer, angelegt_von_benutzer_id, $spalten) VALUES ($platzhalter)")
                    ->execute([$nummer, $angelegtVonBenutzerId, ...$werte]);
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
        $st = $this->pdo->prepare("UPDATE fahrzeug SET $zuweisung, geaendert_am = NOW(6) WHERE id = ? AND geaendert_am = ?");
        $st->execute([...$this->werte($d), $id, $erwarteterStand]);
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
