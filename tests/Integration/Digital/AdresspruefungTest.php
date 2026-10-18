<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Digital;

use Emc\Digital\Adresspruefung;
use Emc\Digital\Protokoll;
use Emc\Tests\Integration\DbTestCase;

final class AdresspruefungTest extends DbTestCase
{
    private int $h0;
    private int $n;
    private int $tt;
    private int $nr = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $id = fn(string $name) => (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = '$name'")->fetchColumn();
        $this->h0 = $id('H0');
        $this->n = $id('N');
        $this->tt = $id('TT');
    }

    private function fahrzeug(int $spur, string $status = 'einsatzbereit', string $bez = 'Lok'): int
    {
        $this->nr++;
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, status) VALUES (?, ?, ?, ?)')
            ->execute([sprintf('EMC-%04d', $this->nr), $bez . ' ' . $this->nr, $spur, $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function decoder(int $fz, string $protokoll, ?int $adresse): int
    {
        $this->pdo->prepare('INSERT INTO decoder (fahrzeug_id, bezeichnung, protokoll, adresse) VALUES (?, ?, ?, ?)')
            ->execute([$fz, 'Motor', $protokoll, $adresse]);
        return (int) $this->pdo->lastInsertId();
    }

    public function test_konflikt_nur_in_gleicher_spurweite(): void
    {
        $h0 = $this->fahrzeug($this->h0);
        $nLok = $this->fahrzeug($this->n);
        $this->decoder($h0, 'dcc', 3);
        $this->decoder($nLok, 'dcc', 3);
        $p = new Adresspruefung($this->pdo);

        $k = $p->konflikte($this->h0, Protokoll::Dcc, 3, $nLok);
        $this->assertCount(1, $k);
        $this->assertSame($h0, $k[0]['fahrzeug_id']);
        $this->assertSame('EMC-0001', $k[0]['inventarnummer']);
        $this->assertCount(1, $p->konflikte($this->h0, Protokoll::Dcc, 3));
        $this->assertCount(0, $p->konflikte($this->tt, Protokoll::Dcc, 3));
    }

    public function test_konflikt_nur_bei_gleichem_protokoll(): void
    {
        $this->decoder($this->fahrzeug($this->h0), 'dcc', 3);
        $this->assertCount(0, (new Adresspruefung($this->pdo))->konflikte($this->h0, Protokoll::Motorola, 3));
    }

    public function test_eigenes_fahrzeug_und_ausgemusterte_zaehlen_nicht(): void
    {
        $a = $this->fahrzeug($this->h0);
        $this->decoder($a, 'dcc', 3);
        $this->decoder($a, 'dcc', 3);
        $this->decoder($this->fahrzeug($this->h0, 'ausgemustert'), 'dcc', 3);
        $p = new Adresspruefung($this->pdo);
        $this->assertCount(0, $p->konflikte($this->h0, Protokoll::Dcc, 3, $a));
        $this->assertCount(1, $p->konflikte($this->h0, Protokoll::Dcc, 3));
    }

    public function test_uebersicht_markiert_doppelte(): void
    {
        $this->decoder($this->fahrzeug($this->h0), 'dcc', 3);
        $this->decoder($this->fahrzeug($this->h0), 'dcc', 3);
        $this->decoder($this->fahrzeug($this->h0), 'dcc', 4);
        $this->decoder($this->fahrzeug($this->h0), 'mfx', null);
        $this->decoder($this->fahrzeug($this->n), 'dcc', 3);
        $u = (new Adresspruefung($this->pdo))->uebersicht($this->h0);
        $this->assertSame([true, true, false], array_column($u, 'doppelt'));
        $this->assertSame([3, 3, 4], array_column($u, 'adresse'));
        $this->assertSame('kurz', $u[0]['adressart']);
        $this->assertSame('Verein', $u[0]['eigentuemer_name']);
    }

    public function test_warntext(): void
    {
        $k = [['inventarnummer' => 'EMC-0007', 'bezeichnung' => 'V 200 060'], ['inventarnummer' => 'EMC-0008', 'bezeichnung' => 'E 10']];
        $this->assertSame(
            'Achtung: Adresse 3 (DCC) ist in Spur H0 bereits vergeben an EMC-0007 V 200 060, EMC-0008 E 10.',
            Adresspruefung::warntext(Protokoll::Dcc, 3, 'H0', $k)
        );
        $this->assertNull(Adresspruefung::warntext(Protokoll::Dcc, 3, 'H0', []));
    }
}
