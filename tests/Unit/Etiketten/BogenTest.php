<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Etiketten;

use Emc\Etiketten\Bogen;
use PHPUnit\Framework\TestCase;

final class BogenTest extends TestCase
{
    public function test_felder_je_bogen(): void
    {
        $this->assertSame(189, Bogen::klebchen()->proBogen());
        $this->assertSame(24, Bogen::anlage()->proBogen());
    }

    public function test_boegen_passen_auf_a4(): void
    {
        foreach ([Bogen::klebchen(), Bogen::anlage()] as $b) {
            $rechts = $b->randLinks + $b->spalten * $b->breite + ($b->spalten - 1) * $b->abstandX;
            $unten = $b->randOben + $b->zeilen * $b->hoehe + ($b->zeilen - 1) * $b->abstandY;
            $this->assertLessThanOrEqual(210.0, $rechts);
            $this->assertLessThanOrEqual(297.0, $unten);
        }
        $k = Bogen::klebchen();
        $this->assertSame([25.4, 10.0, 7, 27], [$k->breite, $k->hoehe, $k->spalten, $k->zeilen]);
        // Symmetrisch: linker und rechter Rand gleich (L4731).
        $this->assertEqualsWithDelta($k->randLinks, 210 - ($k->randLinks + 7 * 25.4 + 6 * 2.54), 0.01);
        $a = Bogen::anlage();
        $this->assertSame([70.0, 37.0, 3, 8], [$a->breite, $a->hoehe, $a->spalten, $a->zeilen]);
    }

    public function test_position_eines_feldes(): void
    {
        $k = Bogen::klebchen();
        $this->assertEqualsWithDelta(['links' => 8.48, 'oben' => 13.5], $k->position(0), 0.001);
        // Feld 9 = Zeile 2, Spalte 2.
        $this->assertEqualsWithDelta(['links' => 8.48 + 25.4 + 2.54, 'oben' => 23.5], $k->position(8), 0.001);
        $this->assertEqualsWithDelta(['links' => 8.48 + 6 * 27.94, 'oben' => 13.5 + 26 * 10], $k->position(188), 0.001);
        $a = Bogen::anlage();
        $this->assertEqualsWithDelta(['links' => 140.0, 'oben' => 0.5 + 7 * 37], $a->position(23), 0.001);
    }

    public function test_verteile_ueber_den_bogen_hinaus(): void
    {
        $etiketten = range(1, 10);
        $boegen = Bogen::klebchen()->verteile($etiketten, 185);
        $this->assertCount(2, $boegen);
        $this->assertCount(189, $boegen[0]);
        $this->assertSame(array_fill(0, 184, null), array_slice($boegen[0], 0, 184));
        $this->assertSame([1, 2, 3, 4, 5], array_slice($boegen[0], 184));
        $this->assertSame([6, 7, 8, 9, 10], $boegen[1]);
    }

    public function test_startposition_ausserhalb_gilt_als_eins(): void
    {
        $b = Bogen::klebchen();
        $erwartet = [[1, 2, 3]];
        $this->assertSame($erwartet, $b->verteile([1, 2, 3], 0));
        $this->assertSame($erwartet, $b->verteile([1, 2, 3], 190));
        $this->assertSame($erwartet, $b->verteile([1, 2, 3], -5));
        $this->assertSame([[null, null, 'x']], Bogen::anlage()->verteile(['x'], 3));
        $this->assertSame([['x']], Bogen::anlage()->verteile(['x'], 25));
    }

    public function test_leere_liste_ergibt_keine_boegen(): void
    {
        $this->assertSame([], Bogen::klebchen()->verteile([], 5));
    }

    public function test_genau_ein_voller_bogen(): void
    {
        $boegen = Bogen::anlage()->verteile(range(1, 24), 1);
        $this->assertCount(1, $boegen);
        $boegen = Bogen::anlage()->verteile(range(1, 25), 1);
        $this->assertCount(2, $boegen);
        $this->assertSame([25], $boegen[1]);
    }
}
