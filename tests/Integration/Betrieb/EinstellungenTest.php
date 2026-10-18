<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Betrieb;

use Emc\Betrieb\Einstellungen;
use Emc\Tests\Integration\DbTestCase;

final class EinstellungenTest extends DbTestCase
{
    public function test_setzen_und_lesen(): void
    {
        $e = new Einstellungen($this->pdo);
        $this->assertNull($e->hole('x'));
        $e->setze('x', '1');
        $e->setze('x', '2');
        $this->assertSame('2', $e->hole('x'));
    }
}
