<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Digital;

use Emc\Digital\AdressValidator;
use Emc\Digital\Protokoll;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdressValidatorTest extends TestCase
{
    #[DataProvider('grenzen')]
    public function test_grenzen(Protokoll $p, ?int $a, bool $ok): void
    {
        $this->assertSame($ok, AdressValidator::pruefe($p, $a) === null);
    }

    public static function grenzen(): array
    {
        return [
            [Protokoll::Dcc, 1, true], [Protokoll::Dcc, 10239, true], [Protokoll::Dcc, 0, false],
            [Protokoll::Dcc, 10240, false], [Protokoll::Dcc, null, false],
            [Protokoll::Motorola, 1, true], [Protokoll::Motorola, 255, true], [Protokoll::Motorola, 256, false],
            [Protokoll::Motorola, 0, false], [Protokoll::Motorola, null, false],
            [Protokoll::Selectrix, 0, true], [Protokoll::Selectrix, 111, true], [Protokoll::Selectrix, 112, false],
            [Protokoll::Selectrix, null, false],
            [Protokoll::Mfx, null, true], [Protokoll::Mfx, 1, true], [Protokoll::Mfx, 16383, true],
            [Protokoll::Mfx, 16384, false], [Protokoll::Mfx, 0, false],
        ];
    }

    public function test_meldungen(): void
    {
        $this->assertSame('Bitte eine Adresse eingeben.', AdressValidator::pruefe(Protokoll::Dcc, null));
        $this->assertSame('Für DCC sind Adressen von 1 bis 10239 möglich.', AdressValidator::pruefe(Protokoll::Dcc, 0));
        $this->assertSame('Für Motorola sind Adressen von 1 bis 255 möglich.', AdressValidator::pruefe(Protokoll::Motorola, 256));
        $this->assertSame('Für Selectrix sind Adressen von 0 bis 111 möglich.', AdressValidator::pruefe(Protokoll::Selectrix, 112));
        $this->assertSame('Für mfx sind Adressen von 1 bis 16383 möglich.', AdressValidator::pruefe(Protokoll::Mfx, 16384));
    }

    public function test_adressart(): void
    {
        $this->assertSame('kurz', AdressValidator::adressart(Protokoll::Dcc, 127));
        $this->assertSame('lang', AdressValidator::adressart(Protokoll::Dcc, 128));
        $this->assertNull(AdressValidator::adressart(Protokoll::Motorola, 5));
    }
}
