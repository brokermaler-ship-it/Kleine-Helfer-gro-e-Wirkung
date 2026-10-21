<?php

declare(strict_types=1);

namespace Emc\Boerse;

/** Prüft Name, Datum, Ort und Provision einer Börse (Veranstaltung). */
final class BoerseValidator
{
    public const VORGABE_PROVISION = '10.00';
    public const KEINE_PROVISION = 'Bitte eine Provision zwischen 0 und 100 % angeben.';
    public const PROVISION_GESPERRT = 'Die Provision lässt sich nach dem ersten Verkauf nicht mehr ändern.';

    /**
     * @param array<string,mixed> $eingabe Rohdaten aus dem Formular
     * @param bool $hatVerkaeufe es gibt schon (nicht stornierte) Verkäufe – dann bleibt die Provision
     * @param ?string $bisherigeProvision gespeicherte Provision (DECIMAL-Zeichenkette) beim Bearbeiten
     * @return array{0: array{name:?string, datum:?string, ort:?string, provision_prozent:string}, 1: array<string,string>}
     */
    public static function pruefe(array $eingabe, bool $hatVerkaeufe, ?string $bisherigeProvision = null): array
    {
        $name = trim((string) ($eingabe['name'] ?? ''));
        $ort = trim((string) ($eingabe['ort'] ?? ''));
        $f = [];
        if ($name === '') {
            $f['name'] = 'Bitte einen Namen eingeben.';
        } elseif (mb_strlen($name) > 150) {
            $f['name'] = 'Bitte höchstens 150 Zeichen eingeben.';
        }
        if (mb_strlen($ort) > 150) {
            $f['ort'] = 'Bitte höchstens 150 Zeichen eingeben.';
        }
        $datum = self::datum(trim((string) ($eingabe['datum'] ?? '')));
        if ($datum === null) {
            $f['datum'] = 'Bitte ein gültiges Datum angeben.';
        }
        $roh = trim((string) ($eingabe['provision_prozent'] ?? ''));
        $provision = $roh === '' ? self::VORGABE_PROVISION : self::provision($roh);
        if ($provision === null) {
            $f['provision_prozent'] = self::KEINE_PROVISION;
            $provision = $bisherigeProvision ?? self::VORGABE_PROVISION;
        } elseif ($hatVerkaeufe && $bisherigeProvision !== null && Geld::hundertstelProzent($provision) !== Geld::hundertstelProzent(self::provision($bisherigeProvision) ?? '0')) {
            $f['provision_prozent'] = self::PROVISION_GESPERRT;
        }
        return [['name' => $name === '' ? null : $name, 'datum' => $datum, 'ort' => $ort === '' ? null : $ort, 'provision_prozent' => $provision], $f];
    }

    /** „7,5“ → „7.50“, „100“ → „100.00“; null außerhalb 0 … 100 oder mit mehr als zwei Nachkommastellen. */
    public static function provision(string $eingabe): ?string
    {
        if (preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $eingabe, $m) !== 1) {
            return null;
        }
        $nachkomma = str_pad($m[2] ?? '', 2, '0');
        if ((int) $m[1] * 100 + (int) $nachkomma > 10000) {
            return null;
        }
        $ganz = ltrim($m[1], '0');
        return ($ganz === '' ? '0' : $ganz) . '.' . $nachkomma;
    }

    /** Provision für Anzeige und Eingabefeld: „10.00“ → „10“, „7.50“ → „7,5“. */
    public static function provisionAnzeige(string $wert): string
    {
        [$ganz, $rest] = array_pad(explode('.', $wert, 2), 2, '');
        $rest = rtrim($rest, '0');
        return $ganz . ($rest === '' ? '' : ',' . $rest);
    }

    /** Y-m-d (Datumsfeld) oder d.m.Y; null bei ungültigem Datum. */
    private static function datum(string $w): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $w, $m) === 1) {
            [$j, $mo, $t] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $w, $m) === 1) {
            [$t, $mo, $j] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if ($j < 2000 || $j > 2999 || !checkdate($mo, $t, $j)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $j, $mo, $t);
    }
}
