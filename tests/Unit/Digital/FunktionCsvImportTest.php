<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Digital;

use Emc\Digital\FunktionCsvImport;
use PHPUnit\Framework\TestCase;

final class FunktionCsvImportTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../fixtures/csv/' . $name);
    }

    public function test_excel_ansi_mit_umlauten(): void
    {
        $e = FunktionCsvImport::parse($this->fixture('excel_ansi.csv'));
        $this->assertSame([], $e->fehler);
        $this->assertCount(2, $e->zeilen);
        $this->assertSame(['taste' => 1, 'beschreibung' => 'Fahrgeräusch', 'schaltart' => 'dauer'], $e->zeilen[0]);
        $this->assertSame(['taste' => 2, 'beschreibung' => 'Pfeife kurz', 'schaltart' => 'moment'], $e->zeilen[1]);
    }

    public function test_utf8_mit_bom_ohne_kopfzeile(): void
    {
        $e = FunktionCsvImport::parse($this->fixture('utf8_bom.csv'));
        $this->assertSame([], $e->fehler);
        $this->assertCount(2, $e->zeilen);
        $this->assertSame(0, $e->zeilen[0]['taste']);
        $this->assertSame('dauer', $e->zeilen[0]['schaltart']);
        $this->assertSame(3, $e->zeilen[1]['taste']);
        $this->assertSame('moment', $e->zeilen[1]['schaltart']);
    }

    public function test_komma_als_trenner(): void
    {
        $e = FunktionCsvImport::parse($this->fixture('komma.csv'));
        $this->assertSame([], $e->fehler);
        $this->assertSame(['taste' => 4, 'beschreibung' => 'Kupplung', 'schaltart' => 'moment'], $e->zeilen[0]);
    }

    public function test_fehlerzeilen(): void
    {
        $e = FunktionCsvImport::parse("Taste;Beschreibung;Schaltart\nF40;X;Dauer\nF2;A;Dauer\nF2;B;Dauer\nF3;C;kurz\nF5;;Dauer");
        $this->assertSame([
            'Zeile 2: Taste „F40“ ist ungültig (erlaubt F0–F31).',
            'Zeile 4: Taste F2 kommt doppelt vor.',
            'Zeile 5: Schaltart „kurz“ ist unbekannt (erlaubt Dauer oder Moment).',
            'Zeile 6: Beschreibung fehlt.',
        ], $e->fehler);
    }

    public function test_fixture_fehlerhaft_liefert_fehler(): void
    {
        $this->assertCount(4, FunktionCsvImport::parse($this->fixture('fehlerhaft.csv'))->fehler);
    }

    public function test_vorlage_ist_selbst_importierbar(): void
    {
        $e = FunktionCsvImport::parse(FunktionCsvImport::VORLAGE);
        $this->assertSame([], $e->fehler);
        $this->assertCount(3, $e->zeilen);
        $this->assertSame('Fahrgeräusch', $e->zeilen[1]['beschreibung']);
    }

    public function test_windows_1252_ohne_kopfzeile_mit_crlf_und_leerzeilen(): void
    {
        $e = FunktionCsvImport::parse("F0;Licht;Dauer\r\n\r\n;;\r\nF 4;\xdcberlast;Moment\r\n");
        $this->assertSame([], $e->fehler);
        $this->assertSame('Überlast', $e->zeilen[1]['beschreibung']);
    }

    public function test_tab_und_anfuehrungszeichen_und_laenge(): void
    {
        $e = FunktionCsvImport::parse("1\t\"Licht; vorn\"\tdauer\n2\t" . str_repeat('x', 101) . "\tdauer\n");
        $this->assertSame('Licht; vorn', $e->zeilen[0]['beschreibung']);
        $this->assertSame(100, mb_strlen($e->zeilen[1]['beschreibung']));
    }
}
