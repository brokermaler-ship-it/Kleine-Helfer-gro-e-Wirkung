<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Dateien;

use Emc\Dateien\DokumentRepository;
use Emc\OneDrive\Auftraege;
use Emc\Tests\Integration\DbTestCase;

final class DokumentRepositoryTest extends DbTestCase
{
    private string $speicher;
    private DokumentRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher . '/2026/10', 0750, true);
        $this->repo = new DokumentRepository($this->pdo, $this->speicher, new Auftraege($this->pdo));
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
    }

    private function fahrzeug(string $nummer): int
    {
        $this->pdo->prepare("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) SELECT ?, 'Lok', id FROM spurweite LIMIT 1")
            ->execute([$nummer]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Legt Datensatz und Dateien (Original, Anzeige, Vorschau) an. */
    private function dokument(string $name, string $ablage = 'lokal', ?string $itemId = null): int
    {
        $pfad = '2026/10/' . $name;
        $this->pdo->prepare(
            "INSERT INTO dokument (titel, kategorie, original_name, endung, mime, groesse, pfad, ablage, onedrive_item_id, onedrive_drive_id) VALUES (?, 'foto', ?, 'jpg', 'image/jpeg', 3, ?, ?, ?, ?)"
        )->execute([$name, $name, $pfad, $ablage, $itemId, $itemId === null ? null : 'drive-1']);
        foreach (['', '.anzeige.jpg', '.vorschau.jpg'] as $endung) {
            file_put_contents($this->speicher . '/' . $pfad . $endung, 'abc');
        }
        return (int) $this->pdo->lastInsertId();
    }

    private function anzahlAuftraege(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'loeschen'")->fetchColumn();
    }

    public function test_loesche_datensatz_ohne_transaktion_innerhalb_fremder_transaktion(): void
    {
        $fz = $this->fahrzeug('EMC-0001');
        $id = $this->dokument('a.jpg', 'onedrive', 'item-a');
        $this->repo->zuordnen($id, 'fahrzeug', $fz);
        $this->repo->setzeTitelbild($fz, $id);

        $this->pdo->beginTransaction();
        $pfad = $this->repo->loescheDatensatz($id);
        $this->assertTrue($this->pdo->inTransaction(), 'loescheDatensatz darf die fremde Transaktion nicht beenden');
        $this->assertSame('2026/10/a.jpg', $pfad);
        $this->assertNull($this->repo->finde($id));
        $this->assertSame(1, $this->anzahlAuftraege());
        $this->assertFileExists($this->speicher . '/2026/10/a.jpg', 'Dateien bleiben bis nach dem Commit');
        $this->pdo->rollBack();

        $this->assertNotNull($this->repo->finde($id));
        $this->assertSame([$fz], $this->repo->objekte($id, 'fahrzeug'));
        $this->assertSame(0, $this->anzahlAuftraege());
        $this->assertSame($id, (int) $this->pdo->query("SELECT titelbild_dokument_id FROM fahrzeug WHERE id = $fz")->fetchColumn());
        $this->assertFileExists($this->speicher . '/2026/10/a.jpg');
    }

    public function test_loesche_datensatz_unbekannt_liefert_null(): void
    {
        $this->assertNull($this->repo->loescheDatensatz(999));
    }

    public function test_entferne_dateien_entfernt_original_und_varianten(): void
    {
        $this->dokument('b.jpg');
        $this->repo->entferneDateien('2026/10/b.jpg');
        foreach (['', '.anzeige.jpg', '.vorschau.jpg'] as $endung) {
            $this->assertFileDoesNotExist($this->speicher . '/2026/10/b.jpg' . $endung);
        }
        // Verdächtige Pfade werden ignoriert.
        $this->repo->entferneDateien('../b.jpg');
        $this->repo->entferneDateien('');
        $this->addToAssertionCount(1);
    }

    public function test_ids_fuer_objekt_ohne_geteilte(): void
    {
        $eins = $this->fahrzeug('EMC-0001');
        $zwei = $this->fahrzeug('EMC-0002');
        $a = $this->dokument('a.jpg');
        $b = $this->dokument('b.jpg');
        $c = $this->dokument('c.jpg');
        $this->repo->zuordnen($a, 'fahrzeug', $eins);
        $this->repo->zuordnen($b, 'fahrzeug', $eins);
        $this->repo->zuordnen($b, 'fahrzeug', $zwei);
        $this->repo->zuordnen($c, 'fahrzeug', $eins);
        $this->repo->zuordnen($c, 'anlage', $eins);

        $this->assertSame([$a], $this->repo->idsFuerObjekt('fahrzeug', $eins));
        $this->assertSame([], $this->repo->idsFuerObjekt('fahrzeug', $zwei));
    }

    public function test_loeschen_verhaelt_sich_wie_bisher(): void
    {
        $fz = $this->fahrzeug('EMC-0001');
        $id = $this->dokument('a.jpg', 'onedrive', 'item-a');
        $this->repo->zuordnen($id, 'fahrzeug', $fz);
        $this->repo->setzeTitelbild($fz, $id);

        $this->repo->loeschen($id);

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertNull($this->repo->finde($id));
        $this->assertNull($this->pdo->query("SELECT titelbild_dokument_id FROM fahrzeug WHERE id = $fz")->fetchColumn() ?: null);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument_zuordnung')->fetchColumn());
        $this->assertSame('drive-1', $this->pdo->query("SELECT drive_id FROM onedrive_auftrag WHERE art = 'loeschen'")->fetchColumn());
        $this->assertFileDoesNotExist($this->speicher . '/2026/10/a.jpg');
        $this->assertFileDoesNotExist($this->speicher . '/2026/10/a.jpg.vorschau.jpg');
    }

    public function test_loeschen_behaelt_dateien_wenn_datenbankteil_scheitert(): void
    {
        $fz = $this->fahrzeug('EMC-0001');
        $id = $this->dokument('a.jpg', 'onedrive', 'item-a');
        $this->repo->zuordnen($id, 'fahrzeug', $fz);
        $this->pdo->exec("CREATE TRIGGER test_dokument_sperre BEFORE DELETE ON dokument FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gesperrt'");
        try {
            try {
                $this->repo->loeschen($id);
                $this->fail('Ausnahme erwartet');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('gesperrt', $e->getMessage());
            }
        } finally {
            $this->pdo->exec('DROP TRIGGER IF EXISTS test_dokument_sperre');
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertNotNull($this->repo->finde($id));
        $this->assertSame([$fz], $this->repo->objekte($id, 'fahrzeug'));
        $this->assertSame(0, $this->anzahlAuftraege());
        foreach (['', '.anzeige.jpg', '.vorschau.jpg'] as $endung) {
            $this->assertFileExists($this->speicher . '/2026/10/a.jpg' . $endung);
        }
    }
    /**
     * Ein Foto löschen darf nicht an Zeilen warten, die eine Kasse gerade gesperrt hat (Angebote, Magazinartikel ohne
     * dieses Foto): READ COMMITTED und Indizes auf titelbild_dokument_id sperren nur die passenden Zeilen.
     */
    public function test_loeschen_wartet_nicht_auf_fremd_gesperrte_zeilen(): void
    {
        $kat = (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'")->fetchColumn();
        $this->pdo->exec("INSERT INTO magazin_artikel (bezeichnung, kategorie_id) VALUES ('Gleis', $kat), ('Weiche', $kat)");
        $mitFoto = (int) $this->pdo->query("SELECT id FROM magazin_artikel WHERE bezeichnung = 'Gleis'")->fetchColumn();
        $gesperrt = (int) $this->pdo->query("SELECT id FROM magazin_artikel WHERE bezeichnung = 'Weiche'")->fetchColumn();
        $this->pdo->exec("INSERT INTO boerse_angebot (jahr, laufnummer, bezeichnung, kategorie_id, wunschpreis, herkunft) VALUES (2026, 1, 'Lok', $kat, '5.00', 'verein')");
        $angebot = (int) $this->pdo->lastInsertId();
        $fz = $this->fahrzeug('EMC-0001');
        $id = $this->dokument('a.jpg');
        $this->repo->zuordnen($id, 'magazin_artikel', $mitFoto);
        $this->pdo->exec("UPDATE magazin_artikel SET titelbild_dokument_id = $id WHERE id = $mitFoto");

        $kasse = self::verbindung();
        $kasse->beginTransaction();
        $kasse->query("SELECT id FROM boerse_angebot WHERE id = $angebot FOR UPDATE")->fetchAll();
        $kasse->query("SELECT id FROM magazin_artikel WHERE id = $gesperrt FOR UPDATE")->fetchAll();
        $kasse->query("SELECT id FROM fahrzeug WHERE id = $fz FOR UPDATE")->fetchAll();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->repo->loeschen($id);
        } finally {
            $kasse->rollBack();
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        $this->assertNull($this->repo->finde($id));
        $this->assertNull($this->pdo->query("SELECT titelbild_dokument_id FROM magazin_artikel WHERE id = $mitFoto")->fetchColumn());
    }
}
