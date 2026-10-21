<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Wartung;

use Emc\Wartung\GiltFuer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GiltFuerTest extends TestCase
{
    /** @return array<string,array{0:string,1:string,2:?string,3:bool}> */
    public static function faelle(): array
    {
        return [
            'alle + lok' => ['fahrzeug_alle', 'fahrzeug', 'lok', true],
            'alle + wagen' => ['fahrzeug_alle', 'fahrzeug', 'wagen', true],
            'alle + sonderfahrzeug' => ['fahrzeug_alle', 'fahrzeug', 'sonderfahrzeug', true],
            'alle + anlage' => ['fahrzeug_alle', 'anlage', null, false],
            'antrieb + lok' => ['fahrzeug_antrieb', 'fahrzeug', 'lok', true],
            'antrieb + triebwagen' => ['fahrzeug_antrieb', 'fahrzeug', 'triebwagen', true],
            'antrieb + wagen' => ['fahrzeug_antrieb', 'fahrzeug', 'wagen', false],
            'antrieb + sonderfahrzeug' => ['fahrzeug_antrieb', 'fahrzeug', 'sonderfahrzeug', false],
            'antrieb + anlage' => ['fahrzeug_antrieb', 'anlage', null, false],
            'wagen + wagen' => ['fahrzeug_wagen', 'fahrzeug', 'wagen', true],
            'wagen + lok' => ['fahrzeug_wagen', 'fahrzeug', 'lok', false],
            'wagen + sonderfahrzeug' => ['fahrzeug_wagen', 'fahrzeug', 'sonderfahrzeug', false],
            'anlage + anlage' => ['anlage', 'anlage', null, true],
            'anlage + fahrzeug' => ['anlage', 'fahrzeug', 'lok', false],
            'fahrzeug ohne gattung' => ['fahrzeug_antrieb', 'fahrzeug', null, false],
            'unbekannter typ' => ['fahrzeug_alle', 'wartung', null, false],
        ];
    }

    #[DataProvider('faelle')]
    public function test_passt(string $giltFuer, string $objektTyp, ?string $gattung, bool $erwartet): void
    {
        $this->assertSame($erwartet, GiltFuer::from($giltFuer)->passt($objektTyp, $gattung));
    }

    public function test_labels_und_werte(): void
    {
        $this->assertSame('Fahrzeuge (alle)', GiltFuer::FahrzeugAlle->label());
        $this->assertSame('Lok/Triebwagen', GiltFuer::FahrzeugAntrieb->label());
        $this->assertSame('Wagen', GiltFuer::FahrzeugWagen->label());
        $this->assertSame('Anlagen', GiltFuer::Anlage->label());
        $this->assertSame(['fahrzeug_alle', 'fahrzeug_antrieb', 'fahrzeug_wagen', 'anlage'], array_map(fn(GiltFuer $g) => $g->value, GiltFuer::cases()));
    }
}
