<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Wartung;

use Emc\Wartung\WartungsartValidator;
use PHPUnit\Framework\TestCase;

final class WartungsartValidatorTest extends TestCase
{
    /** @return array<string,string> */
    private function eingabe(array $u = []): array
    {
        return $u + ['name' => ' Räder reinigen ', 'gilt_fuer' => 'fahrzeug_alle', 'intervall_monate' => '6', 'intervall_stunden' => '20', 'aktiv' => '1', 'sortierung' => '10'];
    }

    public function test_gueltige_eingabe_wird_normalisiert(): void
    {
        [$d, $f] = WartungsartValidator::pruefe($this->eingabe());
        $this->assertSame([], $f);
        $this->assertSame(['name' => 'Räder reinigen', 'gilt_fuer' => 'fahrzeug_alle', 'intervall_monate' => 6, 'intervall_stunden' => 20, 'aktiv' => 1, 'sortierung' => 10], $d);
    }

    public function test_leere_intervalle_werden_null_und_ohne_aktiv_inaktiv(): void
    {
        [$d, $f] = WartungsartValidator::pruefe(['name' => 'Kupplungen', 'gilt_fuer' => 'fahrzeug_alle', 'intervall_monate' => '12', 'intervall_stunden' => ' ']);
        $this->assertSame([], $f);
        $this->assertNull($d['intervall_stunden']);
        $this->assertSame(0, $d['aktiv']);
        $this->assertSame(0, $d['sortierung']);
    }

    public function test_beide_intervalle_leer(): void
    {
        [, $f] = WartungsartValidator::pruefe($this->eingabe(['intervall_monate' => '', 'intervall_stunden' => '']));
        $this->assertSame('Bitte ein Intervall in Monaten oder Betriebsstunden angeben.', $f['intervall_monate']);
    }

    public function test_anlage_ohne_stunden(): void
    {
        [, $f] = WartungsartValidator::pruefe($this->eingabe(['gilt_fuer' => 'anlage', 'intervall_monate' => '3', 'intervall_stunden' => '5']));
        $this->assertSame('Anlagen haben keine Betriebsstunden – bitte ein Intervall in Monaten angeben.', $f['intervall_stunden']);
        [, $f] = WartungsartValidator::pruefe($this->eingabe(['gilt_fuer' => 'anlage', 'intervall_monate' => '3', 'intervall_stunden' => '']));
        $this->assertSame([], $f);
    }

    public function test_ungueltige_intervalle(): void
    {
        foreach (['0', 'abc', '-1', '1000', '2,5', '1e2'] as $wert) {
            [, $f] = WartungsartValidator::pruefe($this->eingabe(['intervall_monate' => $wert]));
            $this->assertSame('Bitte eine ganze Zahl von 1 bis 999 angeben.', $f['intervall_monate'] ?? null, $wert);
            [, $f] = WartungsartValidator::pruefe($this->eingabe(['intervall_stunden' => $wert]));
            $this->assertSame('Bitte eine ganze Zahl von 1 bis 999 angeben.', $f['intervall_stunden'] ?? null, $wert);
        }
        [, $f] = WartungsartValidator::pruefe($this->eingabe(['intervall_monate' => '999', 'intervall_stunden' => '1']));
        $this->assertSame([], $f);
    }

    public function test_name_pflicht_und_hoechstlaenge(): void
    {
        [, $f] = WartungsartValidator::pruefe($this->eingabe(['name' => '  ']));
        $this->assertSame('Bitte einen Namen eingeben.', $f['name']);
        [, $f] = WartungsartValidator::pruefe($this->eingabe(['name' => str_repeat('ä', 101)]));
        $this->assertSame('Bitte höchstens 100 Zeichen eingeben.', $f['name']);
        [, $f] = WartungsartValidator::pruefe($this->eingabe(['name' => str_repeat('ä', 100)]));
        $this->assertSame([], $f);
    }

    public function test_gilt_fuer_und_sortierung(): void
    {
        [, $f] = WartungsartValidator::pruefe($this->eingabe(['gilt_fuer' => 'boot']));
        $this->assertSame('Bitte wählen, wofür die Wartungsart gilt.', $f['gilt_fuer']);
        [, $f] = WartungsartValidator::pruefe($this->eingabe(['sortierung' => 'x']));
        $this->assertSame('Bitte eine ganze Zahl angeben.', $f['sortierung']);
        [, $f] = WartungsartValidator::pruefe(['name' => ['x'], 'gilt_fuer' => ['y'], 'intervall_monate' => ['1']]);
        $this->assertArrayHasKey('name', $f);
        $this->assertArrayHasKey('gilt_fuer', $f);
    }

    public function test_intervall_text(): void
    {
        $this->assertSame('6 Monate oder 20 h', WartungsartValidator::intervallText(6, 20));
        $this->assertSame('12 Monate', WartungsartValidator::intervallText(12, null));
        $this->assertSame('1 Monat', WartungsartValidator::intervallText(1, null));
        $this->assertSame('50 h', WartungsartValidator::intervallText(null, 50));
        $this->assertSame('–', WartungsartValidator::intervallText(null, null));
    }
}
