<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

/** Fortlaufende Inventarnummern EMC-0001 …; vergebene Nummern werden nie wiederverwendet. */
final class Inventarnummer
{
    public const ZAEHLER = 'inventarnummer_zaehler';

    /** Nächste Nummer = max(höchste vorhandene, Zähler) + 1. */
    public static function naechste(\PDO $pdo): string
    {
        $hoechste = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(inventarnummer, 5) AS UNSIGNED)), 0) FROM fahrzeug WHERE inventarnummer REGEXP '^EMC-[0-9]+$'"
        )->fetchColumn();
        $st = $pdo->prepare('SELECT wert FROM einstellung WHERE schluessel = ?');
        $st->execute([self::ZAEHLER]);
        $zaehler = (int) $st->fetchColumn();
        return self::formatiere(max($hoechste, $zaehler) + 1);
    }

    /** Hält fest, dass diese Nummer vergeben wurde (Zähler wird nie kleiner). */
    public static function merke(\PDO $pdo, string $nummer): void
    {
        $n = (int) substr($nummer, 4);
        $pdo->prepare(
            'INSERT INTO einstellung (schluessel, wert) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE wert = CAST(GREATEST(CAST(wert AS UNSIGNED), ?) AS CHAR)'
        )->execute([self::ZAEHLER, (string) $n, $n]);
    }

    public static function formatiere(int $n): string
    {
        return sprintf('EMC-%04d', $n);
    }
}
