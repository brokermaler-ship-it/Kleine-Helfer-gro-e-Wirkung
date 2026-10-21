<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Boerse;

use Emc\Boerse\Geld;
use PHPUnit\Framework\TestCase;

/** Geld nur als DECIMAL-Zeichenkette oder ganze Cent; Provision kaufmännisch gerundet je Stück. */
final class GeldTest extends TestCase
{
    public function test_cent_und_dezimal(): void
    {
        $this->assertSame(1250, Geld::cent('12.50'));
        $this->assertSame(5, Geld::cent('0.05'));
        $this->assertSame(1200, Geld::cent('12'));
        $this->assertSame(1250, Geld::cent('12.5'));
        $this->assertSame(-310, Geld::cent('-3.10'));
        $this->assertSame(0, Geld::cent('0.00'));
        $this->assertSame('0.05', Geld::dezimal(5));
        $this->assertSame('1234.56', Geld::dezimal(123456));
        $this->assertSame('12.50', Geld::dezimal(1250));
        $this->assertSame('0.00', Geld::dezimal(0));
        $this->assertSame('-3.10', Geld::dezimal(-310));
        $this->assertSame('-0.05', Geld::dezimal(-5));
    }

    /** @return list<array{0:string}> */
    public static function ungueltig(): array
    {
        return [['abc'], [''], ['12,50'], ['1.234'], ['1e3'], [' 12.50'], ['--1'], ['.5']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltig')]
    public function test_cent_ungueltig_wirft(string $wert): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Geld::cent($wert);
    }

    public function test_provision_kaufmaennisch(): void
    {
        $this->assertSame(125, Geld::provision(1250, '10.00'));
        $this->assertSame(1, Geld::provision(5, '10'));
        $this->assertSame(0, Geld::provision(4, '10'));
        $this->assertSame(50, Geld::provision(335, '15'));
        $this->assertSame(0, Geld::provision(999, '0'));
        $this->assertSame(999, Geld::provision(999, '100'));
        $this->assertSame(75, Geld::provision(1000, '7.5'));
        $this->assertSame(75, Geld::provision(1000, '7.50'));
        $this->assertSame(1, Geld::provision(13, '7.69'));
        $this->assertSame(0, Geld::provision(0, '10.00'));
    }

    public function test_provision_ungueltig(): void
    {
        foreach (['abc', '-1', '100.01', '7.555'] as $prozent) {
            try {
                Geld::provision(1000, $prozent);
                $this->fail($prozent);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        Geld::provision(-1, '10');
    }
}
