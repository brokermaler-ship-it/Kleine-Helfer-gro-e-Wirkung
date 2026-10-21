<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Etiketten;

use Emc\Etiketten\QrCode;
use PHPUnit\Framework\TestCase;

final class QrCodeTest extends TestCase
{
    public function test_svg_ohne_xml_kopf(): void
    {
        $svg = QrCode::svg('https://verein.example/c/A1');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('viewBox', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
        $this->assertStringEndsWith('</svg>', rtrim($svg));
    }

    public function test_gleicher_text_gleiche_ausgabe(): void
    {
        $this->assertSame(QrCode::svg('https://verein.example/c/A1'), QrCode::svg('https://verein.example/c/A1'));
        $this->assertNotSame(QrCode::svg('https://verein.example/c/A1'), QrCode::svg('https://verein.example/c/A2'));
    }
}
