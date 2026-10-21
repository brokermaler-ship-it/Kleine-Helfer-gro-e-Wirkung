<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Reparatur;

use Emc\Reparatur\ReparaturValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReparaturValidatorTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function eingabe(array $mehr = []): array
    {
        return $mehr + [
            'beschreibung' => 'Motor brummt',
            'wo_bauteil' => '',
            'prioritaet' => 'normal',
            'status' => 'geplant',
            'zustaendig' => '',
            'termin' => '',
            'notizen' => '',
            'was_gemacht' => '',
            'teile' => [],
        ];
    }

    public function test_gueltige_eingabe(): void
    {
        [$d, $f, $teile] = ReparaturValidator::pruefe(self::eingabe([
            'beschreibung' => '  Motor brummt  ',
            'prioritaet' => 'dringend',
            'status' => 'in_arbeit',
            'zustaendig' => '7',
            'termin' => '2026-11-03',
            'notizen' => ' Kohlen prüfen ',
        ]), [7, 8], 'fahrzeug');
        $this->assertSame([], $f);
        $this->assertSame([], $teile);
        $this->assertSame([
            'beschreibung' => 'Motor brummt',
            'wo_bauteil' => null,
            'prioritaet' => 'dringend',
            'zustaendig_person_id' => 7,
            'termin' => '2026-11-03',
            'status' => 'in_arbeit',
            'notizen' => 'Kohlen prüfen',
            'was_gemacht' => null,
        ], $d);
    }

    public function test_leere_felder_werden_null_und_termin_deutsch(): void
    {
        [$d, $f] = ReparaturValidator::pruefe(self::eingabe(['termin' => '3.11.2026']), [], 'fahrzeug');
        $this->assertSame([], $f);
        $this->assertNull($d['zustaendig_person_id']);
        $this->assertSame('2026-11-03', $d['termin']);
        $this->assertNull($d['notizen']);
    }

    public function test_wo_bauteil_nur_bei_anlagen(): void
    {
        [$d] = ReparaturValidator::pruefe(self::eingabe(['wo_bauteil' => 'W12']), [], 'fahrzeug');
        $this->assertNull($d['wo_bauteil']);
        [$d] = ReparaturValidator::pruefe(self::eingabe(['wo_bauteil' => ' W12 Antrieb ']), [], 'anlage');
        $this->assertSame('W12 Antrieb', $d['wo_bauteil']);
    }

    public function test_pflichtfelder_und_listen(): void
    {
        [, $f] = ReparaturValidator::pruefe(self::eingabe([
            'beschreibung' => '   ',
            'prioritaet' => 'sofort',
            'status' => 'geloescht',
            'termin' => '31.02.2026',
            'notizen' => str_repeat('x', 5001),
        ]), [], 'fahrzeug');
        $this->assertSame('Bitte beschreibe den Defekt.', $f['beschreibung']);
        $this->assertArrayHasKey('prioritaet', $f);
        $this->assertArrayHasKey('status', $f);
        $this->assertSame('Bitte ein gültiges Datum angeben.', $f['termin']);
        $this->assertArrayHasKey('notizen', $f);
    }

    public function test_zeilenumbrueche_zaehlen_wie_im_browser(): void
    {
        // 2500 Zeilen à „x\r\n“ = 5000 Zeichen im Browser (maxlength), 7500 Bytes ohne Normalisierung.
        [$d, $f] = ReparaturValidator::pruefe(self::eingabe(['notizen' => str_repeat("x\r\n", 2499) . 'xy']), [], 'fahrzeug');
        $this->assertSame([], $f);
        $this->assertStringNotContainsString("\r", (string) $d['notizen']);
    }

    public function test_unbekannte_person(): void
    {
        [$d, $f] = ReparaturValidator::pruefe(self::eingabe(['zustaendig' => '99']), [7], 'fahrzeug');
        $this->assertArrayHasKey('zustaendig', $f);
        $this->assertNull($d['zustaendig_person_id']);
        [, $f] = ReparaturValidator::pruefe(self::eingabe(['zustaendig' => '7x']), [7], 'fahrzeug');
        $this->assertArrayHasKey('zustaendig', $f);
    }

    public function test_erledigt_verlangt_was_gemacht(): void
    {
        [, $f] = ReparaturValidator::pruefe(self::eingabe(['status' => 'erledigt', 'was_gemacht' => '  ']), [], 'fahrzeug');
        $this->assertSame('Bitte kurz beschreiben, was gemacht wurde.', $f['was_gemacht']);
        [$d, $f] = ReparaturValidator::pruefe(self::eingabe(['status' => 'erledigt', 'was_gemacht' => 'Kohlen getauscht']), [], 'fahrzeug');
        $this->assertSame([], $f);
        $this->assertSame('Kohlen getauscht', $d['was_gemacht']);
        // Verwerfen braucht keine Angabe.
        [, $f] = ReparaturValidator::pruefe(self::eingabe(['status' => 'verworfen']), [], 'fahrzeug');
        $this->assertSame([], $f);
    }

    /** @return array<string,array{string,string}> */
    public static function gueltigeBetraege(): array
    {
        return [
            'Komma eine Stelle' => ['12,5', '12.50'],
            'Punkt' => ['12.50', '12.50'],
            'ganze Zahl' => ['1234', '1234.00'],
            'null' => ['0', '0.00'],
            'Leerzeichen' => [' 7,25 ', '7.25'],
            'Höchstwert' => ['999999,99', '999999.99'],
            'führende Null' => ['0,05', '0.05'],
        ];
    }

    #[DataProvider('gueltigeBetraege')]
    public function test_gueltige_kosten(string $eingabe, string $erwartet): void
    {
        [, $f, $teile] = ReparaturValidator::pruefe(self::eingabe(['teile' => [['bezeichnung' => 'Haftreifen', 'kosten' => $eingabe]]]), [], 'fahrzeug');
        $this->assertSame([], $f);
        $this->assertSame($erwartet, $teile[0]['kosten']);
    }

    /** @return array<string,array{string}> */
    public static function ungueltigeBetraege(): array
    {
        return [
            'negativ' => ['-1'],
            'drei Nachkommastellen' => ['12,345'],
            'Tausenderpunkt' => ['1.234,50'],
            'Text' => ['abc'],
            'zu groß' => ['1000000'],
            'Euro-Zeichen' => ['12,50 €'],
            'nur Komma' => [',50'],
            'Exponent' => ['1e3'],
        ];
    }

    #[DataProvider('ungueltigeBetraege')]
    public function test_ungueltige_kosten(string $eingabe): void
    {
        [, $f] = ReparaturValidator::pruefe(self::eingabe(['teile' => [
            ['bezeichnung' => 'Schleifer', 'kosten' => '1'],
            ['bezeichnung' => 'Haftreifen', 'kosten' => $eingabe],
        ]]), [], 'fahrzeug');
        $this->assertSame(['teile.1.kosten' => 'Bitte die Kosten als Betrag angeben, z. B. 12,50.'], $f);
    }

    public function test_ersatzteile_leere_zeilen_entfallen_und_bezeichnung_pflicht(): void
    {
        [, $f, $teile] = ReparaturValidator::pruefe(self::eingabe(['teile' => [
            ['bezeichnung' => '', 'artikelnummer' => '', 'bezugsquelle' => '', 'kosten' => ''],
            ['bezeichnung' => ' Haftreifen ', 'artikelnummer' => ' 123 ', 'bezugsquelle' => 'Händler', 'kosten' => '4,9', 'bestellt' => '1'],
            ['bezeichnung' => '  ', 'kosten' => ' '],
            ['bezeichnung' => 'Schleifer'],
        ]]), [], 'fahrzeug');
        $this->assertSame([], $f);
        $this->assertSame([
            ['bezeichnung' => 'Haftreifen', 'artikelnummer' => '123', 'bezugsquelle' => 'Händler', 'kosten' => '4.90', 'bestellt' => true],
            ['bezeichnung' => 'Schleifer', 'artikelnummer' => null, 'bezugsquelle' => null, 'kosten' => null, 'bestellt' => false],
        ], $teile);

        [, $f] = ReparaturValidator::pruefe(self::eingabe(['teile' => [['bezeichnung' => '', 'kosten' => '12,50']]]), [], 'fahrzeug');
        $this->assertSame(['teile.0.bezeichnung' => 'Bitte eine Bezeichnung für das Ersatzteil angeben.'], $f);
    }

    public function test_ersatzteile_robust_gegen_unsinn(): void
    {
        [, $f, $teile] = ReparaturValidator::pruefe(self::eingabe(['teile' => 'kein Array']), [], 'fahrzeug');
        $this->assertSame([], $f);
        $this->assertSame([], $teile);
        // Keine Zeile (kein Array) und Nicht-Text-Werte gelten als leer; Fehlerschlüssel zählen die gesendeten Zeilen.
        [, $f, $teile] = ReparaturValidator::pruefe(self::eingabe(['teile' => [
            'x', ['bezeichnung' => ['a']], ['bezeichnung' => 'Lampe', 'kosten' => ['1']], ['bezeichnung' => '', 'kosten' => '2'],
        ]]), [], 'fahrzeug');
        $this->assertSame(['teile.3.bezeichnung' => 'Bitte eine Bezeichnung für das Ersatzteil angeben.'], $f);
        $this->assertSame(['Lampe', ''], array_column($teile, 'bezeichnung'));
        $this->assertNull($teile[0]['kosten']);
    }

    public function test_hoechstens_fuenfzig_ersatzteile(): void
    {
        $viele = array_fill(0, 51, ['bezeichnung' => 'Schraube']);
        [, $f] = ReparaturValidator::pruefe(self::eingabe(['teile' => $viele]), [], 'fahrzeug');
        $this->assertSame('Bitte höchstens 50 Ersatzteile angeben.', $f['teile']);
    }

    public function test_betrag_anzeige_ohne_gleitkomma(): void
    {
        $this->assertSame('19,75 €', ReparaturValidator::betrag('19.75'));
        $this->assertSame('0,00 €', ReparaturValidator::betrag('0.00'));
        $this->assertSame('1.234,50 €', ReparaturValidator::betrag('1234.50'));
        $this->assertSame('999.999,99 €', ReparaturValidator::betrag('999999.99'));
        $this->assertSame('12.345.678,90 €', ReparaturValidator::betrag('12345678.9'));
        $this->assertSame('7,00 €', ReparaturValidator::betrag('7'));
        $this->assertSame('', ReparaturValidator::betrag(null));
        // Fürs Formular: Komma, ohne Euro-Zeichen.
        $this->assertSame('12,50', ReparaturValidator::betragEingabe('12.50'));
        $this->assertSame('', ReparaturValidator::betragEingabe(null));
    }
}
