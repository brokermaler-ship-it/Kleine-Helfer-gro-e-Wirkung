<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Core;

use Emc\Core\DomainFehler;
use Emc\Core\Tresor;
use PHPUnit\Framework\TestCase;

final class TresorTest extends TestCase
{
    private function schluessel(string $fuell = 'a'): string
    {
        return base64_encode(str_repeat($fuell, 32));
    }

    public function testRundreise(): void
    {
        $t = new Tresor($this->schluessel());
        $geheim = $t->verschluessele('Grüße {"x":1}');
        self::assertStringNotContainsString('Grüße', $geheim);
        self::assertSame('Grüße {"x":1}', $t->entschluessele($geheim));
        self::assertNotSame($geheim, $t->verschluessele('Grüße {"x":1}'), 'Nonce ist zufällig');
    }

    public function testManipulationWirdErkannt(): void
    {
        $t = new Tresor($this->schluessel());
        $roh = base64_decode($t->verschluessele('geheim'), true);
        $roh[strlen($roh) - 1] = $roh[strlen($roh) - 1] ^ "\x01";
        $this->expectException(DomainFehler::class);
        $t->entschluessele(base64_encode($roh));
    }

    public function testVeraenderteNonceOderTagWirdErkannt(): void
    {
        $t = new Tresor($this->schluessel());
        $roh = base64_decode($t->verschluessele('geheim'), true);
        foreach ([0, 12] as $pos) {
            $m = $roh;
            $m[$pos] = $m[$pos] ^ "\x01";
            try {
                $t->entschluessele(base64_encode($m));
                self::fail('Ausnahme erwartet');
            } catch (DomainFehler) {
                self::assertTrue(true);
            }
        }
    }

    public function testFalscherSchluessel(): void
    {
        $geheim = (new Tresor($this->schluessel('a')))->verschluessele('geheim');
        $this->expectException(DomainFehler::class);
        (new Tresor($this->schluessel('b')))->entschluessele($geheim);
    }

    public function testUngueltigerOderFehlenderSchluessel(): void
    {
        foreach (['', 'kein base64 !!', base64_encode('zu kurz')] as $schluessel) {
            try {
                new Tresor($schluessel);
                self::fail('Ausnahme erwartet');
            } catch (DomainFehler $e) {
                self::assertSame('app_schluessel fehlt oder ist ungültig (32 Byte, base64).', $e->getMessage());
            }
        }
    }

    public function testZuKurzerGeheimtext(): void
    {
        $this->expectException(DomainFehler::class);
        (new Tresor($this->schluessel()))->entschluessele(base64_encode('abc'));
    }
}
