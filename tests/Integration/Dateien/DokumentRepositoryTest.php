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
}
