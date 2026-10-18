<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Protokoll;

use Emc\Protokoll\Aenderungsprotokoll;
use PHPUnit\Framework\TestCase;

final class DiffTest extends TestCase
{
    public function test_diff_nur_geaenderte_felder(): void
    {
        $this->assertSame(
            ['status' => ['alt' => 'einsatzbereit', 'neu' => 'defekt']],
            Aenderungsprotokoll::diff(['status' => 'einsatzbereit', 'name' => 'V 200'], ['status' => 'defekt', 'name' => 'V 200'])
        );
        $this->assertSame([], Aenderungsprotokoll::diff(['adresse' => 3], ['adresse' => '3'])); // Zahl vs. Formular-String gilt als gleich
    }

    public function test_diff_nur_schluessel_aus_neu_und_null_gleich_leer(): void
    {
        $this->assertSame([], Aenderungsprotokoll::diff(['a' => 1, 'b' => 2], ['a' => 1]));
        $this->assertSame([], Aenderungsprotokoll::diff(['notiz' => null], ['notiz' => '']));
        $this->assertSame(['x' => ['alt' => null, 'neu' => 'y']], Aenderungsprotokoll::diff([], ['x' => 'y']));
    }
}
