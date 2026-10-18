<?php

declare(strict_types=1);

namespace Emc\Digital;

/** Liest Funktionsbelegungen aus CSV (auch aus deutschem Excel: Windows-1252, BOM, Semikolon). */
final class FunktionCsvImport
{
    public const VORLAGE = "\u{FEFF}Taste;Beschreibung;Schaltart\r\nF0;Licht;Dauer\r\nF1;Fahrgeräusch;Dauer\r\nF2;Pfeife;Moment\r\n";

    private const TASTE = '/^\s*F?\s*(\d{1,2})\s*$/i';
    private const MAX_BESCHREIBUNG = 100;

    public static function parse(string $inhalt): CsvErgebnis
    {
        if (str_starts_with($inhalt, "\xEF\xBB\xBF")) {
            $inhalt = substr($inhalt, 3);
        }
        if (!mb_check_encoding($inhalt, 'UTF-8')) {
            $inhalt = mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');
        }
        $zeilen = preg_split('/\r\n|\n|\r/', $inhalt) ?: [];

        $trenner = ';';
        foreach ($zeilen as $z) {
            if (trim($z) !== '') {
                $z = preg_replace('/"[^"]*"/', '', $z) ?? $z; // Zitiertes zählt nicht
                $trenner = str_contains($z, ';') ? ';' : (str_contains($z, "\t") ? "\t" : ',');
                break;
            }
        }

        $ergebnis = [];
        $fehler = [];
        $belegt = [];
        $erste = true;
        foreach ($zeilen as $index => $zeile) {
            $nr = $index + 1;
            $zellen = array_map(fn($c) => trim((string) $c), str_getcsv($zeile, $trenner, '"', ''));
            if (implode('', $zellen) === '') {
                continue;
            }
            if ($erste) {
                $erste = false;
                if (preg_match('/^\s*F?\s*\d+\s*$/i', $zellen[0]) !== 1) {
                    continue; // Kopfzeile
                }
            }
            if (preg_match(self::TASTE, $zellen[0], $m) !== 1 || (int) $m[1] > 31) {
                $fehler[] = sprintf('Zeile %d: Taste „%s“ ist ungültig (erlaubt F0–F31).', $nr, $zellen[0]);
                continue;
            }
            $taste = (int) $m[1];
            $zeilenFehler = false;
            if (isset($belegt[$taste])) {
                $fehler[] = sprintf('Zeile %d: Taste F%d kommt doppelt vor.', $nr, $taste);
                $zeilenFehler = true;
            }
            $roh = $zellen[2] ?? '';
            $schaltart = match (mb_strtolower($roh)) {
                '', 'dauer', 'd' => 'dauer',
                'moment', 'm' => 'moment',
                default => null,
            };
            if ($schaltart === null) {
                $fehler[] = sprintf('Zeile %d: Schaltart „%s“ ist unbekannt (erlaubt Dauer oder Moment).', $nr, $roh);
                $zeilenFehler = true;
            }
            $beschreibung = mb_substr($zellen[1] ?? '', 0, self::MAX_BESCHREIBUNG);
            if ($beschreibung === '') {
                $fehler[] = sprintf('Zeile %d: Beschreibung fehlt.', $nr);
                $zeilenFehler = true;
            }
            $belegt[$taste] = true;
            if (!$zeilenFehler) {
                $ergebnis[] = ['taste' => $taste, 'beschreibung' => $beschreibung, 'schaltart' => $schaltart];
            }
        }
        return new CsvErgebnis($ergebnis, $fehler);
    }
}
