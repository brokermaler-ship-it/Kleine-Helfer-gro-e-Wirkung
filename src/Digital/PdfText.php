<?php

declare(strict_types=1);

namespace Emc\Digital;

use Emc\Core\DomainFehler;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\PDFObject;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\RawData\FilterHelper;

/**
 * Liest den Text eines PDFs aus dem Speicher (die Datei wird nie abgelegt), mit festen Grenzen für Größe, Seiten,
 * Textmenge, Entpacken und Laufzeit. Jeder Fehler der Bibliothek wird zu einem DomainFehler mit deutscher Meldung.
 */
final class PdfText
{
    public const MAX_BYTES = 20 * 1048576;
    public const MAX_SEITEN = 150;
    public const MAX_TEXT = 2 * 1048576;
    /** Höchstens so viel entpackt die Bibliothek aus einem einzelnen Datenstrom. */
    public const DECODE_LIMIT = 32 * 1048576;
    /**
     * Höchstens so viel dürfen alle Datenströme (ohne Bilder) zusammen entpackt ergeben. Die Bibliothek entpackt beim
     * Einlesen alle Ströme auf einmal; ohne diese Summe könnte ein kleines PDF mit vielen „Zip-Bomben“ den Speicher sprengen.
     */
    public const DECODE_GESAMT = 96 * 1048576;
    /** Seiten mit mehr Inhalt (entpackt) werden übersprungen; die Textauswertung braucht ein Vielfaches davon an Speicher. */
    public const MAX_SEITENINHALT = 4 * 1048576;
    /** Speicher, den das Auslesen mindestens bekommt (falls der Webspace erlaubt, ihn anzuheben). */
    private const SPEICHER = 512 * 1048576;
    /** Kürzester sinnvoller Text (ohne Leerraum); darunter gilt das PDF als Scan. */
    private const MIN_TEXT = 20;

    public function __construct(
        public readonly int $maxBytes = self::MAX_BYTES,
        public readonly int $maxSeiten = self::MAX_SEITEN,
        private int $zeitSekunden = 30,
    ) {
    }

    /** Einstellungen der Bibliothek: keine Bilddaten behalten, Entpacken je Datenstrom begrenzt. */
    public static function bibliotheksConfig(): Config
    {
        $config = new Config();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(self::DECODE_LIMIT);
        return $config;
    }

    /**
     * @return array{text:string, seiten:int, gelesen:int}
     * @throws DomainFehler
     */
    public function lies(string $inhalt): array
    {
        if (strlen($inhalt) > $this->maxBytes) {
            throw new DomainFehler(sprintf('Die Datei ist zu groß (höchstens %d MB).', max(1, intdiv($this->maxBytes, 1048576))));
        }
        if (!str_contains(substr($inhalt, 0, 1024), '%PDF-')) {
            throw new DomainFehler('Die Datei ist kein PDF.');
        }
        // Grenzen nur für das Auslesen; danach gilt wieder, was vorher eingestellt war (im CLI z. B. „unbegrenzt“).
        $zeitVorher = (int) ini_get('max_execution_time');
        $speicherVorher = (string) ini_get('memory_limit');
        @set_time_limit($this->zeitSekunden);
        self::speicherAnheben();
        try {
            return $this->auslesen($inhalt);
        } finally {
            @set_time_limit($zeitVorher);
            @ini_set('memory_limit', $speicherVorher);
        }
    }

    /**
     * @return array{text:string, seiten:int, gelesen:int}
     * @throws DomainFehler
     */
    private function auslesen(string $inhalt): array
    {
        if (self::entpackteGroesse($inhalt, self::DECODE_GESAMT) > self::DECODE_GESAMT) {
            error_log('PDF-Import: entpackte Datenströme zusammen über ' . self::DECODE_GESAMT . ' Bytes, abgelehnt.');
            throw new DomainFehler('Das PDF konnte nicht gelesen werden.');
        }

        try {
            $dokument = (new Parser([], self::bibliotheksConfig()))->parseContent($inhalt);
            $seiten = array_values($dokument->getPages());
        } catch (\Throwable $t) {
            self::protokolliere($t);
            $meldung = strtolower($t->getMessage());
            if (str_contains($meldung, 'secured') || str_contains($meldung, 'encrypt')) {
                throw new DomainFehler('Das PDF ist geschützt (verschlüsselt) und kann nicht gelesen werden.');
            }
            throw new DomainFehler('Das PDF konnte nicht gelesen werden.');
        }

        $texte = [];
        $laenge = 0;
        $gelesen = 0;
        foreach (array_slice($seiten, 0, max(0, $this->maxSeiten)) as $seite) {
            if ($laenge >= self::MAX_TEXT) {
                break;
            }
            $gelesen++;
            try {
                if (self::seiteninhalt($seite) > self::MAX_SEITENINHALT) {
                    error_log('PDF-Import: Seite ' . $gelesen . ' mit zu großem Inhalt übersprungen.');
                    continue;
                }
                $text = (string) $seite->getText();
            } catch (\Throwable $t) {
                self::protokolliere($t);
                continue;
            }
            $rest = self::MAX_TEXT - $laenge;
            if (strlen($text) > $rest) {
                $text = mb_strcut($text, 0, $rest, 'UTF-8');
            }
            $texte[] = $text;
            $laenge += strlen($text) + 1;
        }
        $text = implode("\n", $texte);

        $ohneLeerraum = preg_replace('/\s+/u', '', $text) ?? (string) preg_replace('/\s+/', '', $text);
        if (mb_strlen($ohneLeerraum, 'UTF-8') < self::MIN_TEXT) {
            throw new DomainFehler('Das PDF enthält keinen lesbaren Text (vermutlich ein Scan). Texterkennung gibt es nicht – bitte die Tasten von Hand eintragen.');
        }
        return ['text' => $text, 'seiten' => count($seiten), 'gelesen' => $gelesen];
    }

    /**
     * Schätzt, wie groß alle Datenströme außer Bildern entpackt zusammen werden (wie die Bibliothek sie entpacken würde,
     * je Strom höchstens DECODE_LIMIT). Zählt nur bis knapp über $grenze und braucht dabei kaum Speicher.
     */
    public static function entpackteGroesse(string $pdf, int $grenze): int
    {
        $summe = 0;
        $pos = 0;
        $laenge = strlen($pdf);
        while ($summe <= $grenze && preg_match('/stream\r?\n/', $pdf, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
            $start = $m[0][1] + strlen($m[0][0]);
            $pos = $start;
            if ($m[0][1] >= 3 && substr($pdf, $m[0][1] - 3, 3) === 'end') {
                continue; // „endstream“
            }
            // Das Wörterbuch steht zwischen dem letzten „obj“ und „stream“.
            $kopfStart = max(0, $m[0][1] - 4096);
            $kopf = substr($pdf, $kopfStart, $m[0][1] - $kopfStart);
            $obj = strrpos($kopf, 'obj');
            $kopf = $obj === false ? $kopf : substr($kopf, $obj);
            // Wie die Bibliothek: „endstream“ erst hinter der angegebenen Länge suchen.
            $angegeben = preg_match('#/Length\s+(\d{1,10})(?!\s+\d+\s+R)#', $kopf, $l) === 1 ? (int) $l[1] : 0;
            $ende = strpos($pdf, 'endstream', min($laenge, $start + $angegeben));
            if ($ende !== false) {
                $pos = $ende + 9;
            }
            if (preg_match('#/Type\s*/XObject\b#', $kopf) === 1 && preg_match('#/Subtype\s*/Image\b#', $kopf) === 1) {
                continue; // Bilder verwirft die Bibliothek ungelesen (setRetainImageContent(false)).
            }
            $roh = ($ende === false ? $laenge : $ende) - $start;
            $summe += self::stromGroesse($pdf, $start, $roh, $kopf, min(self::DECODE_LIMIT, $grenze - $summe + 1));
        }
        return $summe;
    }

    /**
     * Entpackte Größe eines Stroms nach seiner Filterkette (nur die Filter, die die Bibliothek selbst entpackt).
     * Wo sich das nicht billig nachrechnen lässt, wird mit der Höchstgröße gerechnet.
     */
    private static function stromGroesse(string $pdf, int $start, int $roh, string $kopf, int $grenze): int
    {
        if (preg_match('#/Filter\s*(\[[^\]]*\]|/[A-Za-z0-9]+|\d+\s+\d+\s+R)#', $kopf, $f) !== 1) {
            return min($roh, $grenze);
        }
        if (!str_contains($f[1], '/')) {
            return $grenze; // Filter als Verweis: nicht nachrechenbar.
        }
        preg_match_all('#/([A-Za-z0-9]+)#', $f[1], $namen);
        $filter = $namen[1];
        $daten = null;
        foreach ($filter as $n => $name) {
            $letzter = $n === count($filter) - 1;
            if ($name === 'FlateDecode' && $letzter) {
                return $daten === null ? self::inflateGroesse($pdf, $start, $roh, $grenze)
                    : self::inflateGroesse($daten, 0, strlen($daten), $grenze);
            }
            if ($name === 'LZWDecode' || $name === 'RunLengthDecode' || $name === 'FlateDecode') {
                return $grenze; // selten (oder Flate mitten in der Kette): vorsichtig mit der Höchstgröße rechnen
            }
            if ($name === 'ASCIIHexDecode' || $name === 'ASCII85Decode') {
                try {
                    $daten = (new FilterHelper())->decodeFilter($name, $daten ?? substr($pdf, $start, $roh));
                } catch (\Throwable) {
                    return $grenze;
                }
            }
        }
        return min($grenze, $daten === null ? $roh : strlen($daten));
    }

    /**
     * Entpackte Länge eines Deflate-Stroms, stückweise gezählt bis höchstens $grenze. Wie die Bibliothek erst mit
     * zlib-Kopf, dann ohne (roh) versucht.
     */
    private static function inflateGroesse(string $daten, int $start, int $roh, int $grenze): int
    {
        $summe = self::inflateZaehlen($daten, $start, $roh, $grenze, ZLIB_ENCODING_DEFLATE);
        return $summe > 0 ? $summe : self::inflateZaehlen($daten, $start, $roh, $grenze, ZLIB_ENCODING_RAW);
    }

    private static function inflateZaehlen(string $daten, int $start, int $roh, int $grenze, int $art): int
    {
        $ctx = @inflate_init($art);
        if ($ctx === false) {
            return 0;
        }
        $summe = 0;
        // Je 4 KB Eingabe höchstens rund 4 MB Ausgabe (Deflate packt höchstens etwa 1:1032).
        for ($o = 0; $o < $roh && $summe < $grenze; $o += 4096) {
            $teil = @inflate_add($ctx, substr($daten, $start + $o, min(4096, $roh - $o)), ZLIB_SYNC_FLUSH);
            if ($teil === false) {
                break;
            }
            $summe += strlen($teil);
            unset($teil);
            if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                break;
            }
        }
        return min($summe, $grenze);
    }

    /** Entpackte Größe der Inhaltsströme einer Seite (ohne eingebettete Formulare). */
    private static function seiteninhalt(PDFObject $seite): int
    {
        $inhalt = $seite->get('Contents');
        $teile = $inhalt instanceof ElementArray ? $inhalt->getContent() : [$inhalt];
        $summe = 0;
        foreach (is_array($teile) ? $teile : [] as $teil) {
            if ($teil instanceof PDFObject) {
                $summe += strlen((string) $teil->getContent());
            }
        }
        return $summe;
    }

    private static function speicherAnheben(): void
    {
        $roh = trim((string) ini_get('memory_limit'));
        if ($roh === '' || $roh === '-1') {
            return;
        }
        $zahl = (int) $roh;
        $bytes = match (strtolower(substr($roh, -1))) {
            'g' => $zahl * 1073741824,
            'm' => $zahl * 1048576,
            'k' => $zahl * 1024,
            default => $zahl,
        };
        if ($bytes > 0 && $bytes < self::SPEICHER) {
            @ini_set('memory_limit', (string) self::SPEICHER);
        }
    }

    private static function protokolliere(\Throwable $t): void
    {
        // Nur Klasse und (gekürzte) Meldung, nie Inhalt der Datei.
        $meldung = (string) preg_replace('/[^\x20-\x7E]/', '?', mb_strcut($t->getMessage(), 0, 200));
        error_log('PDF-Import: ' . $t::class . ': ' . $meldung);
    }
}
