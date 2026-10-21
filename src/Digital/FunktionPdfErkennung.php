<?php

declare(strict_types=1);

namespace Emc\Digital;

/**
 * Erkennt Funktionstasten („F0 Licht“, „F2 – Pfiff“, „F 3: Glocke“) im Text einer Hersteller-Anleitung.
 *
 * Arbeitet nur auf Text (das Auslesen des PDFs macht PdfText). Was nicht sicher erkannt wird,
 * wird nicht übernommen; statt Fehlern gibt es Hinweise für die Vorschau.
 */
final class FunktionPdfErkennung
{
    private const MAX_BESCHREIBUNG = 100;

    /**
     * Tasten-Anfang: Taste, optional Bereich, Trenner; danach muss Text oder das Zeilenende folgen.
     * Gruppe 1 = Taste, Gruppe 2 = Ende eines Bereichs.
     */
    private const ANFANG = '/(?<![^\h|])F\h?(\d{1,3})(?![\d\p{L}])'
        . '(?:\h*(?:–|—|-|…|\.\.\.?|bis)\h*F?\h?(\d{1,3})(?![\d\p{L}]))?'
        . '\h*[:=|.\-–—]?\h*(?=[\p{L}\d"„“”\'‚‘’»«]|$)/iu';

    private const MOMENT = '/\h*(?:\((?:M|Moment|Taster|Impuls)\)|\[M\])$/iu';

    private const LEER = ['frei', 'nicht belegt', 'unbelegt', 'reserviert', 'ohne funktion', 'keine funktion', '-', '–', '—'];

    private const ZERLEGT = [
        "a\u{0308}" => 'ä', "o\u{0308}" => 'ö', "u\u{0308}" => 'ü',
        "A\u{0308}" => 'Ä', "O\u{0308}" => 'Ö', "U\u{0308}" => 'Ü',
        "\u{FB01}" => 'fi', "\u{FB02}" => 'fl',
    ];

    public static function erkenne(string $text): CsvErgebnis
    {
        $zeilen = self::verbindeTrennungen(self::vorbereiten($text));

        $gefunden = [];   // Taste → Zeile
        $vergleich = [];  // Taste → Beschreibung in Kleinbuchstaben (vor dem Kürzen)
        $hinweise = [];
        foreach ($zeilen as $zeile) {
            foreach (self::teile($zeile) as [$taste, $bis, $roh]) {
                [$beschreibung, $schaltart] = self::saeubern($roh);
                if (self::istLeer($beschreibung)) {
                    continue;
                }
                if ($bis !== null) {
                    $hinweise[] = sprintf('Bereich F%d–F%d („%s“) nicht übernommen – bitte von Hand eintragen.', $taste, $bis, $beschreibung);
                    continue;
                }
                if ($taste > FunktionRepository::MAX_TASTE) {
                    $hinweise[] = sprintf('F%d liegt außerhalb von F0–F%d und wurde übersprungen.', $taste, FunktionRepository::MAX_TASTE);
                    continue;
                }
                if (isset($gefunden[$taste])) {
                    if ($vergleich[$taste] !== mb_strtolower($beschreibung)) {
                        $hinweise[] = sprintf('F%d kommt mehrfach vor; übernommen wurde „%s“.', $taste, $gefunden[$taste]['beschreibung']);
                    }
                    continue;
                }
                $vergleich[$taste] = mb_strtolower($beschreibung);
                if (mb_strlen($beschreibung) > self::MAX_BESCHREIBUNG) {
                    $beschreibung = mb_substr($beschreibung, 0, self::MAX_BESCHREIBUNG);
                    $hinweise[] = sprintf('Beschreibung von F%d auf %d Zeichen gekürzt.', $taste, self::MAX_BESCHREIBUNG);
                }
                $gefunden[$taste] = ['taste' => $taste, 'beschreibung' => $beschreibung, 'schaltart' => $schaltart];
            }
        }
        ksort($gefunden);
        return new CsvErgebnis(array_values($gefunden), [], $hinweise);
    }

    /** @return list<string> Zeilen mit einheitlichem Zeichensatz */
    private static function vorbereiten(string $text): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = strtr($text, self::ZERLEGT);
        return preg_split('/\r\n|\r|\n/', $text) ?: [];
    }

    /**
     * Verbindet „Rangierbeleuch-“ + „tung“; nicht, wenn die nächste Zeile mit einer Taste oder groß beginnt.
     *
     * @param list<string> $zeilen
     * @return list<string>
     */
    private static function verbindeTrennungen(array $zeilen): array
    {
        $r = [];
        $n = count($zeilen);
        for ($i = 0; $i < $n; $i++) {
            $zeile = $zeilen[$i];
            while ($i + 1 < $n
                && preg_match('/[a-zäöüß]-\h*$/u', $zeile) === 1
                && preg_match('/^\h*\p{Ll}/u', $zeilen[$i + 1]) === 1
                && preg_match('/^\h*f\h?\d/iu', $zeilen[$i + 1]) !== 1
            ) {
                $zeile = (string) preg_replace('/-\h*$/u', '', $zeile) . (string) preg_replace('/^\h+/u', '', $zeilen[++$i]);
            }
            $r[] = $zeile;
        }
        return $r;
    }

    /**
     * Teilt eine Zeile an jedem Tasten-Anfang; Text vor dem ersten Anfang entfällt.
     *
     * @return list<array{0:int,1:?int,2:string}> Taste, Bereichsende oder null, Rohtext
     */
    private static function teile(string $zeile): array
    {
        if (preg_match_all(self::ANFANG, $zeile, $treffer, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }
        $teile = [];
        foreach ($treffer as $k => $m) {
            $start = $m[0][1] + strlen($m[0][0]);
            $ende = isset($treffer[$k + 1]) ? $treffer[$k + 1][0][1] : strlen($zeile);
            $bis = isset($m[2]) && $m[2][1] >= 0 && $m[2][0] !== '' ? (int) $m[2][0] : null;
            $teile[] = [(int) $m[1][0], $bis, substr($zeile, $start, $ende - $start)];
        }
        return $teile;
    }

    /** @return array{0:string,1:string} Beschreibung, Schaltart */
    private static function saeubern(string $roh): array
    {
        $text = self::trimme((string) preg_replace('/\h+/u', ' ', $roh));
        $text = self::trimme((string) preg_replace('/\.{3,}\h*\d*$/u', '', $text));
        $schaltart = 'dauer';
        if (preg_match(self::MOMENT, $text) === 1) {
            $schaltart = 'moment';
            $text = self::trimme((string) preg_replace(self::MOMENT, '', $text));
        }
        return [$text, $schaltart];
    }

    private static function istLeer(string $beschreibung): bool
    {
        return preg_match('/^[\p{N}\p{P}\p{S}\h]*$/u', $beschreibung) === 1
            || in_array(mb_strtolower($beschreibung), self::LEER, true);
    }

    private static function trimme(string $text): string
    {
        return (string) preg_replace('/^\h+|\h+$/u', '', $text);
    }
}
