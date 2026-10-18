<?php

declare(strict_types=1);

namespace Emc\Digital;

final class DecoderRepository
{
    private const FELDER = ['bezeichnung', 'hersteller', 'typ', 'firmware', 'sound', 'protokoll', 'adresse'];

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function fuerFahrzeug(int $fahrzeugId): array
    {
        $st = $this->pdo->prepare(
            'SELECT d.*,
                    (SELECT COUNT(*) FROM funktion f WHERE f.decoder_id = d.id) AS funktionen_anzahl,
                    (SELECT COUNT(*) FROM cv_wert c WHERE c.decoder_id = d.id) AS cv_anzahl
             FROM decoder d WHERE d.fahrzeug_id = ? ORDER BY d.id'
        );
        $st->execute([$fahrzeugId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return ?array<string,mixed> */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM decoder WHERE id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /** @param array<string,mixed> $d */
    public function anlegen(int $fahrzeugId, array $d): int
    {
        $this->pdo->prepare(
            'INSERT INTO decoder (fahrzeug_id, ' . implode(', ', self::FELDER) . ') VALUES (?, ' . implode(', ', array_fill(0, count(self::FELDER), '?')) . ')'
        )->execute([$fahrzeugId, ...$this->werte($d)]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $d */
    public function aktualisieren(int $id, array $d): void
    {
        $zuweisung = implode(', ', array_map(fn(string $f) => "$f = ?", self::FELDER));
        $this->pdo->prepare("UPDATE decoder SET $zuweisung WHERE id = ?")->execute([...$this->werte($d), $id]);
    }

    public function loeschen(int $id): void
    {
        $this->pdo->prepare('DELETE FROM decoder WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string,mixed> $d
     * @return list<mixed>
     */
    private function werte(array $d): array
    {
        $w = [];
        foreach (self::FELDER as $f) {
            $wert = $d[$f] ?? null;
            if ($f === 'sound') {
                $wert = $wert ? 1 : 0;
            } elseif ($f === 'protokoll') {
                $wert = $wert instanceof Protokoll ? $wert->value : ($wert ?? 'dcc');
            }
            $w[] = $wert;
        }
        return $w;
    }
}
