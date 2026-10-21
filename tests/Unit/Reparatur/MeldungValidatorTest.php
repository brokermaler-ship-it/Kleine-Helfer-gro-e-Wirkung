<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Reparatur;

use Emc\Reparatur\MeldungValidator;
use PHPUnit\Framework\TestCase;

final class MeldungValidatorTest extends TestCase
{
    public function test_beschreibung_pflicht(): void
    {
        [, $f] = MeldungValidator::pruefe(['beschreibung' => "  \n "], 'fahrzeug');
        $this->assertSame('Bitte beschreibe den Defekt.', $f['beschreibung']);
        [, $f] = MeldungValidator::pruefe([], 'anlage');
        $this->assertSame('Bitte beschreibe den Defekt.', $f['beschreibung']);
        [, $f] = MeldungValidator::pruefe(['beschreibung' => ['kein Text']], 'fahrzeug');
        $this->assertArrayHasKey('beschreibung', $f);
    }

    public function test_beschreibung_hoechstens_2000_zeichen(): void
    {
        [$d, $f] = MeldungValidator::pruefe(['beschreibung' => str_repeat('ä', 2000)], 'fahrzeug');
        $this->assertSame([], $f);
        $this->assertSame(2000, mb_strlen($d['beschreibung']));
        [, $f] = MeldungValidator::pruefe(['beschreibung' => str_repeat('ä', 2001)], 'fahrzeug');
        $this->assertSame('Bitte höchstens 2000 Zeichen eingeben.', $f['beschreibung']);
    }

    public function test_zeilenumbruch_zaehlt_einfach(): void
    {
        // Der Browser zählt bei maxlength einen Umbruch als ein Zeichen, sendet aber \r\n.
        $text = str_repeat("Zeile\r\n", 333) . 'xx';
        [$d, $f] = MeldungValidator::pruefe(['beschreibung' => $text], 'fahrzeug');
        $this->assertSame([], $f);
        $this->assertStringNotContainsString("\r", $d['beschreibung']);
        $this->assertSame(2000, mb_strlen($d['beschreibung']));
        [$d] = MeldungValidator::pruefe(['beschreibung' => "a\rb", 'wo_bauteil' => "W1\r\nW2"], 'anlage');
        $this->assertSame("a\nb", $d['beschreibung']);
        $this->assertSame("W1\nW2", $d['wo_bauteil']);
    }

    public function test_einstufung(): void
    {
        [$d] = MeldungValidator::pruefe(['beschreibung' => 'Motor brummt', 'einstufung' => 'dringend'], 'fahrzeug');
        $this->assertSame('dringend', $d['prioritaet']);
        [$d] = MeldungValidator::pruefe(['beschreibung' => 'Motor brummt', 'einstufung' => 'normal'], 'fahrzeug');
        $this->assertSame('normal', $d['prioritaet']);
        foreach (['niedrig', 'egal', '', null, ['dringend']] as $roh) {
            [$d, $f] = MeldungValidator::pruefe(['beschreibung' => 'Motor brummt', 'einstufung' => $roh], 'fahrzeug');
            $this->assertSame('normal', $d['prioritaet']);
            $this->assertSame([], $f);
        }
    }

    public function test_wo_bauteil_nur_bei_anlagen(): void
    {
        [$d, $f] = MeldungValidator::pruefe(['beschreibung' => ' Weiche schaltet nicht ', 'wo_bauteil' => ' W12 Antrieb brummt '], 'anlage');
        $this->assertSame([], $f);
        $this->assertSame('Weiche schaltet nicht', $d['beschreibung']);
        $this->assertSame('W12 Antrieb brummt', $d['wo_bauteil']);
        [$d] = MeldungValidator::pruefe(['beschreibung' => 'Weiche', 'wo_bauteil' => '  '], 'anlage');
        $this->assertNull($d['wo_bauteil']);
        [$d, $f] = MeldungValidator::pruefe(['beschreibung' => 'Licht flackert', 'wo_bauteil' => str_repeat('x', 500)], 'fahrzeug');
        $this->assertSame([], $f);
        $this->assertNull($d['wo_bauteil']);
        [, $f] = MeldungValidator::pruefe(['beschreibung' => 'Weiche', 'wo_bauteil' => str_repeat('x', 151)], 'anlage');
        $this->assertSame('Bitte höchstens 150 Zeichen eingeben.', $f['wo_bauteil']);
    }
}
