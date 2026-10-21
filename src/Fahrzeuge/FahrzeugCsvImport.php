<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

/**
 * Liest eine Fahrzeugliste aus CSV (auch aus deutschem Excel: Windows-1252, BOM, Semikolon) für den einmaligen Import.
 * Die Spalten werden über die Kopfzeile zugeordnet (Reihenfolge, Groß-/Kleinschreibung und Leerzeichen egal).
 * Prüft nur die Datei selbst; ob sie schon importiert wurde, prüft FahrzeugImport.
 */
final class FahrzeugCsvImport
{
    public const VORLAGE = "\u{FEFF}Alte Nummer;Bezeichnung;Gattung;Baureihe;Bahn;Epoche;Spur;Strom;Hersteller;Artikel;Besitzer;Quelle;Notiz\r\n"
        . "A-1;V 100 rot;Lok;V 100;DR;IV;TT;2L;Beispielwerk;12345;;Bestandsliste 2020;Beispielzeile, bitte ersetzen\r\n"
        . "A-2;Personenwagen grün;Wagen;;DB;III;H0;3L;;;;;\r\n";

    public const MAX_ZEILEN = 2000;
    public const MAX_BYTES = 2097152;

    /** Spaltenschlüssel (Kopf ohne Groß-/Kleinschreibung und Leerzeichen) => Anzeigename. */
    private const SPALTEN = [
        'altenummer' => 'Alte Nummer', 'bezeichnung' => 'Bezeichnung', 'gattung' => 'Gattung', 'baureihe' => 'Baureihe',
        'bahn' => 'Bahn', 'epoche' => 'Epoche', 'spur' => 'Spur', 'strom' => 'Strom', 'hersteller' => 'Hersteller',
        'artikel' => 'Artikel', 'besitzer' => 'Besitzer', 'quelle' => 'Quelle', 'notiz' => 'Notiz',
    ];
    private const PFLICHT = ['bezeichnung', 'gattung', 'spur'];

    /** CSV-Spalte => Fahrzeugfeld mit Höchstlänge wie in migrations/001 bzw. FahrzeugValidator. */
    private const TEXTFELDER = [
        'bezeichnung' => ['bezeichnung', 150], 'baureihe' => ['baureihe', 100], 'bahn' => ['bahnverwaltung', 100],
        'hersteller' => ['hersteller', 100], 'artikel' => ['artikelnummer', 100],
    ];
    /** Nur in die Notiz: Höchstlängen, damit die zusammengesetzte Notiz sicher unter 65000 Zeichen bleibt. */
    private const NOTIZFELDER = ['altenummer' => 50, 'besitzer' => 150, 'quelle' => 250, 'notiz' => 60000];

    private const GATTUNGEN = [
        'lok' => 'lok', 'triebwagen' => 'triebwagen', 'wagen' => 'wagen', 'sonderfahrzeug' => 'sonderfahrzeug',
        'zugverband' => 'zugverband', 'zug' => 'zugverband',
    ];

    /**
     * @param array<string,int> $spurweiten Name der Spurweite => ID
     * @param list<string> $vorhandeneBezeichnungen Bezeichnungen schon vorhandener Fahrzeuge (für den Hinweis „doppelt“)
     */
    public static function parse(string $inhalt, array $spurweiten, array $vorhandeneBezeichnungen = []): FahrzeugImportErgebnis
    {
        if (strlen($inhalt) > self::MAX_BYTES) {
            return new FahrzeugImportErgebnis([], ['Die Datei ist zu groß (höchstens ' . intdiv(self::MAX_BYTES, 1048576) . ' MB).']);
        }
        if (str_starts_with($inhalt, "\xEF\xBB\xBF")) {
            $inhalt = substr($inhalt, 3);
        }
        if (!mb_check_encoding($inhalt, 'UTF-8')) {
            $inhalt = mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');
        }
        $zeilen = preg_split('/\r\n|\n|\r/', $inhalt) ?: [];

        $kopfIndex = null;
        foreach ($zeilen as $i => $z) {
            if (trim($z) !== '') {
                $kopfIndex = $i;
                break;
            }
        }
        if ($kopfIndex === null) {
            return new FahrzeugImportErgebnis([], ['Die Datei enthält keine Fahrzeuge.']);
        }
        $ohneZitate = preg_replace('/"[^"]*"/', '', $zeilen[$kopfIndex]) ?? $zeilen[$kopfIndex];
        $trenner = str_contains($ohneZitate, ';') ? ';' : (str_contains($ohneZitate, "\t") ? "\t" : ',');

        // Kopfzeile: Spaltenschlüssel => Index
        $spalte = [];
        foreach (self::zellen($zeilen[$kopfIndex], $trenner) as $index => $name) {
            $schluessel = mb_strtolower(preg_replace('/\s+/u', '', $name) ?? $name);
            if (!isset(self::SPALTEN[$schluessel])) {
                continue; // unbekannte Spalten werden ignoriert
            }
            if (isset($spalte[$schluessel])) {
                return new FahrzeugImportErgebnis([], ['Die Spalte „' . self::SPALTEN[$schluessel] . '“ steht mehrfach in der Kopfzeile.']);
            }
            $spalte[$schluessel] = $index;
        }
        $fehlend = array_values(array_filter(self::PFLICHT, fn(string $s): bool => !isset($spalte[$s])));
        if ($fehlend !== []) {
            return new FahrzeugImportErgebnis([], array_map(fn(string $s): string => 'In der Kopfzeile fehlt die Spalte „' . self::SPALTEN[$s] . '“.', $fehlend));
        }

        $spurNachName = [];
        foreach ($spurweiten as $name => $id) {
            $spurNachName[mb_strtolower(trim((string) $name))] = $id;
        }

        $ergebnis = [];
        foreach ($zeilen as $index => $zeile) {
            if ($index <= $kopfIndex) {
                continue;
            }
            $zellen = self::zellen($zeile, $trenner);
            if (implode('', $zellen) === '') {
                continue;
            }
            if (count($ergebnis) >= self::MAX_ZEILEN) {
                return new FahrzeugImportErgebnis([], ['Die Datei hat mehr als ' . self::MAX_ZEILEN . ' Fahrzeugzeilen. Bitte aufteilen.']);
            }
            $wert = fn(string $s): string => isset($spalte[$s]) ? ($zellen[$spalte[$s]] ?? '') : '';
            $ergebnis[] = self::zeile($index + 1, $wert, $spurweiten, $spurNachName);
        }
        if ($ergebnis === []) {
            return new FahrzeugImportErgebnis([], ['Die Datei enthält keine Fahrzeuge.']);
        }
        return new FahrzeugImportErgebnis(self::markiereDoppelte($ergebnis, $vorhandeneBezeichnungen), []);
    }

    /**
     * Notiz des neuen Fahrzeugs: Notiz-Text, Leerzeile, Herkunft des Imports.
     *
     * @param array{alte_nummer:string,besitzer:string,quelle:string,notiz:string} $zeile
     */
    public static function notiz(array $zeile, string $dateiname, \DateTimeImmutable $datum): string
    {
        $satz = fn(string $t): string => rtrim($t, '. ');
        $herkunft = 'Import vom ' . $datum->format('d.m.Y') . ' aus „' . $dateiname . '“';
        $herkunft .= $zeile['alte_nummer'] !== '' ? ', alte Nummer ' . $satz($zeile['alte_nummer']) . '.' : '.';
        if ($zeile['besitzer'] !== '') {
            $herkunft .= ' Besitzer laut Liste: ' . $satz($zeile['besitzer']) . '.';
        }
        if ($zeile['quelle'] !== '') {
            $herkunft .= ' Quelle: ' . $satz($zeile['quelle']) . '.';
        }
        return $zeile['notiz'] === '' ? $herkunft : $zeile['notiz'] . "\n\n" . $herkunft;
    }

    /** Dateiname für Notiz und Importliste: ohne Pfad, Steuer- und Sonderzeichen, höchstens 100 Zeichen. */
    public static function dateiname(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\p{C}]+/u', '', $name) ?? '';
        $name = preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', $name) ?? '';
        $name = mb_substr(trim($name, " ."), 0, 100);
        return $name === '' ? 'import.csv' : $name;
    }

    /** @return list<string> */
    private static function zellen(string $zeile, string $trenner): array
    {
        return array_map(fn($c) => trim((string) $c), str_getcsv($zeile, $trenner, '"', ''));
    }

    /**
     * @param callable(string):string $wert
     * @param array<string,int> $spurweiten
     * @param array<string,int> $spurNachName
     * @return array<string,mixed>
     */
    private static function zeile(int $nr, callable $wert, array $spurweiten, array $spurNachName): array
    {
        $fehler = [];
        $gattungRoh = $wert('gattung');
        $gattung = self::GATTUNGEN[mb_strtolower($gattungRoh)] ?? null;
        $basis = [
            'nr' => $nr,
            'alte_nummer' => $wert('altenummer'),
            'bezeichnung' => $wert('bezeichnung'),
            'gattung_text' => $gattungRoh,
            'spur' => $wert('spur'),
            'besitzer' => $wert('besitzer'),
            'quelle' => $wert('quelle'),
            'notiz' => $wert('notiz'),
            'doppelt' => false,
            'daten' => [],
        ];
        if ($gattung === 'zugverband') {
            return $basis + ['status' => 'zugverband', 'fehler' => []];
        }

        $daten = [];
        foreach (self::TEXTFELDER as $csv => [$feld, $max]) {
            $w = $wert($csv);
            if (mb_strlen($w) > $max) {
                $fehler[] = self::SPALTEN[$csv] . ': höchstens ' . $max . ' Zeichen.';
            }
            $daten[$feld] = $w === '' ? null : $w;
        }
        if ($daten['bezeichnung'] === null) {
            array_unshift($fehler, 'Bezeichnung fehlt.');
        }
        if ($gattungRoh === '') {
            $fehler[] = 'Gattung fehlt.';
        } elseif ($gattung === null) {
            $fehler[] = 'Gattung „' . $gattungRoh . '“ ist unbekannt (erlaubt: Lok, Triebwagen, Wagen, Sonderfahrzeug, Zugverband).';
        }
        $spur = $wert('spur');
        $spurId = $spurNachName[mb_strtolower($spur)] ?? null;
        if ($spur === '') {
            $fehler[] = 'Spur fehlt.';
        } elseif ($spurId === null) {
            $fehler[] = 'Spur „' . $spur . '“ ist unbekannt (vorhanden: ' . implode(', ', array_map('strval', array_keys($spurweiten))) . ').';
        }
        $epoche = mb_strtoupper($wert('epoche'));
        if ($epoche !== '' && !in_array($epoche, FahrzeugValidator::EPOCHEN, true)) {
            $fehler[] = 'Epoche „' . $wert('epoche') . '“ ist ungültig (erlaubt I bis VI).';
        }
        $strom = mb_strtoupper($wert('strom'));
        if ($strom !== '' && !in_array($strom, FahrzeugValidator::STROMSYSTEME, true)) {
            $fehler[] = 'Strom „' . $wert('strom') . '“ ist unbekannt (erlaubt 2L, 3L oder leer).';
        }
        foreach (self::NOTIZFELDER as $csv => $max) {
            if (mb_strlen($wert($csv)) > $max) {
                $fehler[] = self::SPALTEN[$csv] . ': höchstens ' . $max . ' Zeichen.';
            }
        }

        $daten = [
            'bezeichnung' => $daten['bezeichnung'],
            'baureihe' => $daten['baureihe'],
            'bahnverwaltung' => $daten['bahnverwaltung'],
            'epoche' => $epoche === '' ? null : $epoche,
            'gattung' => $gattung,
            'hersteller' => $daten['hersteller'],
            'artikelnummer' => $daten['artikelnummer'],
            'spurweite_id' => $spurId,
            'stromsystem' => $strom === '' ? '2L' : $strom,
        ];
        if ($fehler === []) {
            // Gleiche Regeln wie im Formular, damit ein importiertes Fahrzeug später unverändert speicherbar ist.
            $pruefung = FahrzeugValidator::pruefe($daten + ['status' => 'einsatzbereit', 'betriebsstunden' => 0.0, 'notiz' => null]);
            $fehler = array_values($pruefung);
        }
        return ['status' => $fehler === [] ? 'anlegen' : 'fehler', 'fehler' => $fehler, 'daten' => $daten] + $basis;
    }

    /**
     * Markiert Zeilen, deren Bezeichnung (ohne Groß-/Kleinschreibung und Randleerzeichen) mehrfach in der Datei oder
     * schon im Bestand vorkommt. Zugverbände zählen nicht mit, sie werden nicht angelegt.
     *
     * @param list<array<string,mixed>> $zeilen
     * @param list<string> $vorhandene
     * @return list<array<string,mixed>>
     */
    private static function markiereDoppelte(array $zeilen, array $vorhandene): array
    {
        $schluessel = fn(string $b): string => mb_strtolower(trim($b));
        $bestand = array_fill_keys(array_map($schluessel, $vorhandene), true);
        $anzahl = [];
        foreach ($zeilen as $z) {
            if ($z['status'] !== 'zugverband' && $z['bezeichnung'] !== '') {
                $k = $schluessel($z['bezeichnung']);
                $anzahl[$k] = ($anzahl[$k] ?? 0) + 1;
            }
        }
        foreach ($zeilen as $i => $z) {
            if ($z['status'] === 'zugverband' || $z['bezeichnung'] === '') {
                continue;
            }
            $k = $schluessel($z['bezeichnung']);
            $zeilen[$i]['doppelt'] = $anzahl[$k] > 1 || isset($bestand[$k]);
        }
        return $zeilen;
    }
}
