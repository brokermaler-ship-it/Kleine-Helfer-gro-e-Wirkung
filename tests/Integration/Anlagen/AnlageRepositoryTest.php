<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Anlagen;

use Emc\Anlagen\AnlageRepository;
use Emc\Anlagen\AnlagenCode;
use Emc\Fahrzeuge\KonfliktFehler;
use Emc\Tests\Integration\DbTestCase;

final class AnlageRepositoryTest extends DbTestCase
{
    private AnlageRepository $repo;
    private int $h0;
    private int $n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new AnlageRepository($this->pdo);
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->n = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name <> 'H0' ORDER BY id LIMIT 1")->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function daten(array $u = []): array
    {
        return $u + ['name' => 'Clubanlage', 'spurweite_id' => $this->h0, 'kurzbeschreibung' => null, 'standort' => null, 'status' => 'in_ordnung'];
    }

    public function test_code_fortlaufend_nie_wiederverwendet(): void
    {
        $a1 = $this->repo->anlegen($this->daten());
        $a2 = $this->repo->anlegen($this->daten(['name' => 'Zweite']));
        $this->assertSame('A1', $this->repo->finde($a1)['code']);
        $this->assertSame('A2', $this->repo->finde($a2)['code']);
        $this->repo->loeschen($a2);
        $a3 = $this->repo->anlegen($this->daten(['name' => 'Dritte']));
        $this->assertSame(3, (int) $this->repo->finde($a3)['nummer']);
        $this->assertSame('A3', $this->repo->finde($a3)['code']);
        $this->assertSame($a3, (int) $this->repo->findeNachNummer(3)['id']);
        $this->assertNull($this->repo->findeNachNummer(2));
        $this->assertSame('3', $this->pdo->query("SELECT wert FROM einstellung WHERE schluessel = 'anlage_zaehler'")->fetchColumn());
    }

    public function test_zaehler_und_hoechste_nummer(): void
    {
        $this->assertSame(1, AnlagenCode::naechste($this->pdo));
        AnlagenCode::merke($this->pdo, 9);
        AnlagenCode::merke($this->pdo, 4); // wird nie kleiner
        $this->assertSame(10, AnlagenCode::naechste($this->pdo));
        $this->assertSame('A10', AnlagenCode::formatiere(10));
        $id = $this->repo->anlegen($this->daten());
        $this->assertSame('A10', $this->repo->finde($id)['code']);
    }

    public function test_stand_pruefung(): void
    {
        $id = $this->repo->anlegen($this->daten());
        $stand = (string) $this->repo->finde($id)['geaendert_am'];
        $this->repo->aktualisieren($id, $this->daten(['name' => 'Neu', 'status' => 'defekt']), $stand);
        $a = $this->repo->finde($id);
        $this->assertSame('Neu', $a['name']);
        $this->assertSame('defekt', $a['status']);
        $this->assertNotSame($stand, $a['geaendert_am']);
        try {
            $this->repo->aktualisieren($id, $this->daten(['name' => 'Veraltet']), $stand);
            $this->fail('KonfliktFehler erwartet');
        } catch (KonfliktFehler $e) {
            $this->assertStringContainsString('Diese Anlage wurde inzwischen von jemand anderem geändert', $e->getMessage());
        }
        $this->assertSame('Neu', $this->repo->finde($id)['name']);
        // Unverändert speichern trifft trotzdem eine Zeile (kein Schein-Konflikt).
        $this->repo->aktualisieren($id, $this->daten(['name' => 'Neu', 'status' => 'defekt']), (string) $this->repo->finde($id)['geaendert_am']);
    }

    public function test_titelbild_aendert_stand_nicht(): void
    {
        $id = $this->repo->anlegen($this->daten());
        $stand = (string) $this->repo->finde($id)['geaendert_am'];
        $this->repo->setzeTitelbild($id, 5);
        $a = $this->repo->finde($id);
        $this->assertSame(5, (int) $a['titelbild_dokument_id']);
        $this->assertSame($stand, $a['geaendert_am']);
        $this->repo->setzeTitelbild($id, null);
        $this->assertNull($this->repo->finde($id)['titelbild_dokument_id']);
    }

    public function test_alle_mit_filter_und_spurweite(): void
    {
        $this->repo->anlegen($this->daten(['name' => 'Clubanlage H0', 'standort' => 'Raum 2']));
        $this->repo->anlegen($this->daten(['name' => 'Modul Bahnhof', 'spurweite_id' => $this->n]));
        $alle = $this->repo->alle();
        $this->assertCount(2, $alle);
        $this->assertSame('A1', $alle[0]['code']);
        $this->assertSame('H0', $alle[0]['spurweite_name']);
        $this->assertSame(['Modul Bahnhof'], array_column($this->repo->alle($this->n), 'name'));
        $this->assertSame(['Clubanlage H0'], array_column($this->repo->alle(null, 'raum'), 'name'));
        $this->assertSame(['Modul Bahnhof'], array_column($this->repo->alle(null, 'bahnhof'), 'name'));
        $this->assertSame([], $this->repo->alle(null, '100%'));
        $this->assertNull($this->repo->finde(999));
    }
}
