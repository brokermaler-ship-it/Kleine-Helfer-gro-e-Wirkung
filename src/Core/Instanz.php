<?php

declare(strict_types=1);

namespace Emc\Core;

/**
 * Merkmale einer Installation (Echtbetrieb oder Testinstanz) aus der config.php.
 * Beide Instanzen können im selben Hosting-Paket liegen; Sitzungen dürfen nie instanzübergreifend gelten.
 */
final class Instanz
{
    public const SITZUNG_MINUTEN_STANDARD = 480;

    /** Nur ein echtes `true` schaltet die Testumgebung ein (nicht 'false', 1 oder 'true'). */
    public static function istTestumgebung(array $config): bool
    {
        return ($config['testumgebung'] ?? false) === true;
    }

    /** Kennung der Instanz: Datenbank + basis_url. */
    public static function fingerabdruck(array $config): string
    {
        return hash('sha256', (string) ($config['db_dsn'] ?? '') . '|' . (string) ($config['basis_url'] ?? ''));
    }

    public static function sitzungsName(array $config): string
    {
        return 'emc78_' . substr(self::fingerabdruck($config), 0, 8);
    }

    public static function cookiePfad(array $config): string
    {
        $basis = rtrim((string) ($config['basis_url'] ?? ''), '/');
        return $basis === '' ? '/' : $basis;
    }

    public static function sitzungSekunden(array $config): int
    {
        $minuten = $config['sitzung_minuten'] ?? self::SITZUNG_MINUTEN_STANDARD;
        if (!is_int($minuten) || $minuten <= 0) {
            $minuten = self::SITZUNG_MINUTEN_STANDARD;
        }
        return $minuten * 60;
    }
}
