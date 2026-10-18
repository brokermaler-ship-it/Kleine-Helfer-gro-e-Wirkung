<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Fahrzeuge;

use Emc\Fahrzeuge\FahrzeugFilter;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Fahrzeuge\KonfliktFehler;
use Emc\Tests\Integration\DbTestCase;

final class FahrzeugRepositoryTest extends DbTestCase
{
    private FahrzeugRepository $repo;
    private int $spurweite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new FahrzeugRepository($this->pdo);
        $this->spurweite = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function daten(array $ueberschreibe = []): array
    {
        return $ueberschreibe + [
            'bezeichnung' => 'V 200', 'baureihe' => null, 'bahnverwaltung' => null, 'epoche' => null, 'gattung' => 'lok',
            'hersteller' => null, 'artikelnummer' => null, 'spurweite_id' => $this->spurweite, 'stromsystem' => '2L',
            'eigentuemer_person_id' => null, 'status' => 'einsatzbereit', 'betriebsstunden' => 0.0, 'notiz' => null,
        ];
    }

    private function filter(array $a = []): FahrzeugFilter
    {
        return new FahrzeugFilter($a['text'] ?? null, $a['spurweiteId'] ?? null, $a['eigentuemer'] ?? null, $a['personId'] ?? null, $a['status'] ?? null, $a['gattung'] ?? null);
    }

    public function test_inventarnummern_fortlaufend_und_nie_wiederverwendet(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->repo->anlegen($this->daten());
        }
        $nummern = $this->pdo->query('SELECT inventarnummer FROM fahrzeug ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['EMC-0001', 'EMC-0002', 'EMC-0003'], $nummern);

        $this->repo->loeschen($ids[2]);
        $neu = $this->repo->anlegen($this->daten());
        $this->assertSame('EMC-0004', $this->repo->finde($neu)['inventarnummer']);
    }

    public function test_inventarnummer_wird_fuenfstellig_ab_10000(): void
    {
        $this->pdo->prepare("INSERT INTO einstellung (schluessel, wert) VALUES ('inventarnummer_zaehler', '9998')")->execute();
        $a = $this->repo->anlegen($this->daten());
        $b = $this->repo->anlegen($this->daten());
        $this->assertSame('EMC-9999', $this->repo->finde($a)['inventarnummer']);
        $this->assertSame('EMC-10000', $this->repo->finde($b)['inventarnummer']);
    }

    public function test_hoechste_vorhandene_nummer_zaehlt_auch_ohne_zaehler(): void
    {
        $this->pdo->prepare("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) VALUES ('EMC-0042', 'Alt', ?)")->execute([$this->spurweite]);
        $id = $this->repo->anlegen($this->daten());
        $this->assertSame('EMC-0043', $this->repo->finde($id)['inventarnummer']);
    }

    public function test_suche_nach_text_und_eigentuemer(): void
    {
        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('Anna', 'Bauer')");
        $pid = (int) $this->pdo->lastInsertId();
        $this->repo->anlegen($this->daten(['bezeichnung' => 'V 200']));
        $this->repo->anlegen($this->daten(['bezeichnung' => 'BR 01', 'eigentuemer_person_id' => $pid]));

        $r = $this->repo->suche($this->filter(['text' => 'BR 01']));
        $this->assertCount(1, $r);
        $this->assertSame('BR 01', $r[0]['bezeichnung']);
        $this->assertSame('Anna Bauer', $r[0]['eigentuemer_name']);
        $this->assertSame('H0', $r[0]['spurweite_name']);

        $v = $this->repo->suche($this->filter(['eigentuemer' => 'verein']));
        $this->assertSame(['V 200'], array_column($v, 'bezeichnung'));
        $m = $this->repo->suche($this->filter(['eigentuemer' => 'mitglied']));
        $this->assertSame(['BR 01'], array_column($m, 'bezeichnung'));
        $p = $this->repo->suche($this->filter(['personId' => $pid]));
        $this->assertSame(['BR 01'], array_column($p, 'bezeichnung'));
        $this->assertSame([], $this->repo->suche($this->filter(['text' => '100%'])));
        $this->assertNull($v[0]['eigentuemer_name']);
        $this->assertArrayHasKey('adressen', $v[0]);
        $this->assertArrayHasKey('titelbild_dokument_id', $v[0]);
    }

    public function test_adressen_aus_decodern(): void
    {
        $id = $this->repo->anlegen($this->daten());
        $this->pdo->prepare("INSERT INTO decoder (fahrzeug_id, bezeichnung, protokoll, adresse) VALUES (?, 'Motor', 'dcc', 33), (?, 'Sound', 'dcc', 34)")->execute([$id, $id]);
        $r = $this->repo->suche($this->filter());
        $this->assertSame('33, 34', $r[0]['adressen']);
    }

    public function test_ausgemusterte_standardmaessig_ausgeblendet(): void
    {
        $this->repo->anlegen($this->daten(['bezeichnung' => 'Aktiv']));
        $this->repo->anlegen($this->daten(['bezeichnung' => 'Alt', 'status' => 'ausgemustert']));
        $this->assertSame(['Aktiv'], array_column($this->repo->suche($this->filter()), 'bezeichnung'));
        $this->assertSame(['Alt'], array_column($this->repo->suche($this->filter(['status' => 'ausgemustert'])), 'bezeichnung'));
    }

    public function test_gleichzeitige_aenderung_wird_erkannt(): void
    {
        $id = $this->repo->anlegen($this->daten());
        $stand = $this->repo->finde($id)['geaendert_am'];
        $this->repo->aktualisieren($id, ['bezeichnung' => 'Erste Änderung'] + $this->daten(), $stand);
        $this->assertSame('Erste Änderung', $this->repo->finde($id)['bezeichnung']);
        $this->expectException(KonfliktFehler::class);
        $this->repo->aktualisieren($id, ['bezeichnung' => 'Zweite Änderung'] + $this->daten(), $stand);
    }

    public function test_unveraendert_speichern_ist_kein_konflikt(): void
    {
        $id = $this->repo->anlegen($this->daten());
        $stand = $this->repo->finde($id)['geaendert_am'];
        $this->repo->aktualisieren($id, $this->daten(), $stand);
        $stand2 = $this->repo->finde($id)['geaendert_am'];
        $this->repo->aktualisieren($id, $this->daten(), $stand2);
        $this->assertSame('V 200', $this->repo->finde($id)['bezeichnung']);
    }

    public function test_aktualisieren_geloeschtes_fahrzeug_wirft_konflikt(): void
    {
        $id = $this->repo->anlegen($this->daten());
        $stand = $this->repo->finde($id)['geaendert_am'];
        $this->repo->loeschen($id);
        $this->expectException(KonfliktFehler::class);
        $this->repo->aktualisieren($id, $this->daten(), $stand);
    }
}
