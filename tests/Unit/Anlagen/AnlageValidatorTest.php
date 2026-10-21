<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Anlagen;

use Emc\Anlagen\AnlageValidator;
use PHPUnit\Framework\TestCase;

final class AnlageValidatorTest extends TestCase
{
    public function test_gueltige_eingabe_wird_normalisiert(): void
    {
        [$d, $f] = AnlageValidator::pruefe(
            ['name' => '  Clubanlage H0 ', 'spurweite_id' => '2', 'kurzbeschreibung' => ' ', 'standort' => ' Vereinsheim ', 'status' => 'defekt'],
            [1, 2]
        );
        $this->assertSame([], $f);
        $this->assertSame(['name' => 'Clubanlage H0', 'spurweite_id' => 2, 'kurzbeschreibung' => null, 'standort' => 'Vereinsheim', 'status' => 'defekt'], $d);
    }

    public function test_status_standard_in_ordnung(): void
    {
        [$d, $f] = AnlageValidator::pruefe(['name' => 'A', 'spurweite_id' => '1'], [1]);
        $this->assertSame([], $f);
        $this->assertSame('in_ordnung', $d['status']);
    }

    public function test_leerer_name(): void
    {
        [, $f] = AnlageValidator::pruefe(['name' => '   ', 'spurweite_id' => '1'], [1]);
        $this->assertSame('Bitte einen Namen eingeben.', $f['name']);
    }

    public function test_fehlende_oder_unbekannte_spurweite(): void
    {
        [, $f] = AnlageValidator::pruefe(['name' => 'A', 'spurweite_id' => ''], [1]);
        $this->assertSame('Bitte eine Spurweite wählen.', $f['spurweite_id']);
        [, $f] = AnlageValidator::pruefe(['name' => 'A', 'spurweite_id' => '99'], [1]);
        $this->assertSame('Bitte eine Spurweite wählen.', $f['spurweite_id']);
        [, $f] = AnlageValidator::pruefe(['name' => 'A', 'spurweite_id' => 'x1'], [1]);
        $this->assertSame('Bitte eine Spurweite wählen.', $f['spurweite_id']);
    }

    public function test_unbekannter_status(): void
    {
        [, $f] = AnlageValidator::pruefe(['name' => 'A', 'spurweite_id' => '1', 'status' => 'kaputt'], [1]);
        $this->assertArrayHasKey('status', $f);
    }

    public function test_laengen(): void
    {
        [, $f] = AnlageValidator::pruefe(['name' => str_repeat('ä', 150), 'spurweite_id' => '1'], [1]);
        $this->assertSame([], $f);
        [, $f] = AnlageValidator::pruefe([
            'name' => str_repeat('ä', 151),
            'spurweite_id' => '1',
            'standort' => str_repeat('x', 151),
            'kurzbeschreibung' => str_repeat('x', 1001),
        ], [1]);
        $this->assertSame('Bitte höchstens 150 Zeichen eingeben.', $f['name']);
        $this->assertSame('Bitte höchstens 150 Zeichen eingeben.', $f['standort']);
        $this->assertSame('Bitte höchstens 1000 Zeichen eingeben.', $f['kurzbeschreibung']);
    }

    public function test_status_labels(): void
    {
        $this->assertSame(['in_ordnung', 'defekt', 'in_arbeit'], AnlageValidator::STATUS);
        $this->assertSame(['in_ordnung' => 'in Ordnung', 'defekt' => 'defekt', 'in_arbeit' => 'in Arbeit'], AnlageValidator::STATUS_LABELS);
    }
}
