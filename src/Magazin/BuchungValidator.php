<?php

declare(strict_types=1);

namespace Emc\Magazin;

use Emc\Reparatur\ReparaturValidator;

/**
 * Prüft eine Magazinbuchung aus dem Formular (Eingang, Ausgang, Umlagerung). Ein Ausgang „verkauft (Börse)“ ist hier
 * nicht wählbar – den bucht nur die Kasse.
 */
final class BuchungValidator
{
    public const ARTEN = ['eingang', 'ausgang', 'umlagerung'];
    public const HERKUNFT = ['spende', 'nachlass', 'kauf', 'fund', 'sonstiges'];
    /** Im Formular wählbare Ausgangsarten (ohne verkauft_boerse). */
    public const AUSGANG_ARTEN = ['gegen_spende', 'verschrottet', 'ausgeliehen', 'sonstiges'];
    public const MENGE_MAX = 99999;
    public const FREITEXT_MAX = 200;
    public const LAGERORT_MAX = 100;

    public const KEIN_DATUM = 'Bitte ein gültiges Datum angeben.';
    public const KEINE_MENGE = 'Bitte eine Menge ab 1 angeben.';
    public const KEINE_HERKUNFT = 'Bitte die Herkunft wählen.';
    public const KEINE_AUSGANG_ART = 'Bitte die Art des Ausgangs wählen.';
    public const KEIN_BETRAG = 'Bitte den Betrag angeben, z. B. 12,50.';
    public const KEIN_LAGERORT = 'Bitte den neuen Lagerort angeben.';

    /**
     * @param array<string,mixed> $eingabe datum, menge, herkunft, ausgang_art, freitext, betrag, lagerort_neu
     * @param string $art eingang, ausgang oder umlagerung
     * @param ?\DateTimeImmutable $heute Standard: heute (leeres Datum → heute, Datum in der Zukunft → Fehler)
     * @return array{0: array{datum:string, menge:int, herkunft:?string, ausgang_art:?string, freitext:?string, betrag:?string, lagerort_neu:?string},
     *               1: array<string,string>}
     */
    public static function pruefe(array $eingabe, string $art, ?\DateTimeImmutable $heute = null): array
    {
        $heute = ($heute ?? new \DateTimeImmutable('today'))->format('Y-m-d');
        $f = [];
        $d = ['datum' => $heute, 'menge' => 0, 'herkunft' => null, 'ausgang_art' => null, 'freitext' => null, 'betrag' => null, 'lagerort_neu' => null];
        if (!in_array($art, self::ARTEN, true)) {
            return [$d, ['art' => 'Bitte eine Buchungsart wählen.']];
        }

        $datum = self::text($eingabe['datum'] ?? null);
        if ($datum !== null) {
            $iso = self::datum($datum);
            if ($iso === null || $iso > $heute) {
                $f['datum'] = self::KEIN_DATUM;
            } else {
                $d['datum'] = $iso;
            }
        }

        if ($art === 'umlagerung') {
            $ort = self::text($eingabe['lagerort_neu'] ?? null);
            if ($ort === null) {
                $f['lagerort_neu'] = self::KEIN_LAGERORT;
            } elseif (mb_strlen($ort) > self::LAGERORT_MAX) {
                $f['lagerort_neu'] = 'Bitte höchstens ' . self::LAGERORT_MAX . ' Zeichen eingeben.';
            }
            $d['lagerort_neu'] = $ort;
            return [$d, $f];
        }

        $menge = self::text($eingabe['menge'] ?? null);
        if ($menge === null || preg_match('/^\d{1,5}$/', $menge) !== 1 || (int) $menge < 1 || (int) $menge > self::MENGE_MAX) {
            $f['menge'] = self::KEINE_MENGE;
        } else {
            $d['menge'] = (int) $menge;
        }

        if ($art === 'eingang') {
            $herkunft = self::text($eingabe['herkunft'] ?? null);
            if ($herkunft === null || !in_array($herkunft, self::HERKUNFT, true)) {
                $f['herkunft'] = self::KEINE_HERKUNFT;
            } else {
                $d['herkunft'] = $herkunft;
            }
        } else {
            $ausgangArt = self::text($eingabe['ausgang_art'] ?? null);
            if ($ausgangArt === null || !in_array($ausgangArt, self::AUSGANG_ARTEN, true)) {
                $f['ausgang_art'] = self::KEINE_AUSGANG_ART;
            } else {
                $d['ausgang_art'] = $ausgangArt;
            }
        }

        $freitext = self::text($eingabe['freitext'] ?? null);
        if ($freitext !== null && mb_strlen($freitext) > self::FREITEXT_MAX) {
            $f['freitext'] = 'Bitte höchstens ' . self::FREITEXT_MAX . ' Zeichen eingeben.';
        }
        $d['freitext'] = $freitext;

        $betrag = self::text($eingabe['betrag'] ?? null);
        if ($betrag !== null) {
            $wert = ReparaturValidator::betragWert($betrag);
            if ($wert === null) {
                $f['betrag'] = self::KEIN_BETRAG;
            } else {
                $d['betrag'] = $wert;
            }
        }
        return [$d, $f];
    }

    /** „2026-10-21“ oder „21.10.2026“ → „2026-10-21“; sonst null. */
    private static function datum(string $w): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $w, $m) === 1) {
            [$j, $mo, $t] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $w, $m) === 1) {
            [$t, $mo, $j] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if ($j < 1900 || !checkdate($mo, $t, $j)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $j, $mo, $t);
    }

    private static function text(mixed $w): ?string
    {
        if (!is_string($w)) {
            return null;
        }
        $w = trim($w);
        return $w === '' ? null : $w;
    }
}
