<?php

declare(strict_types=1);

namespace Emc\Digital;

/**
 * Erkennt Funktionstasten („F0 Licht“, „F2 – Pfiff“, „F 3: Glocke“) im Text einer Hersteller-Anleitung.
 *
 * Arbeitet nur auf Text (das Auslesen des PDFs macht PdfText). Was nicht sicher erkannt wird,
 * wird nicht übernommen; statt Fehlern gibt es Hinweise für die Vorschau.
 * Laufzeit und Speicher wachsen linear mit dem Text; Zeilen, Tasten je Zeile und Hinweise sind begrenzt.
 */
final class FunktionPdfErkennung
{
    private const MAX_BESCHREIBUNG = 100;
    private const MAX_ZEILE = 2000;          // Zeichen je Zeile, der Rest wird abgeschnitten
    private const MAX_ANFAENGE_JE_ZEILE = 70;
    private const MAX_HINWEISE = 20;

    /**
     * Sichere Stelle für einen Tasten-Anfang: am Zeilenanfang (auch nach einem Aufzählungszeichen oder „1.“/„a)“),
     * nach einer Spaltenlücke (Tab, mindestens zwei Leerzeichen, „|“) oder nach einem Wort wie „Taste“/„Funktion“
     * an einer dieser Stellen.
     */
    private const STELLE = '(?:^|\t|\h{2}|\|)\h*+(?:(?:[•·*\-–]|[0-9]{1,3}[.)]|[a-z]\))\h++)?(?:(?:Funktionstaste|Funktion|Taste|Fkt\.)\h++)?';

    /** Taste, optional Bereich, Trenner; es muss Text oder das Zeilenende folgen. Gruppe 1 = Taste, Gruppe 2 = Bereichsende. */
    private const TASTE = 'F\h?([0-9]{1,3})(?![0-9\p{L}])'
        . '(?:\h*+(?:–|—|-|…|\.\.\.?|bis)\h*+F?\h?([0-9]{1,3})(?![0-9\p{L}]))?'
        . '\h*+(?:[:=|.–—]|-(?=\h))?\h*+(?!F\h?[0-9])(?=[\p{L}0-9"„“”\'‚‘’»«(]|$)';

    /** Tasten mitten im Satz zählen nicht … */
    private const ANFANG = '/' . self::STELLE . self::TASTE . '/iu';

    /** … außer in einer Tastenzeile (erste Taste am Zeilenanfang): dort trennt auch ein einzelnes Leerzeichen Spalten. */
    private const ANFANG_IN_TASTENZEILE = '/(?:' . self::STELLE . '|(?<=[\h|]))' . self::TASTE . '/iu';

    private const MOMENT = '/\h*+(?:\((?:M|Moment|Taster|Impuls)\)|\[M\])$/iu';
    private const PUNKTREIHE = '/(?:\h?\.){3,}+\h*+[0-9]*+$/u';

    private const LEER = ['frei', 'nicht belegt', 'unbelegt', 'reserviert', 'ohne funktion', 'keine funktion', 'n. b', 'n.b', '-', '–', '—'];

    private const ZERLEGT = [
        "a\u{0308}" => 'ä', "o\u{0308}" => 'ö', "u\u{0308}" => 'ü',
        "A\u{0308}" => 'Ä', "O\u{0308}" => 'Ö', "U\u{0308}" => 'Ü',
        "\u{FB01}" => 'fi', "\u{FB02}" => 'fl',
        "\u{200B}" => ' ', // Nullbreites Leerzeichen trennt Wörter
    ];

    public static function erkenne(string $text): CsvErgebnis
    {
        $zeilen = self::verbindeTrennungen(self::vorbereiten($text));

        $gefunden = [];   // Taste → Zeile
        $vergleich = [];  // Taste → Beschreibung in Kleinbuchstaben (vor dem Kürzen)
        $hinweise = [];
        $gemeldet = [];   // „Art:Taste“ → true, je Taste und Art nur ein Hinweis
        $weitere = 0;
        $hinweis = static function (string $art, int $taste, string $text) use (&$hinweise, &$gemeldet, &$weitere): void {
            if (isset($gemeldet[$art . ':' . $taste])) {
                return;
            }
            $gemeldet[$art . ':' . $taste] = true;
            if (count($hinweise) < self::MAX_HINWEISE) {
                $hinweise[] = $text;
            } else {
                $weitere++;
            }
        };

        foreach ($zeilen as $zeile) {
            foreach (self::teile($zeile) as [$taste, $bis, $roh]) {
                [$beschreibung, $schaltart] = self::saeubern($roh);
                if (self::istLeer($beschreibung)) {
                    continue;
                }
                if ($bis !== null) {
                    $hinweis('bereich', $taste, sprintf(
                        'Bereich F%d–F%d („%s“) nicht übernommen – bitte von Hand eintragen.',
                        $taste,
                        $bis,
                        self::trimme(mb_substr($beschreibung, 0, self::MAX_BESCHREIBUNG)),
                    ));
                    continue;
                }
                if ($taste > FunktionRepository::MAX_TASTE) {
                    $hinweis('grenze', $taste, sprintf('F%d liegt außerhalb von F0–F%d und wurde übersprungen.', $taste, FunktionRepository::MAX_TASTE));
                    continue;
                }
                if (isset($gefunden[$taste])) {
                    if ($vergleich[$taste] !== mb_strtolower($beschreibung)) {
                        $hinweis('doppelt', $taste, sprintf('F%d kommt mehrfach vor; übernommen wurde „%s“.', $taste, $gefunden[$taste]['beschreibung']));
                    }
                    continue;
                }
                $vergleich[$taste] = mb_strtolower($beschreibung);
                if (mb_strlen($beschreibung) > self::MAX_BESCHREIBUNG) {
                    $beschreibung = self::trimme(mb_substr($beschreibung, 0, self::MAX_BESCHREIBUNG));
                    $hinweis('lang', $taste, sprintf('Beschreibung von F%d auf %d Zeichen gekürzt.', $taste, self::MAX_BESCHREIBUNG));
                }
                $gefunden[$taste] = ['taste' => $taste, 'beschreibung' => $beschreibung, 'schaltart' => $schaltart];
            }
        }
        if ($weitere > 0) {
            $hinweise[] = sprintf('… und %d weitere Hinweise.', $weitere);
        }
        ksort($gefunden);
        return new CsvErgebnis(array_values($gefunden), [], $hinweise);
    }

    /** @return list<string> Zeilen mit einheitlichem Zeichensatz, ohne unsichtbare Zeichen, je höchstens MAX_ZEILE Zeichen */
    private static function vorbereiten(string $text): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = strtr($text, self::ZERLEGT);
        // Formatzeichen (BOM, weiches Trennzeichen …) und Steuerzeichen außer Tab und Zeilenende entfernen
        $text = (string) preg_replace('/[\p{Cf}]++|[^\P{Cc}\t\n\r]++/u', '', $text);
        $zeilen = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($zeilen as $i => $z) {
            if (strlen($z) > self::MAX_ZEILE) {
                $zeilen[$i] = mb_substr($z, 0, self::MAX_ZEILE);
            }
        }
        return $zeilen;
    }

    /**
     * Verbindet „Rangierbeleuch-“ + „tung“; nicht, wenn die nächste Zeile mit einer Taste oder groß beginnt.
     * Folgt „und“/„oder“/„bzw.“/„sowie“ („Vorder- und Rücklicht“), bleibt der Bindestrich.
     * Geprüft wird nur das jeweils letzte Stück, verbunden wird am Ende (linear).
     *
     * @param list<string> $zeilen
     * @return list<string>
     */
    private static function verbindeTrennungen(array $zeilen): array
    {
        $r = [];
        $n = count($zeilen);
        for ($i = 0; $i < $n; $i++) {
            $teile = [$zeilen[$i]];
            while ($i + 1 < $n
                && preg_match('/[a-zäöüß]-\h*+$/u', $teile[count($teile) - 1]) === 1
                && preg_match('/^\h*+\p{Ll}/u', $zeilen[$i + 1]) === 1
                && preg_match('/^\h*+f\h?[0-9]/iu', $zeilen[$i + 1]) !== 1
            ) {
                $k = count($teile) - 1;
                $naechste = (string) preg_replace('/^\h++/u', '', $zeilen[++$i]);
                if (preg_match('/^(?:und|oder|bzw\.|sowie)(?!\p{L})/u', $naechste) === 1) {
                    $teile[$k] = (string) preg_replace('/\h++$/u', '', $teile[$k]) . ' ';
                } else {
                    $teile[$k] = (string) preg_replace('/-\h*+$/u', '', $teile[$k]);
                }
                $teile[] = $naechste;
            }
            $r[] = implode('', $teile);
        }
        return $r;
    }

    /**
     * Teilt eine Zeile an jedem sicheren Tasten-Anfang (höchstens MAX_ANFAENGE_JE_ZEILE); Text davor entfällt.
     * Beginnt die Zeile mit einer Taste, zählen weitere Tasten auch nach einem einzelnen Leerzeichen.
     *
     * @return list<array{0:int,1:?int,2:string}> Taste, Bereichsende oder null, Rohtext
     */
    private static function teile(string $zeile): array
    {
        if (strlen($zeile) > self::MAX_ZEILE) {
            $zeile = mb_substr($zeile, 0, self::MAX_ZEILE); // nach dem Verbinden von Trennungen
        }
        $anfaenge = [];
        $offset = 0;
        $laenge = strlen($zeile);
        $muster = self::ANFANG;
        while ($offset <= $laenge && count($anfaenge) < self::MAX_ANFAENGE_JE_ZEILE
            && preg_match($muster, $zeile, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset) === 1
        ) {
            $ende = $m[0][1] + strlen($m[0][0]);
            if ($anfaenge === [] && $m[0][1] === 0) {
                $muster = self::ANFANG_IN_TASTENZEILE; // Tabellenzeile: Spalten auch mit einfachem Leerzeichen
            }
            $anfaenge[] = [$m[0][1], $ende, (int) $m[1][0], $m[2][0] !== null ? (int) $m[2][0] : null];
            $offset = max($ende, $m[0][1] + 1);
        }
        $teile = [];
        foreach ($anfaenge as $k => [, $start, $taste, $bis]) {
            $stopp = isset($anfaenge[$k + 1]) ? $anfaenge[$k + 1][0] : $laenge;
            $teile[] = [$taste, $bis, substr($zeile, $start, max(0, $stopp - $start))];
        }
        return $teile;
    }

    /** @return array{0:string,1:string} Beschreibung, Schaltart */
    private static function saeubern(string $roh): array
    {
        $text = self::trimme((string) preg_replace('/\h++/u', ' ', $roh));
        $text = self::trimme((string) preg_replace(self::PUNKTREIHE, '', $text));
        $schaltart = 'dauer';
        if (preg_match(self::MOMENT, $text) === 1) {
            $schaltart = 'moment';
            $text = self::trimme((string) preg_replace(self::MOMENT, '', $text));
            $text = self::trimme((string) preg_replace(self::PUNKTREIHE, '', $text));
        }
        if (preg_match('/^\(([^()]*)\)$/u', $text, $m) === 1) {
            $text = self::trimme($m[1]); // „F1 (Licht)“
        }
        return [$text, $schaltart];
    }

    private static function istLeer(string $beschreibung): bool
    {
        if (preg_match('/^[\p{N}\p{P}\p{S}\h]*+$/u', $beschreibung) === 1) {
            return true;
        }
        // „frei.“, „frei (s. u.)“, „Unbelegt:“, „n. b.“
        $kern = (string) preg_replace('/\h*+\([^()]*\)$/u', '', mb_strtolower($beschreibung));
        $kern = (string) preg_replace('/[\h.:;,!]++$/u', '', $kern);
        return in_array($kern, self::LEER, true);
    }

    /** Leerraum und senkrechte Striche (Tabellenränder) an beiden Enden entfernen. */
    private static function trimme(string $text): string
    {
        return (string) preg_replace('/^[\h|]++|[\h|]++$/u', '', $text);
    }
}
