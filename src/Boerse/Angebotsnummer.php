<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Codes\Code;

/** Angebotsnummer der Börse: fortlaufend je Jahr, „B2026-118“. Reine Logik ohne Datenbank. */
final class Angebotsnummer
{
    public static function formatiere(int $jahr, int $lauf): string
    {
        return 'B' . $jahr . '-' . $lauf;
    }

    /**
     * „B2026-118“, „b2026-0118“, „2026-118“ → 2026/118; eine reine Zahl („118“) nur mit $jahrVorgabe (Kasse: Jahr des
     * Börsendatums). Sonst null (auch Laufnummer 0 oder mehr als sechs Stellen).
     *
     * @return ?array{jahr:int, lauf:int}
     */
    public static function zerlege(string $eingabe, ?int $jahrVorgabe = null): ?array
    {
        $n = Code::normalisiere($eingabe);
        if (preg_match('/^B?(\d{4})-0*([1-9]\d{0,5})$/', $n, $m) === 1) {
            return ['jahr' => (int) $m[1], 'lauf' => (int) $m[2]];
        }
        if ($jahrVorgabe !== null && preg_match('/^0*([1-9]\d{0,5})$/', $n, $m) === 1) {
            return ['jahr' => $jahrVorgabe, 'lauf' => (int) $m[1]];
        }
        return null;
    }
}
