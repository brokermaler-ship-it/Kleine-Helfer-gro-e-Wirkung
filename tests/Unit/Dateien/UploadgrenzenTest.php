<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Dateien;

use Emc\Dateien\Uploadgrenzen;
use PHPUnit\Framework\TestCase;

final class UploadgrenzenTest extends TestCase
{
    private const MB = 1048576;

    public function test_kleinster_wert_gilt(): void
    {
        $g = Uploadgrenzen::ausConfig(['upload_max_mb' => 64, 'post_max_bytes' => 8 * self::MB], '2M', '100M');
        $this->assertSame(2 * self::MB, $g->dateiBytes);
        $this->assertSame(8 * self::MB, $g->gesamtBytes);
        // Für die Fotos bleibt etwas Platz für Text und Formularfelder.
        $this->assertSame(8 * self::MB - Uploadgrenzen::RESERVE, $g->fotoBytes());
        $this->assertSame('höchstens 2 MB je Foto, zusammen höchstens 8 MB', $g->text());

        $g = Uploadgrenzen::ausConfig(['upload_max_mb' => 5], '20M', '40M');
        $this->assertSame(5 * self::MB, $g->dateiBytes);
        $this->assertSame(40 * self::MB, $g->gesamtBytes);
    }

    public function test_ohne_grenze_in_php(): void
    {
        $g = Uploadgrenzen::ausConfig([], '0', '0');
        $this->assertSame(64 * self::MB, $g->dateiBytes);
        $this->assertSame(0, $g->gesamtBytes);
        $this->assertSame(0, $g->fotoBytes());
        $this->assertSame('höchstens 64 MB je Foto', $g->text());
    }

    public function test_kleine_grenze_wird_nicht_negativ(): void
    {
        $g = Uploadgrenzen::ausConfig(['post_max_bytes' => 1000], '2M', '8M');
        $this->assertGreaterThan(0, $g->fotoBytes());
        $this->assertSame('höchstens 1 MB je Foto, zusammen höchstens 1 MB', $g->text());
    }
}
