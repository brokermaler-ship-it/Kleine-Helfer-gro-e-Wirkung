<?php

declare(strict_types=1);

namespace Emc\Wartung;

use Emc\Core\DomainFehler;

/**
 * Ausnahmen im Wartungsplan je Objekt: eine Wartungsart abschalten oder ein eigenes Intervall setzen.
 * Ein eigenes Intervall ersetzt beide Werte der Wartungsart (null = Kriterium entfällt).
 */
final class AusnahmeRepository
{
    public const MODI = ['standard', 'aus', 'eigen'];
    public const OHNE_INTERVALL = 'Bitte ein eigenes Intervall in Monaten oder Stunden angeben.';

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array<int, array{aus:bool, intervall_monate:?int, intervall_stunden:?int}> Schlüssel Wartungsart-ID */
    public function fuerObjekt(string $objektTyp, int $objektId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM wartungsplan_ausnahme WHERE objekt_typ = ? AND objekt_id = ? ORDER BY wartungsart_id');
        $st->execute([$objektTyp, $objektId]);
        $ergebnis = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $ergebnis[(int) $z['wartungsart_id']] = self::wert($z);
        }
        return $ergebnis;
    }

    /** @return array<string, array{aus:bool, intervall_monate:?int, intervall_stunden:?int}> Schlüssel "<typ>:<id>:<artId>" */
    public function alle(): array
    {
        $ergebnis = [];
        foreach ($this->pdo->query('SELECT * FROM wartungsplan_ausnahme ORDER BY objekt_typ, objekt_id, wartungsart_id')->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $ergebnis[$z['objekt_typ'] . ':' . (int) $z['objekt_id'] . ':' . (int) $z['wartungsart_id']] = self::wert($z);
        }
        return $ergebnis;
    }

    /**
     * @param string $modus standard (Zeile löschen), aus (abschalten) oder eigen (eigenes Intervall)
     * @throws DomainFehler bei „eigen“ ohne Intervall
     * @throws \InvalidArgumentException bei unbekanntem Modus
     */
    public function speichere(string $objektTyp, int $objektId, int $artId, string $modus, ?int $monate, ?int $stunden): void
    {
        if ($modus === 'standard') {
            $this->pdo->prepare('DELETE FROM wartungsplan_ausnahme WHERE objekt_typ = ? AND objekt_id = ? AND wartungsart_id = ?')
                ->execute([$objektTyp, $objektId, $artId]);
            return;
        }
        if ($modus === 'aus') {
            [$aus, $monate, $stunden] = [1, null, null];
        } elseif ($modus === 'eigen') {
            if ($monate === null && $stunden === null) {
                throw new DomainFehler(self::OHNE_INTERVALL);
            }
            $aus = 0;
        } else {
            throw new \InvalidArgumentException('Unbekannter Modus: ' . $modus);
        }
        $this->pdo->prepare(
            'INSERT INTO wartungsplan_ausnahme (objekt_typ, objekt_id, wartungsart_id, aus, intervall_monate, intervall_stunden) VALUES (?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE aus = VALUES(aus), intervall_monate = VALUES(intervall_monate), intervall_stunden = VALUES(intervall_stunden)'
        )->execute([$objektTyp, $objektId, $artId, $aus, $monate, $stunden]);
    }

    public function loescheFuerObjekt(string $objektTyp, int $objektId): void
    {
        $this->pdo->prepare('DELETE FROM wartungsplan_ausnahme WHERE objekt_typ = ? AND objekt_id = ?')->execute([$objektTyp, $objektId]);
    }

    /**
     * @param array<string,mixed> $z
     * @return array{aus:bool, intervall_monate:?int, intervall_stunden:?int}
     */
    private static function wert(array $z): array
    {
        return [
            'aus' => (int) $z['aus'] === 1,
            'intervall_monate' => $z['intervall_monate'] === null ? null : (int) $z['intervall_monate'],
            'intervall_stunden' => $z['intervall_stunden'] === null ? null : (int) $z['intervall_stunden'],
        ];
    }
}
