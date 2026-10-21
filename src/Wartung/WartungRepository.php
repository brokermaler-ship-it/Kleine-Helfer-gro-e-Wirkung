<?php

declare(strict_types=1);

namespace Emc\Wartung;

/** Wartungsprotokoll: eingetragene Wartungen je Objekt (Fahrzeug oder Anlage) mit ihren Wartungsarten. */
final class WartungRepository
{
    /** SQL-Ausdruck (Alias f für fahrzeug) für dieselbe Regel wie fuehrtStunden – für Listen in einer Abfrage. */
    public const FUEHRT_STUNDEN_SQL = "(f.betriebsstunden > 0 OR EXISTS (SELECT 1 FROM wartung ws WHERE ws.objekt_typ = 'fahrzeug' AND ws.objekt_id = f.id AND ws.betriebsstunden IS NOT NULL))";

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Legt eine Wartung samt Wartungsarten an. Läuft in der Transaktion des Aufrufers, sonst in einer eigenen.
     *
     * @param array{datum:string,betriebsstunden:?float|?string,stundenstand_bezug:?float|?string,ausgefuehrt_von_person_id:?int,notiz:?string} $d
     * @param list<int> $artIds
     */
    public function eintragen(string $objektTyp, int $objektId, array $d, array $artIds, ?int $benutzerId): int
    {
        $eigene = !$this->pdo->inTransaction();
        if ($eigene) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO wartung (objekt_typ, objekt_id, datum, betriebsstunden, stundenstand_bezug, ausgefuehrt_von_person_id, notiz, erfasst_von_benutzer_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $objektTyp,
                $objektId,
                $d['datum'],
                $d['betriebsstunden'] ?? null,
                $d['stundenstand_bezug'] ?? null,
                $d['ausgefuehrt_von_person_id'] ?? null,
                $d['notiz'] ?? null,
                $benutzerId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $st = $this->pdo->prepare('INSERT INTO wartung_art (wartung_id, wartungsart_id) VALUES (?, ?)');
            foreach (array_unique($artIds) as $artId) {
                $st->execute([$id, $artId]);
            }
            if ($eigene) {
                $this->pdo->commit();
            }
            return $id;
        } catch (\Throwable $t) {
            if ($eigene) {
                $this->pdo->rollBack();
            }
            throw $t;
        }
    }

    /**
     * Verlauf eines Objekts, neueste zuerst (Datum, bei gleichem Datum die zuletzt eingetragene).
     *
     * @return list<array{id:int, datum:string, betriebsstunden:?string, arten:list<string>, ausgefuehrt_von:?string, notiz:?string, fotos:list<array<string,mixed>>}>
     */
    public function fuerObjekt(string $objektTyp, int $objektId): array
    {
        $st = $this->pdo->prepare(
            "SELECT w.id, w.datum, w.betriebsstunden, w.notiz, TRIM(CONCAT(COALESCE(p.vorname, ''), ' ', COALESCE(p.nachname, ''))) AS person, p.id AS person_id "
            . 'FROM wartung w LEFT JOIN person p ON p.id = w.ausgefuehrt_von_person_id '
            . 'WHERE w.objekt_typ = ? AND w.objekt_id = ? ORDER BY w.datum DESC, w.id DESC'
        );
        $st->execute([$objektTyp, $objektId]);
        $zeilen = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($zeilen === []) {
            return [];
        }
        $ids = array_map(fn(array $z): int => (int) $z['id'], $zeilen);
        $arten = $this->artenNamen($ids);
        $fotos = $this->fotos($ids);
        return array_map(fn(array $z): array => [
            'id' => (int) $z['id'],
            'datum' => (string) $z['datum'],
            'betriebsstunden' => $z['betriebsstunden'] === null ? null : (string) $z['betriebsstunden'],
            'arten' => $arten[(int) $z['id']] ?? [],
            'ausgefuehrt_von' => $z['person_id'] === null ? null : (string) $z['person'],
            'notiz' => $z['notiz'] === null ? null : (string) $z['notiz'],
            'fotos' => $fotos[(int) $z['id']] ?? [],
        ], $zeilen);
    }

    /** @return ?array<string,mixed> Zeile der Wartung samt arten (list<string>) */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM wartung WHERE id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        if ($z === false) {
            return null;
        }
        $z['id'] = (int) $z['id'];
        $z['objekt_id'] = (int) $z['objekt_id'];
        $z['arten'] = $this->artenNamen([$z['id']])[$z['id']] ?? [];
        return $z;
    }

    /** Löscht die Wartung (Wartungsarten per CASCADE); Fotos entfernt der Aufrufer vorher. */
    public function loeschen(int $id): void
    {
        $this->pdo->prepare('DELETE FROM wartung WHERE id = ?')->execute([$id]);
    }

    /** @return list<int> */
    public function idsFuerObjekt(string $objektTyp, int $objektId): array
    {
        $st = $this->pdo->prepare('SELECT id FROM wartung WHERE objekt_typ = ? AND objekt_id = ? ORDER BY id');
        $st->execute([$objektTyp, $objektId]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Letzte Wartung je Objekt und Wartungsart: maßgeblich ist das jüngste Datum (bei gleichem Datum die höchste ID),
     * nicht der höchste Stundenstand – ein nachgetragener älterer Eintrag verdrängt die jüngere Wartung nicht.
     * Der Stundenstand kommt aus der jüngsten Wartung dieser Art mit Stundenstand – aber nur, wenn sie am selben Tag
     * wie die jüngste Wartung liegt. Hat die jüngste Wartung keinen Stand, ist der Stundenbezug unbekannt (null →
     * „Stundenstand fehlt“): ein älterer Stand würde eine gerade erledigte Wartung sonst fälschlich „überfällig“ machen.
     * Ein älterer Nachtrag ohne Stand hinter einer jüngeren Wartung mit Stand ändert nichts. Eine Abfrage.
     *
     * @return array<string, array{datum:string, stundenstand:?float}> Schlüssel "<typ>:<id>:<artId>"
     */
    public function letzteJeArt(string $objektTyp, ?int $objektId = null): array
    {
        $teil = 'PARTITION BY w.objekt_typ, w.objekt_id, wa.wartungsart_id';
        $sql = 'SELECT objekt_typ, objekt_id, wartungsart_id, datum, stundenstand_bezug, rang, rang_stand FROM ('
            . 'SELECT w.objekt_typ, w.objekt_id, wa.wartungsart_id, w.datum, w.stundenstand_bezug, '
            . "ROW_NUMBER() OVER ($teil ORDER BY w.datum DESC, w.id DESC) AS rang, "
            . "ROW_NUMBER() OVER ($teil, w.stundenstand_bezug IS NULL ORDER BY w.datum DESC, w.id DESC) AS rang_stand "
            . 'FROM wartung w JOIN wartung_art wa ON wa.wartung_id = w.id '
            . 'WHERE w.objekt_typ = ?' . ($objektId === null ? '' : ' AND w.objekt_id = ?')
            . ') t WHERE rang = 1 OR (rang_stand = 1 AND stundenstand_bezug IS NOT NULL)';
        $st = $this->pdo->prepare($sql);
        $st->execute($objektId === null ? [$objektTyp] : [$objektTyp, $objektId]);
        $datum = [];
        $stand = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $k = $z['objekt_typ'] . ':' . (int) $z['objekt_id'] . ':' . (int) $z['wartungsart_id'];
            if ((int) $z['rang'] === 1) {
                $datum[$k] = (string) $z['datum'];
            }
            if ($z['stundenstand_bezug'] !== null && (int) $z['rang_stand'] === 1) {
                $stand[$k] = ['datum' => (string) $z['datum'], 'wert' => (float) $z['stundenstand_bezug']];
            }
        }
        $ergebnis = [];
        foreach ($datum as $k => $d) {
            $s = $stand[$k] ?? null;
            $ergebnis[$k] = ['datum' => $d, 'stundenstand' => $s !== null && $s['datum'] === $d ? $s['wert'] : null];
        }
        return $ergebnis;
    }

    /**
     * Ob das Fahrzeug Betriebsstunden führt: Stand über 0 oder mindestens eine Wartung mit eingegebenem Stand (auch 0).
     * Sonst (Stand 0, nie eingegeben – z. B. Wagen) zählt bei Fälligkeiten nur die Zeit.
     */
    public function fuehrtStunden(int $fahrzeugId, float $aktuell): bool
    {
        if ($aktuell > 0) {
            return true;
        }
        $st = $this->pdo->prepare("SELECT 1 FROM wartung WHERE objekt_typ = 'fahrzeug' AND objekt_id = ? AND betriebsstunden IS NOT NULL LIMIT 1");
        $st->execute([$fahrzeugId]);
        return $st->fetchColumn() !== false;
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<string>> Namen je Wartung, sortiert wie die Wartungsarten
     */
    private function artenNamen(array $ids): array
    {
        $st = $this->pdo->prepare(
            'SELECT wa.wartung_id, a.name FROM wartung_art wa JOIN wartungsart a ON a.id = wa.wartungsart_id '
            . 'WHERE wa.wartung_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY a.sortierung, a.name, a.id'
        );
        $st->execute($ids);
        $namen = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $namen[(int) $z['wartung_id']][] = (string) $z['name'];
        }
        return $namen;
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array<string,mixed>>> Fotos (Dokumentzeilen) je Wartung
     */
    private function fotos(array $ids): array
    {
        $st = $this->pdo->prepare(
            "SELECT z.objekt_id AS wartung_id, d.* FROM dokument_zuordnung z JOIN dokument d ON d.id = z.dokument_id "
            . "WHERE z.objekt_typ = 'wartung' AND z.objekt_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY d.id'
        );
        $st->execute($ids);
        $fotos = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $fotos[(int) $z['wartung_id']][] = $z;
        }
        return $fotos;
    }
}
