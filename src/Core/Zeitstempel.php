<?php

declare(strict_types=1);

namespace Emc\Core;

/** Prüft den Bearbeitungsstand aus einem Formular (geaendert_am, DATETIME(6)), bevor er in SQL landet. */
final class Zeitstempel
{
    /** „2026-10-21 12:34:56“ mit optional bis zu sechs Nachkommastellen und gültigem Datum/Uhrzeit. */
    public static function istGueltig(string $stand): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})(\.\d{1,6})?$/', $stand, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60;
    }
}
