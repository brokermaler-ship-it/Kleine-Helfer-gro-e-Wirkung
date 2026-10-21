<?php

declare(strict_types=1);

namespace Emc\Boerse;

/**
 * Geldbeträge der Börse ohne Gleitkomma: aus der Datenbank kommen DECIMAL(10,2)-Zeichenketten („12.50“), gerechnet
 * wird in ganzen Cent (int). Eingabe aus Formularen über ReparaturValidator::betragWert, Anzeige über
 * ReparaturValidator::betrag. Kein float, kein round()/number_format() auf Beträgen.
 */
final class Geld
{
    /** „12.50“ → 1250, „12.5“ → 1250, „12“ → 1200, „-3.10“ → -310. */
    public static function cent(string $dezimal): int
    {
        if (preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/', $dezimal, $m) !== 1) {
            throw new \InvalidArgumentException('Betrag als DECIMAL-Zeichenkette erwartet, z. B. 12.50.');
        }
        $cent = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        return $m[1] === '-' ? -$cent : $cent;
    }

    /** 1250 → „12.50“, 5 → „0.05“, -310 → „-3.10“. */
    public static function dezimal(int $cent): string
    {
        $vorzeichen = $cent < 0 ? '-' : '';
        $betrag = abs($cent);
        return $vorzeichen . intdiv($betrag, 100) . '.' . str_pad((string) ($betrag % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Provision eines Stücks in Cent, kaufmännisch gerundet: 12,50 € bei 10 % → 125; 0,05 € → 1; 0,04 € → 0.
     *
     * @param int $preisCent Verkaufspreis ≥ 0
     * @param string $prozent DECIMAL(5,2) als Zeichenkette zwischen 0 und 100, z. B. „10.00“ oder „7.5“
     */
    public static function provision(int $preisCent, string $prozent): int
    {
        if ($preisCent < 0) {
            throw new \InvalidArgumentException('Die Provision gilt nur für Beträge ab 0.');
        }
        $hundertstel = self::hundertstelProzent($prozent);
        return intdiv($preisCent * $hundertstel + 5000, 10000);
    }

    /** „7.5“ → 750, „100“ → 10000; außerhalb 0 … 100 % oder mit mehr als zwei Nachkommastellen ungültig. */
    public static function hundertstelProzent(string $prozent): int
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $prozent, $m) !== 1) {
            throw new \InvalidArgumentException('Prozent als DECIMAL-Zeichenkette erwartet, z. B. 10.00.');
        }
        $hundertstel = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($hundertstel > 10000) {
            throw new \InvalidArgumentException('Prozent zwischen 0 und 100 erwartet.');
        }
        return $hundertstel;
    }
}
