<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Boerse;

use Emc\Boerse\Warenkorb;
use Emc\Core\Session;
use PHPUnit\Framework\TestCase;

final class WarenkorbTest extends TestCase
{
    private array $daten = [];

    private function korb(int $boerseId = 1): Warenkorb
    {
        return new Warenkorb(new Session($this->daten), $boerseId);
    }

    public function test_hinzu_doppelt_und_summe(): void
    {
        $k = $this->korb();
        $this->assertTrue($k->hinzu(7, '12.50'));
        $this->assertFalse($k->hinzu(7, '12.50'), 'schon im Warenkorb');
        $this->assertTrue($k->hinzu(3, '7.50'));
        $this->assertSame(2000, $k->summeCent());
        $this->assertSame([['angebot_id' => 7, 'preis' => '12.50'], ['angebot_id' => 3, 'preis' => '7.50']], $k->positionen());
        // Nur ids und Preise in der Sitzung.
        $this->assertSame([['angebot_id' => 7, 'preis' => '12.50'], ['angebot_id' => 3, 'preis' => '7.50']], $this->daten['kasse_1']);
    }

    public function test_hoechstens_100_positionen(): void
    {
        $k = $this->korb();
        for ($i = 1; $i <= 100; $i++) {
            $this->assertTrue($k->hinzu($i, '1.00'));
        }
        $this->assertFalse($k->hinzu(101, '1.00'));
        $this->assertCount(100, $k->positionen());
        $this->assertSame(10000, $k->summeCent());
    }

    public function test_preis_entfernen_leeren(): void
    {
        $k = $this->korb();
        $k->hinzu(7, '12.50');
        $k->hinzu(8, '3.00');
        $k->setzePreis(7, '10.00');
        $this->assertSame(1300, $k->summeCent());
        $k->setzePreis(99, '1.00'); // nicht im Korb: nichts
        $this->assertCount(2, $k->positionen());
        $k->entferne(7);
        $this->assertSame([['angebot_id' => 8, 'preis' => '3.00']], $k->positionen());
        $k->leere();
        $this->assertSame([], $k->positionen());
        $this->assertSame(0, $k->summeCent());
    }

    public function test_ungueltiger_preis(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->korb()->hinzu(1, '12,50');
    }

    public function test_getrennte_koerbe_je_boerse(): void
    {
        $this->korb(1)->hinzu(7, '12.50');
        $this->korb(2)->hinzu(8, '1.00');
        $this->assertSame([['angebot_id' => 7, 'preis' => '12.50']], $this->korb(1)->positionen());
        $this->assertSame([['angebot_id' => 8, 'preis' => '1.00']], $this->korb(2)->positionen());
        $this->assertTrue($this->korb(1)->enthaelt(7));
        $this->assertFalse($this->korb(2)->enthaelt(7));
    }

    public function test_kaputte_sitzung_wird_ignoriert(): void
    {
        $this->daten['kasse_1'] = ['quatsch', ['angebot_id' => '5', 'preis' => '2.00'], ['angebot_id' => 6, 'preis' => 'x']];
        $this->assertSame([['angebot_id' => 5, 'preis' => '2.00']], $this->korb()->positionen());
    }
}
