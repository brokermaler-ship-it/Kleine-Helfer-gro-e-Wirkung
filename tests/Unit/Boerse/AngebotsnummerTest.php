<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Boerse;

use Emc\Boerse\Angebotsnummer;
use PHPUnit\Framework\TestCase;

final class AngebotsnummerTest extends TestCase
{
    public function test_formatiere(): void
    {
        $this->assertSame('B2026-118', Angebotsnummer::formatiere(2026, 118));
        $this->assertSame('B2027-1', Angebotsnummer::formatiere(2027, 1));
    }

    public function test_zerlege(): void
    {
        $this->assertSame(['jahr' => 2026, 'lauf' => 118], Angebotsnummer::zerlege(' b2026-0118 '));
        $this->assertSame(['jahr' => 2026, 'lauf' => 118], Angebotsnummer::zerlege('B2026-118'));
        $this->assertSame(['jahr' => 2026, 'lauf' => 118], Angebotsnummer::zerlege('2026-118'));
        $this->assertSame(['jahr' => 2026, 'lauf' => 118], Angebotsnummer::zerlege('B 2026 - 118'));
        $this->assertSame(['jahr' => 2026, 'lauf' => 118], Angebotsnummer::zerlege('118', 2026));
        $this->assertSame(['jahr' => 2026, 'lauf' => 7], Angebotsnummer::zerlege('007', 2026));
        $this->assertSame(['jahr' => 2025, 'lauf' => 3], Angebotsnummer::zerlege('B2025-3', 2026), 'volle Nummer schlägt die Vorgabe');
    }

    public function test_zerlege_ungueltig(): void
    {
        $this->assertNull(Angebotsnummer::zerlege('118'));
        $this->assertNull(Angebotsnummer::zerlege('B26-1'));
        $this->assertNull(Angebotsnummer::zerlege('B2026-0'));
        $this->assertNull(Angebotsnummer::zerlege('0', 2026));
        $this->assertNull(Angebotsnummer::zerlege('B2026-1234567'));
        $this->assertNull(Angebotsnummer::zerlege('1234567', 2026));
        $this->assertNull(Angebotsnummer::zerlege('M12'));
        $this->assertNull(Angebotsnummer::zerlege(''));
        $this->assertNull(Angebotsnummer::zerlege('B2026-12a'));
    }
}
