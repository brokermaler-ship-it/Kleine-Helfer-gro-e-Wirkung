<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Core;

use Emc\Core\Csrf;
use Emc\Core\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function test_token_ist_stabil_und_wird_geprueft(): void
    {
        $daten = [];
        $csrf = new Csrf(new Session($daten));
        $t = $csrf->token();
        $this->assertSame($t, $csrf->token());
        $this->assertGreaterThanOrEqual(32, strlen($t));
        $this->assertTrue($csrf->pruefe($t));
        $this->assertFalse($csrf->pruefe('falsch'));
        $this->assertFalse($csrf->pruefe(null));
        $this->assertFalse($csrf->pruefe(''));
    }

    public function test_ohne_vorher_erzeugtes_token_ist_nichts_gueltig(): void
    {
        $daten = [];
        $this->assertFalse((new Csrf(new Session($daten)))->pruefe(''));
    }

    public function test_session_meldungen_werden_einmal_abgeholt(): void
    {
        $daten = [];
        $s = new Session($daten);
        $s->meldung('erfolg', 'Gespeichert.');
        $this->assertSame([['typ' => 'erfolg', 'text' => 'Gespeichert.']], $s->meldungenAbholen());
        $this->assertSame([], $s->meldungenAbholen());
    }

    public function test_session_hole_setze_entferne(): void
    {
        $daten = [];
        $s = new Session($daten);
        $this->assertSame('d', $s->hole('k', 'd'));
        $s->setze('k', 5);
        $this->assertSame(5, $s->hole('k'));
        $s->entferne('k');
        $this->assertNull($s->hole('k'));
    }
}
