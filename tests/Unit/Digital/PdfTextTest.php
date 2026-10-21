<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Digital;

use Emc\Core\DomainFehler;
use Emc\Digital\FunktionPdfErkennung;
use Emc\Digital\PdfText;
use Emc\Tests\Support\MiniPdf;
use PHPUnit\Framework\TestCase;

final class PdfTextTest extends TestCase
{
    private const SCAN = 'Das PDF enthält keinen lesbaren Text (vermutlich ein Scan). Texterkennung gibt es nicht – bitte die Tasten von Hand eintragen.';

    private string|false $errorLog = false;

    protected function setUp(): void
    {
        // Die Klasse protokolliert Bibliotheksfehler mit error_log; in Tests nicht ausgeben.
        $this->errorLog = ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        if ($this->errorLog !== false) {
            ini_set('error_log', $this->errorLog);
        }
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

    public function test_liest_text_mit_umlauten(): void
    {
        $e = (new PdfText())->lies(MiniPdf::erzeuge([['F0 Licht', 'F1 Fahrgeräusch']]));
        $this->assertStringContainsString('F0 Licht', $e['text']);
        $this->assertStringContainsString('Fahrgeräusch', $e['text']);
        $this->assertSame(1, $e['seiten']);
        $this->assertSame(1, $e['gelesen']);
        $tasten = array_column(FunktionPdfErkennung::erkenne($e['text'])->zeilen, 'beschreibung', 'taste');
        $this->assertSame([0 => 'Licht', 1 => 'Fahrgeräusch'], $tasten);
    }

    public function test_seitengrenze(): void
    {
        $e = (new PdfText(maxSeiten: 2))->lies(MiniPdf::erzeuge([['F0 Licht vorne und hinten'], ['F1 Bremsenquietschen'], ['F2 Pfiff lang']]));
        $this->assertSame(3, $e['seiten']);
        $this->assertSame(2, $e['gelesen']);
        $this->assertStringContainsString('F1 Bremsenquietschen', $e['text']);
        $this->assertStringNotContainsString('F2', $e['text']);
    }

    public function test_scan_ohne_text(): void
    {
        $this->assertSame(self::SCAN, $this->fehler(new PdfText(), MiniPdf::ohneText()));
    }

    public function test_kein_pdf(): void
    {
        $this->assertSame('Die Datei ist kein PDF.', $this->fehler(new PdfText(), "PK\x03\x04" . str_repeat("\0", 100) . 'word/document.xml'));
    }

    public function test_kaputtes_pdf(): void
    {
        $meldung = $this->fehler(new PdfText(), "%PDF-1.4\n1 0 obj << /Type /Catalog");
        $this->assertContains($meldung, ['Das PDF konnte nicht gelesen werden.', self::SCAN]);
    }

    public function test_muell_nach_kopf(): void
    {
        $meldung = $this->fehler(new PdfText(), "%PDF-1.7\n" . random_bytes(4096));
        $this->assertContains($meldung, ['Das PDF konnte nicht gelesen werden.', self::SCAN]);
    }

    public function test_verschluesselt(): void
    {
        $pdf = MiniPdf::erzeuge([['F0 Licht']]);
        $pdf = str_replace('/Root 1 0 R', '/Root 1 0 R /Encrypt 9 0 R', $pdf);
        $this->assertSame('Das PDF ist geschützt (verschlüsselt) und kann nicht gelesen werden.', $this->fehler(new PdfText(), $pdf));
    }

    public function test_zu_gross(): void
    {
        $this->assertSame('Die Datei ist zu groß (höchstens 1 MB).', $this->fehler(new PdfText(maxBytes: 1048576), str_repeat('x', 1048577)));
    }

    public function test_grenzen_der_bibliothek_gesetzt(): void
    {
        $config = PdfText::bibliotheksConfig();
        $this->assertFalse($config->getRetainImageContent());
        $this->assertSame(PdfText::DECODE_LIMIT, $config->getDecodeMemoryLimit());
        $this->assertSame(32 * 1048576, PdfText::DECODE_LIMIT);
        $this->assertSame(20 * 1048576, PdfText::MAX_BYTES);
        $this->assertSame(150, PdfText::MAX_SEITEN);
        $this->assertSame(2 * 1048576, PdfText::MAX_TEXT);
        $this->assertSame(PdfText::MAX_BYTES, (new PdfText())->maxBytes);
    }

    public function test_zip_bombe_im_datenstrom(): void
    {
        // 40 MB Nullen, gepackt rund 40 KB: die Bibliothek entpackt höchstens DECODE_LIMIT, die Seite wird übersprungen.
        $pdf = MiniPdf::mitGepacktemStrom(['F0 Licht vorne und hinten'], 40 * 1048576);
        $this->assertLessThan(100000, strlen($pdf));
        gc_collect_cycles();
        $vorher = memory_get_usage();
        memory_reset_peak_usage();
        $this->assertSame(self::SCAN, $this->fehler(new PdfText(), $pdf));
        $this->assertLessThan(96 * 1048576, memory_get_peak_usage() - $vorher);
    }

    public function test_viele_zip_bomben_vor_dem_einlesen_abgelehnt(): void
    {
        // 4 × 40 MB entpackt (je Strom gekappt bei 32 MB) liegt über DECODE_GESAMT: abgelehnt, bevor die Bibliothek entpackt.
        $pdf = MiniPdf::mitGepacktemStrom(['F0 Licht vorne und hinten'], 40 * 1048576, 4);
        $this->assertGreaterThan(PdfText::DECODE_GESAMT, PdfText::entpackteGroesse($pdf, PdfText::DECODE_GESAMT));
        gc_collect_cycles();
        $vorher = memory_get_usage();
        memory_reset_peak_usage();
        $this->assertSame('Das PDF konnte nicht gelesen werden.', $this->fehler(new PdfText(), $pdf));
        $this->assertLessThan(16 * 1048576, memory_get_peak_usage() - $vorher);
    }

    public function test_entpackte_groesse_normaler_pdfs(): void
    {
        $pdf = MiniPdf::erzeuge([['F0 Licht'], ['F1 Sound']]);
        $this->assertLessThan(1000, PdfText::entpackteGroesse($pdf, PdfText::DECODE_GESAMT));
        $this->assertSame(5 * 1048576, PdfText::entpackteGroesse(MiniPdf::mitGepacktemStrom(['F0 Licht'], 5 * 1048576), PdfText::DECODE_GESAMT) - PdfText::entpackteGroesse(MiniPdf::erzeuge([['F0 Licht']]), PdfText::DECODE_GESAMT));
    }

    public function test_zeit_und_speicher_danach_wie_vorher(): void
    {
        $zeit = ini_get('max_execution_time');
        $speicher = ini_get('memory_limit');
        (new PdfText(zeitSekunden: 5))->lies(MiniPdf::erzeuge([['F0 Licht vorne und hinten']]));
        $this->assertSame($zeit, ini_get('max_execution_time'));
        $this->assertSame($speicher, ini_get('memory_limit'));
    }

    public function test_viele_seiten(): void
    {
        $seiten = array_fill(0, 2000, ['Allgemeine Hinweise zum Betrieb']);
        $seiten[0] = ['F0 Licht vorne und hinten'];
        $start = microtime(true);
        $e = (new PdfText())->lies(MiniPdf::erzeuge($seiten));
        $this->assertSame(2000, $e['seiten']);
        $this->assertSame(150, $e['gelesen']);
        $this->assertLessThan(20, microtime(true) - $start);
    }
}
