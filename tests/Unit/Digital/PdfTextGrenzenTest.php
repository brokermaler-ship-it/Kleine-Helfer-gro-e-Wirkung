<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Digital;

use Emc\Core\DomainFehler;
use Emc\Digital\PdfText;
use Emc\Tests\Support\MiniPdf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Bösartige PDFs (Review Task 2): jede Variante endet mit einer festen Meldung statt mit einem Fatal Error.
 * Fälle, die ohne Schutz den Speicher sprengen würden, laufen in eigenen Prozessen mit fester Speichergrenze.
 */
final class PdfTextGrenzenTest extends TestCase
{
    private const SCAN = 'Das PDF enthält keinen lesbaren Text (vermutlich ein Scan). Texterkennung gibt es nicht – bitte die Tasten von Hand eintragen.';
    private const NICHT_LESBAR = 'Das PDF konnte nicht gelesen werden.';

    protected function setUp(): void
    {
        ini_set('error_log', '/dev/null');
    }

    private function fehler(PdfText $pdf, string $inhalt): string
    {
        try {
            $pdf->lies($inhalt);
        } catch (DomainFehler $f) {
            return $f->getMessage();
        }
        $this->fail('DomainFehler erwartet');
    }

    /** 30 Ströme mit je 32 MB Nullen in der angegebenen Schreibweise, dazu eine Textseite. */
    private static function bomben(string $variante): string
    {
        $nullen = str_repeat("\0", 32 * 1048576);
        $zlib = (string) gzcompress($nullen, 9);
        $roh = 'xx' . (string) gzdeflate($nullen, 9);
        unset($nullen);
        $objekte = MiniPdf::seitenObjekte(MiniPdf::text(['F0 Licht vorne und hinten']));
        for ($i = 0; $i < 30; $i++) {
            $objekte[10 + $i] = match ($variante) {
                'A' => '<< /Length ' . strlen($zlib) . " /Filter /FlateDecode >>\nstream\n" . $zlib . "\nendstream",
                'B' => '<< /Length ' . strlen($zlib) . " /Filter /FlateDecode >>\nstream \n" . $zlib . "\nendstream",
                'C' => '<< /Filter /FlateDecode /X (obj) /Length ' . strlen($zlib) . " >>\nstream\n" . $zlib . "\nendstream",
                'D' => '<< /Length ' . strlen($zlib) . " /Filter /FlateDecode /Foo << /Type /XObject /Subtype /Image >> >>\nstream\n" . $zlib . "\nendstream",
                'F' => '<< /Length ' . strlen($roh) . " /Filter /FlateDecode >>\nstream\n" . $roh . "\nendstream",
                'G' => '<< /Filter /FlateDecode /Pad [' . str_repeat('0 ', 2500) . '] /Length ' . strlen($zlib) . " >>\nstream\n" . $zlib . "\nendstream",
            };
        }
        return MiniPdf::ausObjekten($objekte);
    }

    /** @return array<string,array{string}> */
    public static function bombenVarianten(): array
    {
        return ['A' => ['A'], 'B Leerzeichen vor LF' => ['B'], 'C „obj“ im String' => ['C'], 'D Bild-Wörterbuch verschachtelt' => ['D'],
            'F roh mit 2 Bytes davor' => ['F'], 'G langes Wörterbuch' => ['G']];
    }

    #[DataProvider('bombenVarianten')]
    #[RunInSeparateProcess]
    public function test_viele_bomben_werden_beim_entpacken_gestoppt(string $variante): void
    {
        ini_set('memory_limit', '512M');
        $pdf = self::bomben($variante);
        $this->assertLessThan(2 * 1048576, strlen($pdf));
        gc_collect_cycles();
        $vorher = memory_get_usage();
        memory_reset_peak_usage();
        $this->assertSame(self::NICHT_LESBAR, $this->fehler(new PdfText(), $pdf));
        // Höchstens das Budget plus ein Strom und etwas Verwaltung.
        $this->assertLessThan(PdfText::DECODE_GESAMT + PdfText::DECODE_LIMIT + 64 * 1048576, memory_get_peak_usage() - $vorher);
    }

    public function test_entpacken_wird_gezaehlt_wo_die_bibliothek_entpackt(): void
    {
        // 2 Ströme à 32 MB liegen unter dem Budget: gelesen, Speicher bleibt im Rahmen.
        $zlib = (string) gzcompress(str_repeat("\0", 32 * 1048576), 9);
        $objekte = MiniPdf::seitenObjekte(MiniPdf::text(['F0 Licht vorne und hinten']));
        $objekte[10] = $objekte[11] = '<< /Length ' . strlen($zlib) . " /Filter /FlateDecode >>\nstream\n" . $zlib . "\nendstream";
        $e = (new PdfText())->lies(MiniPdf::ausObjekten($objekte));
        $this->assertStringContainsString('F0 Licht', $e['text']);
    }

    public function test_lzw_wird_mit_echter_groesse_gezaehlt(): void
    {
        // Mehrere kleine LZW-Ströme (alte PDFs) dürfen nicht pauschal als 32 MB zählen.
        $lzw = "\x80\x0B\x60\x50\x22\x0C\x0C\x85\x01"; // LZW-Beispiel der PDF-Referenz („-----A---B“)
        $objekte = MiniPdf::seitenObjekte(MiniPdf::text(['F0 Licht vorne und hinten']));
        for ($i = 0; $i < 10; $i++) {
            $objekte[10 + $i] = '<< /Length ' . strlen($lzw) . " /Filter /LZWDecode >>\nstream\n" . $lzw . "\nendstream";
        }
        $e = (new PdfText())->lies(MiniPdf::ausObjekten($objekte));
        $this->assertStringContainsString('F0 Licht', $e['text']);
    }

    public function test_stream_ohne_endstream_linear(): void
    {
        $pdf = "%PDF-1.4\n" . str_repeat("1 0 obj<</Filter/FlateDecode>>stream\n", 80000);
        $start = microtime(true);
        $meldung = $this->fehler(new PdfText(), $pdf);
        $this->assertContains($meldung, [self::NICHT_LESBAR, self::SCAN]);
        $this->assertLessThan(10, microtime(true) - $start);
    }

    /** Seite 4 ruft /X1 (Objekt 6) auf; @param array<int,string> $formulare ab Objekt 6 */
    private static function mitFormularen(array $formulare): string
    {
        $objekte = MiniPdf::seitenObjekte("q /X1 Do Q\n", ' /XObject << /X1 6 0 R >>');
        return MiniPdf::ausObjekten($objekte + $formulare);
    }

    private static function formular(string $inhalt, string $xobjekte = ''): string
    {
        $res = '/Resources << /Font << /F1 3 0 R >>' . ($xobjekte !== '' ? ' /XObject << ' . $xobjekte . ' >>' : '') . ' >>';
        return MiniPdf::strom($inhalt, ' /Type /XObject /Subtype /Form /BBox [0 0 595 842] ' . $res);
    }

    #[RunInSeparateProcess]
    public function test_grosses_formular_seite_uebersprungen(): void
    {
        ini_set('memory_limit', '512M');
        $text = "BT /F1 11 Tf 50 800 Td\n" . str_repeat("(ab) Tj\n", 30 * 1048576 / 8) . "ET\n";
        $gepackt = (string) gzcompress($text, 9);
        unset($text);
        $form = '<< /Length ' . strlen($gepackt) . ' /Filter /FlateDecode /Type /XObject /Subtype /Form /BBox [0 0 595 842]'
            . " /Resources << /Font << /F1 3 0 R >> >> >>\nstream\n" . $gepackt . "\nendstream";
        $pdf = self::mitFormularen([6 => $form]);
        $this->assertLessThan(200000, strlen($pdf));
        $this->assertSame(self::SCAN, $this->fehler(new PdfText(), $pdf));
    }

    #[RunInSeparateProcess]
    public function test_zyklisches_formular(): void
    {
        ini_set('memory_limit', '512M');
        $pdf = self::mitFormularen([6 => self::formular("BT /F1 11 Tf 50 800 Td (F0 Licht vorne und hinten) Tj ET\n/X1 Do\n", '/X1 6 0 R')]);
        $this->assertLessThan(2000, strlen($pdf));
        $this->assertSame(self::SCAN, $this->fehler(new PdfText(), $pdf));
    }

    #[RunInSeparateProcess]
    public function test_formulare_rufen_sich_gegenseitig_auf(): void
    {
        ini_set('memory_limit', '512M');
        $pdf = self::mitFormularen([
            6 => self::formular("BT /F1 11 Tf 50 800 Td (F0 Licht vorne und hinten) Tj ET\n/X2 Do\n", '/X2 7 0 R'),
            7 => self::formular("BT /F1 11 Tf 50 700 Td (F1 Sound) Tj ET\n/X1 Do\n", '/X1 6 0 R'),
        ]);
        $this->assertSame(self::SCAN, $this->fehler(new PdfText(), $pdf));
    }

    #[RunInSeparateProcess]
    public function test_formular_baum_vervielfacht_sich(): void
    {
        // Jede Ebene ruft die nächste 40-mal auf: 40^4 Aufrufe aus wenigen Bytes.
        ini_set('memory_limit', '512M');
        $formulare = [];
        for ($i = 0; $i < 4; $i++) {
            $formulare[6 + $i] = self::formular(str_repeat('/X' . ($i + 2) . " Do\n", 40), '/X' . ($i + 2) . ' ' . (7 + $i) . ' 0 R');
        }
        $formulare[10] = self::formular("BT /F1 11 Tf 50 800 Td (F0 Licht vorne und hinten) Tj ET\n");
        $this->assertSame(self::SCAN, $this->fehler(new PdfText(), self::mitFormularen($formulare)));
    }

    public function test_formular_mit_text_wird_gelesen(): void
    {
        $pdf = self::mitFormularen([6 => self::formular("BT /F1 11 Tf 50 800 Td (F0 Licht vorne und hinten) Tj ET\n")]);
        $this->assertStringContainsString('F0 Licht', (new PdfText())->lies($pdf)['text']);
    }

    #[RunInSeparateProcess]
    public function test_speichergrenze_ohne_anheben_begrenzt_dateigroesse(): void
    {
        ini_set('memory_limit', '128M');
        $pdf = new PdfText(speicherAnheben: false);
        $this->assertSame((int) (128 * 1048576 / 25), $pdf->wirksameMaxBytes());
        $inhalt = MiniPdf::erzeuge([['F0 Licht vorne und hinten']]) . '%' . str_repeat('x', 6 * 1048576);
        $this->assertSame('Die Datei ist zu groß für diesen Webspace (höchstens 5 MB).', $this->fehler($pdf, $inhalt));
        $this->assertSame('128M', ini_get('memory_limit'));
    }

    #[RunInSeparateProcess]
    public function test_speichergrenze_angehoben(): void
    {
        ini_set('memory_limit', '128M');
        $this->assertSame(PdfText::MAX_BYTES, (new PdfText())->wirksameMaxBytes());
        $this->assertSame('128M', ini_get('memory_limit'));
        $e = (new PdfText())->lies(MiniPdf::erzeuge([['F0 Licht vorne und hinten']]));
        $this->assertSame(1, $e['seiten']);
        $this->assertSame('128M', ini_get('memory_limit'));
    }

    #[RunInSeparateProcess]
    public function test_sehr_viele_objekte_vorab_abgelehnt(): void
    {
        ini_set('memory_limit', '512M');
        $objekte = MiniPdf::seitenObjekte(MiniPdf::text(['F0 Licht vorne und hinten']));
        for ($i = 10; $i < 300010; $i++) {
            $objekte[$i] = '[1 2 3 4 5 6 7 8]';
        }
        $pdf = MiniPdf::ausObjekten($objekte);
        unset($objekte);
        $start = microtime(true);
        $this->assertSame(self::NICHT_LESBAR, $this->fehler(new PdfText(), $pdf));
        $this->assertLessThan(5, microtime(true) - $start);
    }

    #[RunInSeparateProcess]
    public function test_sehr_viele_objekte_im_objektstrom(): void
    {
        ini_set('memory_limit', '512M');
        $n = 250000;
        $paare = [];
        $koerper = '';
        for ($i = 0; $i < $n; $i++) {
            $paare[] = (100 + $i) . ' ' . strlen($koerper);
            $koerper .= '[1 2] ';
        }
        $kopf = implode(' ', $paare) . ' ';
        $daten = (string) gzcompress($kopf . $koerper, 9);
        $objekte = MiniPdf::seitenObjekte(MiniPdf::text(['F0 Licht vorne und hinten']));
        $objekte[10] = '<< /Type /ObjStm /N ' . $n . ' /First ' . strlen($kopf) . ' /Length ' . strlen($daten)
            . " /Filter /FlateDecode >>\nstream\n" . $daten . "\nendstream";
        $pdf = MiniPdf::ausObjekten($objekte);
        $this->assertSame(self::NICHT_LESBAR, $this->fehler(new PdfText(), $pdf));
    }

    public function test_ohne_objekte_nicht_als_verschluesselt_gemeldet(): void
    {
        $pdf = "%PDF-1.4\nxref\n0 1\n0000000000 65535 f \ntrailer\n<< /Size 1 >>\nstartxref\n9\n%%EOF\n";
        $this->assertSame(self::NICHT_LESBAR, $this->fehler(new PdfText(), $pdf));
    }

    public function test_notfallseite_nur_bei_fatalem_fehler_waehrend_des_auslesens(): void
    {
        $speicher = ['type' => E_ERROR, 'message' => 'Allowed memory size of 1 bytes exhausted', 'file' => 'x', 'line' => 1];
        $this->assertNull(PdfText::notfallSeite($speicher, false));
        $this->assertNull(PdfText::notfallSeite(null, true));
        $this->assertNull(PdfText::notfallSeite(['type' => E_WARNING, 'message' => 'x', 'file' => 'x', 'line' => 1], true));
        $html = PdfText::notfallSeite($speicher, true);
        $this->assertNotNull($html);
        $this->assertStringContainsString('Das PDF ist zu groß oder zu aufwendig zum Auslesen.', $html);
        $this->assertStringNotContainsString('Allowed memory', $html);
    }

    public function test_notfallseite_bei_echtem_zeitablauf(): void
    {
        // Eigener PHP-Prozess: 1 Sekunde Laufzeit reicht für 20 000 Seiten nicht; statt weißer Seite kommt die Notfallseite.
        $skript = tempnam(sys_get_temp_dir(), 'pdf') . '.php';
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        file_put_contents($skript, '<?php require ' . var_export($autoload, true) . ';'
            . '$s = array_fill(0, 20000, ["Allgemeine Hinweise zum Betrieb der Lok"]);'
            . '$pdf = Emc\Tests\Support\MiniPdf::erzeuge($s);'
            . 'ini_set("error_log", "/dev/null"); ini_set("display_errors", "0");'
            . '(new Emc\Digital\PdfText(zeitSekunden: 1))->lies($pdf); echo "FERTIG";');
        try {
            $ausgabe = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg($skript) . ' 2>/dev/null');
        } finally {
            @unlink($skript);
            @unlink(substr($skript, 0, -4));
        }
        $this->assertStringNotContainsString('FERTIG', $ausgabe, 'Testaufbau: Auslesen war schneller als 1 s');
        $this->assertStringContainsString('Das PDF ist zu groß oder zu aufwendig zum Auslesen.', $ausgabe);
    }
}
