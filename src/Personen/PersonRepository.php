<?php

declare(strict_types=1);

namespace Emc\Personen;

use Emc\Core\DomainFehler;

final class PersonRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function alle(bool $nurAktive = true): array
    {
        $sql = 'SELECT * FROM person' . ($nurAktive ? ' WHERE aktiv = 1' : '') . ' ORDER BY nachname, vorname, id';
        return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return ?array<string,mixed> */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM person WHERE id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /** @param array<string,mixed> $daten */
    public function speichern(array $daten, ?int $id = null): int
    {
        $werte = [
            trim((string) ($daten['vorname'] ?? '')),
            trim((string) ($daten['nachname'] ?? '')),
            self::leerNull($daten['email'] ?? null),
            self::leerNull($daten['telefon'] ?? null),
            self::leerNull($daten['notiz'] ?? null),
            array_key_exists('aktiv', $daten) ? ($daten['aktiv'] ? 1 : 0) : 1,
        ];
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO person (vorname, nachname, email, telefon, notiz, aktiv) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute($werte);
            return (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare('UPDATE person SET vorname = ?, nachname = ?, email = ?, telefon = ?, notiz = ?, aktiv = ? WHERE id = ?')
            ->execute([...$werte, $id]);
        return $id;
    }

    public function loeschen(int $id): void
    {
        if ($this->zaehle('SELECT COUNT(*) FROM fahrzeug WHERE eigentuemer_person_id = ?', $id) > 0) {
            throw new DomainFehler('Diese Person kann nicht gelöscht werden, weil ihr noch Fahrzeuge gehören.');
        }
        if ($this->zaehle('SELECT COUNT(*) FROM benutzer WHERE person_id = ?', $id) > 0) {
            throw new DomainFehler('Diese Person hat einen Zugang. Bitte zuerst den Zugang löschen oder die Person deaktivieren.');
        }
        try {
            $this->pdo->prepare('DELETE FROM person WHERE id = ?')->execute([$id]);
        } catch (\PDOException $e) {
            // Gleichzeitig entstandener Bezug (Fremdschlüssel RESTRICT).
            throw new DomainFehler('Diese Person wird noch verwendet und kann nicht gelöscht werden. Bitte stattdessen deaktivieren.');
        }
    }

    private function zaehle(string $sql, int $id): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute([$id]);
        return (int) $st->fetchColumn();
    }

    private static function leerNull(mixed $w): ?string
    {
        $s = trim((string) ($w ?? ''));
        return $s === '' ? null : $s;
    }
}
