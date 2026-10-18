<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Fahrzeuge;

use Emc\Fahrzeuge\FahrzeugValidator;
use PHPUnit\Framework\TestCase;

final class FahrzeugValidatorTest extends TestCase
{
    public function test_pflichtfelder(): void
    {
        $f = FahrzeugValidator::pruefe(FahrzeugValidator::normalisiere(['bezeichnung' => '  ']));
        $this->assertSame('Bitte eine Bezeichnung eingeben.', $f['bezeichnung']);
        $this->assertSame('Bitte eine Spurweite wählen.', $f['spurweite_id']);
    }

    public function test_standardwerte(): void
    {
        $d = FahrzeugValidator::normalisiere(['bezeichnung' => ' BR 218 ', 'spurweite_id' => '1', 'eigentuemer_person_id' => '', 'baureihe' => '']);
        $this->assertSame('BR 218', $d['bezeichnung']);
        $this->assertSame('2L', $d['stromsystem']);
        $this->assertSame('einsatzbereit', $d['status']);
        $this->assertSame('lok', $d['gattung']);
        $this->assertNull($d['eigentuemer_person_id']);
        $this->assertNull($d['baureihe']);
        $this->assertSame(1, $d['spurweite_id']);
        $this->assertSame([], FahrzeugValidator::pruefe($d));
    }

    public function test_betriebsstunden_mit_komma(): void
    {
        $d = FahrzeugValidator::normalisiere(['bezeichnung' => 'A', 'spurweite_id' => '1', 'betriebsstunden' => '12,5']);
        $this->assertSame(12.5, $d['betriebsstunden']);
        $this->assertArrayNotHasKey('betriebsstunden', FahrzeugValidator::pruefe($d));

        $neg = FahrzeugValidator::normalisiere(['bezeichnung' => 'A', 'spurweite_id' => '1', 'betriebsstunden' => '-1']);
        $this->assertArrayHasKey('betriebsstunden', FahrzeugValidator::pruefe($neg));
        $text = FahrzeugValidator::normalisiere(['bezeichnung' => 'A', 'spurweite_id' => '1', 'betriebsstunden' => 'viel']);
        $this->assertArrayHasKey('betriebsstunden', FahrzeugValidator::pruefe($text));
    }

    public function test_ungueltige_auswahlwerte(): void
    {
        $d = FahrzeugValidator::normalisiere([
            'bezeichnung' => 'A', 'spurweite_id' => '1', 'stromsystem' => 'AC', 'status' => 'kaputt', 'epoche' => 'VII', 'gattung' => 'ufo',
        ]);
        $f = FahrzeugValidator::pruefe($d);
        foreach (['stromsystem', 'status', 'epoche', 'gattung'] as $feld) {
            $this->assertArrayHasKey($feld, $f);
        }
    }

    public function test_laengen_und_arrays(): void
    {
        $d = FahrzeugValidator::normalisiere(['bezeichnung' => str_repeat('x', 151), 'spurweite_id' => ['1'], 'baureihe' => ['a']]);
        $f = FahrzeugValidator::pruefe($d);
        $this->assertArrayHasKey('bezeichnung', $f);
        $this->assertArrayHasKey('spurweite_id', $f);
        $this->assertNull($d['baureihe']);
    }
}
