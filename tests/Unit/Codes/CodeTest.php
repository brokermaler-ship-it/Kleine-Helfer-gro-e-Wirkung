<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Codes;

use Emc\Codes\Code;
use PHPUnit\Framework\TestCase;

final class CodeTest extends TestCase
{
    public function test_fahrzeug_code_aus_inventarnummer(): void
    {
        $this->assertSame('F12', Code::fahrzeug('EMC-0012'));
        $this->assertSame('F12345', Code::fahrzeug('EMC-12345'));
        $this->assertSame('F1', Code::fahrzeug('EMC-0001'));
        $this->assertNull(Code::fahrzeug('EMC-0000'));
        $this->assertNull(Code::fahrzeug('ALT-7'));
        $this->assertNull(Code::fahrzeug('EMC-12a'));
        $this->assertNull(Code::fahrzeug(''));
    }

    public function test_normalisiere_entfernt_leerraum_und_macht_gross(): void
    {
        $this->assertSame('F012', Code::normalisiere(" f 0\t12 "));
        $this->assertSame('A3', Code::normalisiere("a\u{00A0}3"));
        $this->assertSame('F12', Code::normalisiere("F12\n"));
    }

    public function test_zerlege(): void
    {
        $this->assertSame(['typ' => 'fahrzeug', 'nummer' => 12], Code::zerlege(' f 012 '));
        $this->assertSame(['typ' => 'anlage', 'nummer' => 3], Code::zerlege('a3'));
        $this->assertSame(['typ' => 'anlage', 'nummer' => 3], Code::zerlege("A\u{00A0}3"));
        $this->assertNull(Code::zerlege('F0'));
        $this->assertNull(Code::zerlege('F12a'));
        $this->assertNull(Code::zerlege('X3'));
        $this->assertNull(Code::zerlege('F'));
        $this->assertNull(Code::zerlege(''));
        $this->assertNull(Code::zerlege('F1234567890'));
        $this->assertNull(Code::zerlege('EMC-0012'));
    }

    public function test_ist_adresse(): void
    {
        $this->assertSame(3, Code::istAdresse('0003'));
        $this->assertSame(3, Code::istAdresse(' 3 '));
        $this->assertSame(0, Code::istAdresse('0'));
        $this->assertSame(123456, Code::istAdresse('123456'));
        $this->assertNull(Code::istAdresse('1234567'));
        $this->assertNull(Code::istAdresse('F3'));
        $this->assertNull(Code::istAdresse(''));
        $this->assertNull(Code::istAdresse('-3'));
    }
}
