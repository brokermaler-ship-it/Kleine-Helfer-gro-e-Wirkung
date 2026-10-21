<?php

declare(strict_types=1);

namespace Emc\Anlagen;

/** Fortlaufende Anlagen-Codes A1, A2 …; vergebene Nummern werden nie wiederverwendet (wie Inventarnummer). */
final class AnlagenCode
{
    public const ZAEHLER = 'anlage_zaehler';

    /** Nächste Nummer = max(höchste vorhandene, Zähler) + 1. */
    public static function naechste(\PDO $pdo): int
    {
        $hoechste = (int) $pdo->query('SELECT COALESCE(MAX(nummer), 0) FROM anlage')->fetchColumn();
        $st = $pdo->prepare('SELECT wert FROM einstellung WHERE schluessel = ?');
        $st->execute([self::ZAEHLER]);
        $zaehler = (int) $st->fetchColumn();
        return max($hoechste, $zaehler) + 1;
    }

    /** Hält fest, dass diese Nummer vergeben wurde (Zähler wird nie kleiner). */
    public static function merke(\PDO $pdo, int $nummer): void
    {
        $pdo->prepare(
            'INSERT INTO einstellung (schluessel, wert) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE wert = CAST(GREATEST(CAST(wert AS UNSIGNED), ?) AS CHAR)'
        )->execute([self::ZAEHLER, (string) $nummer, $nummer]);
    }

    public static function formatiere(int $n): string
    {
        return 'A' . $n;
    }
}
