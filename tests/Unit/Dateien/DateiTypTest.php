<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Dateien;

use Emc\Dateien\DateiTyp;
use PHPUnit\Framework\TestCase;

final class DateiTypTest extends TestCase
{
    public function test_positivliste(): void
    {
        $this->assertNotNull(DateiTyp::fuerEndung('JPEG'));
        $this->assertNull(DateiTyp::fuerEndung('php'));
        $this->assertNull(DateiTyp::fuerEndung('phtml'));
        $this->assertNull(DateiTyp::fuerEndung('svg'));
        $this->assertNull(DateiTyp::fuerEndung('html'));
        $this->assertNull(DateiTyp::fuerEndung(''));
    }

    public function test_eigenschaften(): void
    {
        $jpg = DateiTyp::fuerEndung('jpg');
        $this->assertSame(['image/jpeg'], $jpg['mimes']);
        $this->assertSame('inline', $jpg['anzeige']);
        $this->assertTrue($jpg['bild']);
        $pdf = DateiTyp::fuerEndung('pdf');
        $this->assertFalse($pdf['bild']);
        $this->assertSame('inline', $pdf['anzeige']);
        $docx = DateiTyp::fuerEndung('docx');
        $this->assertSame('download', $docx['anzeige']);
        $this->assertContains('application/zip', $docx['mimes']);
        $this->assertContains('text/csv', DateiTyp::fuerEndung('csv')['mimes']);
    }

    public function test_accept_attribut_enthaelt_alle_endungen(): void
    {
        $accept = DateiTyp::acceptAttribut();
        $this->assertStringContainsString('.jpg', $accept);
        $this->assertStringContainsString('.pdf', $accept);
        $this->assertStringNotContainsString('.php', $accept);
    }
}
