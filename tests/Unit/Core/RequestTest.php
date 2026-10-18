<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Core;

use Emc\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function test_text_liefert_string_oder_standard(): void
    {
        $r = new Request('GET', '/', ['a' => 'x', 'arr' => ['1']], ['p' => ' y ']);
        $this->assertSame('x', $r->text('a'));
        $this->assertSame(' y ', $r->text('p'));
        $this->assertSame('', $r->text('fehlt'));
        $this->assertSame('std', $r->text('fehlt', 'std'));
        $this->assertSame('std', $r->text('arr', 'std'));
    }

    public function test_ganzzahl_prueft_format_und_bereich(): void
    {
        $r = new Request('GET', '/', ['a' => '5', 'neg' => '-3', 'gross' => '99999999999', 'kein' => 'abc', 'arr' => ['1'], 'leer' => '', 'komma' => '1.5']);
        $this->assertSame(5, $r->ganzzahl('a', 1, 10));
        $this->assertNull($r->ganzzahl('a', 6, 10));
        $this->assertSame(-3, $r->ganzzahl('neg', -10, 10));
        $this->assertNull($r->ganzzahl('gross', 1, 2147483647));
        $this->assertNull($r->ganzzahl('kein', 1, 10));
        $this->assertNull($r->ganzzahl('arr', 1, 10));
        $this->assertNull($r->ganzzahl('leer', 1, 10));
        $this->assertNull($r->ganzzahl('komma', 1, 10));
        $this->assertNull($r->ganzzahl('fehlt', 1, 10));
    }
}
