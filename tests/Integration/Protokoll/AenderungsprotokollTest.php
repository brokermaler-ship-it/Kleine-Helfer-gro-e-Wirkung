<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Protokoll;

use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\DbTestCase;

final class AenderungsprotokollTest extends DbTestCase
{
    public function test_schreiben_und_lesen_neueste_zuerst_mit_anzeigename(): void
    {
        $bid = $this->legeBenutzerAn('max@verein.de');
        $p = new Aenderungsprotokoll($this->pdo);
        $p->schreibe($bid, 'person', 5, 'angelegt', ['vorname' => ['alt' => null, 'neu' => 'Anna']]);
        $p->schreibe(null, 'person', 5, 'geaendert', ['vorname' => ['alt' => 'Anna', 'neu' => 'Berta']]);
        $p->schreibe($bid, 'person', 6, 'angelegt');

        $liste = $p->fuerObjekt('person', 5);
        $this->assertCount(2, $liste);
        $this->assertSame('geaendert', $liste[0]['aktion']);
        $this->assertNull($liste[0]['anzeigename']);
        $this->assertSame('Max Muster', $liste[1]['anzeigename']);
        $this->assertSame(['vorname' => ['alt' => null, 'neu' => 'Anna']], $liste[1]['aenderungen']);
    }
}
