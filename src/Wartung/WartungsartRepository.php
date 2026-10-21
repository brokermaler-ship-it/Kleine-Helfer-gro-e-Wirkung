<?php

declare(strict_types=1);

namespace Emc\Wartung;

use Emc\Core\DomainFehler;

/**
 * Stammliste der Wartungsarten. Zeilen kommen mit Ganzzahlen für id, Intervalle (oder null), aktiv und sortierung zurück.
 */
final class WartungsartRepository
{
    public const DOPPELT = 'Diese Wartungsart gibt es schon.';
    public const VERWENDET = 'Diese Wartungsart wurde schon verwendet. Bitte stattdessen deaktivieren.';

    /** Felder, die speichern() übernimmt (auch für das Änderungsprotokoll). */
    public const FELDER = ['name', 'gilt_fuer', 'intervall_monate', 'intervall_stunden', 'aktiv', 'sortierung'];

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> sortiert nach sortierung, name */
    public function alle(): array
    {
        $zeilen = $this->pdo->query('SELECT * FROM wartungsart ORDER BY sortierung, name, id')->fetchAll(\PDO::FETCH_ASSOC);
        return array_map([self::class, 'normalisiere'], $zeilen);
    }

    /** @return ?array<string,mixed> */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM wartungsart WHERE id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : self::normalisiere($z);
    }

    /**
     * Legt an ($id null) oder ändert. Erwartet geprüfte Daten (WartungsartValidator::pruefe).
     *
     * @param array<string,mixed> $d
     * @throws DomainFehler bei doppeltem Namen (Vergleich ohne Groß-/Kleinschreibung)
     */
    public function speichern(array $d, ?int $id = null): int
    {
        $werte = [
            trim((string) $d['name']),
            (string) $d['gilt_fuer'],
            $d['intervall_monate'] ?? null,
            $d['intervall_stunden'] ?? null,
            empty($d['aktiv']) ? 0 : 1,
            (int) ($d['sortierung'] ?? 0),
        ];
        try {
            if ($id === null) {
                $this->pdo->prepare(
                    'INSERT INTO wartungsart (name, gilt_fuer, intervall_monate, intervall_stunden, aktiv, sortierung) VALUES (?, ?, ?, ?, ?, ?)'
                )->execute($werte);
                return (int) $this->pdo->lastInsertId();
            }
            $this->pdo->prepare(
                'UPDATE wartungsart SET name = ?, gilt_fuer = ?, intervall_monate = ?, intervall_stunden = ?, aktiv = ?, sortierung = ? WHERE id = ?'
            )->execute([...$werte, $id]);
            return $id;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { // UNIQUE(name)
                throw new DomainFehler(self::DOPPELT, 0, $e);
            }
            throw $e;
        }
    }

    /** @throws DomainFehler wenn die Art schon in Wartungen verwendet wurde */
    public function loeschen(int $id): void
    {
        if ($this->istVerwendet($id)) {
            throw new DomainFehler(self::VERWENDET);
        }
        try {
            $this->pdo->prepare('DELETE FROM wartungsart WHERE id = ?')->execute([$id]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { // Bezug aus Wartungen (FK RESTRICT)
                throw new DomainFehler(self::VERWENDET, 0, $e);
            }
            throw $e;
        }
    }

    /** Wurde die Art schon in einer Wartung eingetragen? (Ausnahmen im Wartungsplan zählen nicht, sie entfallen mit.) */
    public function istVerwendet(int $id): bool
    {
        try {
            $st = $this->pdo->prepare('SELECT 1 FROM wartung_art WHERE wartungsart_id = ? LIMIT 1');
            $st->execute([$id]);
            return $st->fetchColumn() !== false;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return false; // Datenbank-Update (Migration 013) noch nicht ausgeführt: es gibt noch keine Wartungen.
            }
            throw $e;
        }
    }

    /**
     * Aktive Wartungsarten, die zum Objekt passen (GiltFuer::passt), sortiert wie alle().
     *
     * @return list<array<string,mixed>>
     */
    public function passendeFuer(string $objektTyp, ?string $gattung): array
    {
        return array_values(array_filter(
            $this->alle(),
            fn(array $a) => $a['aktiv'] === 1 && GiltFuer::from((string) $a['gilt_fuer'])->passt($objektTyp, $gattung)
        ));
    }

    /**
     * @param array<string,mixed> $z
     * @return array<string,mixed>
     */
    private static function normalisiere(array $z): array
    {
        $z['id'] = (int) $z['id'];
        $z['intervall_monate'] = $z['intervall_monate'] === null ? null : (int) $z['intervall_monate'];
        $z['intervall_stunden'] = $z['intervall_stunden'] === null ? null : (int) $z['intervall_stunden'];
        $z['aktiv'] = (int) $z['aktiv'];
        $z['sortierung'] = (int) $z['sortierung'];
        return $z;
    }
}
