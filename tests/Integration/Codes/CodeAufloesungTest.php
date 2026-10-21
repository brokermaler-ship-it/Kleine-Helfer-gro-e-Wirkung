<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Codes;

use Emc\Anlagen\AnlageRepository;
use Emc\Codes\CodeAufloesung;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Magazin\MagazinRepository;
use Emc\Tests\Integration\DbTestCase;

final class CodeAufloesungTest extends DbTestCase
{
    private int $h0;
    private CodeAufloesung $aufloesung;

    protected function setUp(): void
    {
        parent::setUp();
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->aufloesung = new CodeAufloesung(new FahrzeugRepository($this->pdo), new AnlageRepository($this->pdo), new MagazinRepository($this->pdo));
    }

    private function fahrzeug(string $nummer, string $bezeichnung, string $status = 'einsatzbereit'): int
    {
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, status) VALUES (?, ?, ?, ?)')
            ->execute([$nummer, $bezeichnung, $this->h0, $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function decoder(int $fahrzeug, int $adresse, string $protokoll = 'dcc'): void
    {
        $this->pdo->prepare('INSERT INTO decoder (fahrzeug_id, bezeichnung, protokoll, adresse) VALUES (?, ?, ?, ?)')
            ->execute([$fahrzeug, 'Decoder', $protokoll, $adresse]);
    }

    private function anlage(int $nummer, string $name): int
    {
        $this->pdo->prepare('INSERT INTO anlage (nummer, name, spurweite_id) VALUES (?, ?, ?)')->execute([$nummer, $name, $this->h0]);
        return (int) $this->pdo->lastInsertId();
    }

    public function test_fahrzeug_und_anlage_direkt(): void
    {
        $fz = $this->fahrzeug('EMC-0012', 'V 200');
        $anl = $this->anlage(3, 'Clubanlage H0');
        $e = $this->aufloesung->loese('F12');
        $this->assertSame('direkt', $e->art);
        $this->assertSame('/fahrzeuge/' . $fz, $e->ziel);
        $e = $this->aufloesung->loese(' f 012 ');
        $this->assertSame('/fahrzeuge/' . $fz, $e->ziel);
        $e = $this->aufloesung->loese('A3');
        $this->assertSame('direkt', $e->art);
        $this->assertSame('/anlagen/' . $anl, $e->ziel);
        $e = $this->aufloesung->loese('a3');
        $this->assertSame('/anlagen/' . $anl, $e->ziel);
    }

    public function test_unbekannter_code_sucht(): void
    {
        $this->fahrzeug('EMC-0012', 'V 200');
        $e = $this->aufloesung->loese('F99');
        $this->assertSame('suche', $e->art);
        $this->assertNull($e->ziel);
        $this->assertSame('Kein Fahrzeug und keine Anlage mit diesem Code.', $e->meldung);
        $this->assertSame([], $e->fahrzeuge);
        $e = $this->aufloesung->loese('A7');
        $this->assertSame('Kein Fahrzeug und keine Anlage mit diesem Code.', $e->meldung);
    }

    public function test_adresse_mehrere_treffer_auswahl(): void
    {
        $a = $this->fahrzeug('EMC-0001', 'Erste Lok');
        $b = $this->fahrzeug('EMC-0002', 'Zweite Lok', 'ausgemustert');
        $this->fahrzeug('EMC-0003', 'Andere Lok');
        $this->decoder($a, 3);
        $this->decoder($b, 3, 'motorola');
        $this->decoder($b, 3); // gleiches Fahrzeug zweimal → nur einmal gelistet
        $e = $this->aufloesung->loese('0003');
        $this->assertSame('auswahl', $e->art);
        $this->assertSame([$a, $b], array_map(fn(array $f) => (int) $f['id'], $e->fahrzeuge));
        $this->assertSame('Erste Lok', $e->fahrzeuge[0]['bezeichnung']);
        $this->assertSame('H0', $e->fahrzeuge[0]['spurweite_name']);
        $this->assertArrayHasKey('titelbild_dokument_id', $e->fahrzeuge[0]);
        $this->assertSame('ausgemustert', $e->fahrzeuge[1]['status']);
        // Protokolle der passenden Decoder, damit DCC 3 und Motorola 3 unterscheidbar sind.
        $this->assertSame('dcc', $e->fahrzeuge[0]['adress_protokolle']);
        $this->assertSame('dcc,motorola', $e->fahrzeuge[1]['adress_protokolle']);
    }

    public function test_adresse_null_ist_text(): void
    {
        $fz = $this->fahrzeug('EMC-0001', 'Platzhalter 0');
        $this->decoder($fz, 0);
        $this->decoder($this->fahrzeug('EMC-0002', 'Noch ein Platzhalter'), 0);
        foreach (['0', '000'] as $eingabe) {
            $e = $this->aufloesung->loese($eingabe);
            $this->assertSame('suche', $e->art, $eingabe);
            $this->assertNull($e->ziel, $eingabe);
        }
    }

    public function test_textsuche_hoechstens_50_je_art(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $this->fahrzeug(sprintf('EMC-%04d', $i), 'Güterwagen ' . $i);
            $this->anlage($i, 'Güterbahnhof ' . $i);
        }
        $e = $this->aufloesung->loese('Güter');
        $this->assertCount(CodeAufloesung::MAX_TREFFER, $e->fahrzeuge);
        $this->assertCount(CodeAufloesung::MAX_TREFFER, $e->anlagen);
        $this->assertTrue($e->weitereFahrzeuge);
        $this->assertTrue($e->weitereAnlagen);
        $e = $this->aufloesung->loese('Güterwagen 5');
        $this->assertFalse($e->weitereFahrzeuge);
        $this->assertFalse($e->weitereAnlagen);
    }

    public function test_adresse_ein_treffer_auch_ausgemustert_direkt(): void
    {
        $b = $this->fahrzeug('EMC-0002', 'Alte Lok', 'ausgemustert');
        $this->decoder($b, 3);
        $e = $this->aufloesung->loese('3');
        $this->assertSame('direkt', $e->art);
        $this->assertSame('/fahrzeuge/' . $b, $e->ziel);
    }

    public function test_adresse_ohne_treffer(): void
    {
        $this->fahrzeug('EMC-0042', 'Lok 42');
        $e = $this->aufloesung->loese('42');
        $this->assertSame('suche', $e->art);
        $this->assertSame('Kein Fahrzeug mit der Digitaladresse 42.', $e->meldung);
    }

    public function test_inventarnummer_als_text(): void
    {
        $fz = $this->fahrzeug('EMC-0012', 'V 200');
        $this->fahrzeug('EMC-0013', 'BR 01');
        $e = $this->aufloesung->loese('EMC-0012');
        $this->assertSame('suche', $e->art);
        $this->assertSame([$fz], array_map(fn(array $f) => (int) $f['id'], $e->fahrzeuge));
        $this->assertNull($e->meldung);
    }

    public function test_fahrzeug_ohne_emc_nummer_per_text(): void
    {
        $fz = $this->fahrzeug('ALT-7', 'Museumslok');
        $alt = $this->fahrzeug('EMC-0005', 'Museumswagen', 'ausgemustert');
        $e = $this->aufloesung->loese('ALT-7');
        $this->assertSame('suche', $e->art);
        $this->assertSame([$fz], array_map(fn(array $f) => (int) $f['id'], $e->fahrzeuge));
        $e = $this->aufloesung->loese('museum');
        $this->assertSame([$fz, $alt], array_map(fn(array $f) => (int) $f['id'], $e->fahrzeuge));
    }

    public function test_text_findet_anlagen_und_nichts_gefunden(): void
    {
        $anl = $this->anlage(1, 'Clubanlage H0');
        $e = $this->aufloesung->loese('Clubanlage');
        $this->assertSame('suche', $e->art);
        $this->assertSame([$anl], array_map(fn(array $a) => (int) $a['id'], $e->anlagen));
        $e = $this->aufloesung->loese('Gibtsnicht');
        $this->assertSame('Nichts gefunden.', $e->meldung);
    }

    public function test_leer_und_zusatz(): void
    {
        $fz = $this->fahrzeug('EMC-0012', 'V 200');
        $this->assertSame('leer', $this->aufloesung->loese('   ')->art);
        $this->assertSame('/fahrzeuge/' . $fz . '/defekt', $this->aufloesung->loese('F12', '/defekt')->ziel);
    }

    public function test_magazin_code(): void
    {
        $kategorie = (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'")->fetchColumn();
        $this->pdo->prepare('INSERT INTO magazin_artikel (bezeichnung, kategorie_id) VALUES (?, ?)')->execute(['Märklin C-Gleis', $kategorie]);
        $id = (int) $this->pdo->lastInsertId();
        $e = $this->aufloesung->loese('M' . $id);
        $this->assertSame('direkt', $e->art);
        $this->assertSame('/magazin/' . $id, $e->ziel);
        $e = $this->aufloesung->loese(' m 00' . $id . ' ');
        $this->assertSame('/magazin/' . $id, $e->ziel);

        $e = $this->aufloesung->loese('M99999');
        $this->assertSame('suche', $e->art);
        $this->assertNull($e->ziel);
        $this->assertSame('Kein Magazinartikel mit diesem Code.', $e->meldung);
        $this->assertSame(CodeAufloesung::NICHT_GEFUNDEN_MAGAZIN, $e->meldung);

        // „Defekt melden“ gibt es für Magazinartikel nicht: der Code geht in die Suche.
        $e = $this->aufloesung->loese('M' . $id, '/defekt');
        $this->assertSame('suche', $e->art);
        $this->assertNull($e->ziel);
    }

    public function test_magazin_ohne_repository(): void
    {
        $e = (new CodeAufloesung(new FahrzeugRepository($this->pdo), new AnlageRepository($this->pdo)))->loese('M1');
        $this->assertSame('suche', $e->art);
        $this->assertSame('Kein Magazinartikel mit diesem Code.', $e->meldung);
    }
}
