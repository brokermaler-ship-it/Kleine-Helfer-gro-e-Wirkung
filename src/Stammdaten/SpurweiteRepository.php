<?php

declare(strict_types=1);

namespace Emc\Stammdaten;

use Emc\Core\DomainFehler;

final class SpurweiteRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function alle(): array
    {
        return $this->pdo->query('SELECT * FROM spurweite ORDER BY sortierung, name, id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return ?array<string,mixed> */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM spurweite WHERE id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    public function speichern(string $name, ?string $massstab, int $sortierung, ?int $id = null): int
    {
        $name = trim($name);
        $massstab = $massstab === null || trim($massstab) === '' ? null : trim($massstab);
        try {
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO spurweite (name, massstab, sortierung) VALUES (?, ?, ?)')
                    ->execute([$name, $massstab, $sortierung]);
                return (int) $this->pdo->lastInsertId();
            }
            $this->pdo->prepare('UPDATE spurweite SET name = ?, massstab = ?, sortierung = ? WHERE id = ?')
                ->execute([$name, $massstab, $sortierung, $id]);
            return $id;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { // UNIQUE(name), Vergleich ohne Groß-/Kleinschreibung
                throw new DomainFehler('Diese Spurweite gibt es bereits.', 0, $e);
            }
            throw $e;
        }
    }

    public function loeschen(int $id): void
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM fahrzeug WHERE spurweite_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            throw new DomainFehler('Diese Spurweite wird noch von Fahrzeugen verwendet.');
        }
        try {
            $this->pdo->prepare('DELETE FROM spurweite WHERE id = ?')->execute([$id]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { // gleichzeitig entstandener Fahrzeugbezug (FK RESTRICT)
                throw new DomainFehler('Diese Spurweite wird noch von Fahrzeugen verwendet.', 0, $e);
            }
            throw $e;
        }
    }
}
