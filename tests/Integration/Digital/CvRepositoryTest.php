<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Digital;

use Emc\Digital\CvRepository;
use Emc\Tests\Integration\DbTestCase;

final class CvRepositoryTest extends DbTestCase
{
    public function test_upsert_und_grenzen(): void
    {
        $d = FunktionRepositoryTest::neuerDecoder($this->pdo);
        $repo = new CvRepository($this->pdo);
        $repo->speichern($d, 29, 6, 'Konfiguration');
        $repo->speichern($d, 3, 10, null);
        $repo->speichern($d, 29, 14, 'Lange Adresse');
        $liste = $repo->fuerDecoder($d);
        $this->assertCount(2, $liste);
        $this->assertSame(3, (int) $liste[0]['cv']);
        $this->assertSame(14, (int) $liste[1]['wert']);
        $this->assertSame('Lange Adresse', $liste[1]['kommentar']);

        $this->assertSame('CV muss zwischen 1 und 1024 liegen.', CvRepository::pruefe(0, 1));
        $this->assertSame('CV muss zwischen 1 und 1024 liegen.', CvRepository::pruefe(1025, 1));
        $this->assertSame('Wert muss zwischen 0 und 255 liegen.', CvRepository::pruefe(1, 256));
        $this->assertNull(CvRepository::pruefe(1024, 255));
        $this->assertNull(CvRepository::pruefe(1, 0));
    }

    public function test_loeschen(): void
    {
        $d = FunktionRepositoryTest::neuerDecoder($this->pdo);
        $repo = new CvRepository($this->pdo);
        $repo->speichern($d, 1, 3, null);
        $repo->loeschen($d, 1);
        $this->assertSame([], $repo->fuerDecoder($d));
    }
}
