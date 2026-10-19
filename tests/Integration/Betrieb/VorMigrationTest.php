<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Betrieb;

use Emc\Betrieb\Einstellungen;
use Emc\Betrieb\Migrator;
use Emc\Betrieb\Sicherung;
use Emc\Dateien\DokumentRepository;
use Emc\OneDrive\Auftraege;
use Emc\Tests\Integration\DbTestCase;

/**
 * Neue Programmdateien sind hochgeladen, das Datenbank-Update aber noch nicht ausgeführt:
 * Sicherung und Dateiliste dürfen dann nicht mit einem Fehler 500 abbrechen.
 */
final class VorMigrationTest extends DbTestCase
{
    protected function tearDown(): void
    {
        // Schema für die folgenden Testklassen wiederherstellen.
        self::alleTabellenLoeschen($this->pdo);
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->ausfuehren();
    }

    private function stand003(): void
    {
        $this->pdo->exec('DROP TABLE onedrive_auftrag');
        $this->pdo->exec('ALTER TABLE dokument DROP COLUMN ablage, DROP COLUMN onedrive_item_id, DROP COLUMN onedrive_drive_id, DROP COLUMN onedrive_pfad, DROP COLUMN original_lokal');
        $this->pdo->exec("DELETE FROM schema_migration WHERE version >= '004'");
    }

    private function dokumentMitFahrzeug(): array
    {
        $this->pdo->exec("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) SELECT 'EMC-0001', 'V 200', id FROM spurweite LIMIT 1");
        $fz = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO dokument (titel, kategorie, original_name, endung, mime, groesse, pfad) VALUES ('Plan', 'anleitung', 'p.pdf', 'pdf', 'application/pdf', 1, 'dateien/p')");
        $dok = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) VALUES ($dok, 'fahrzeug', $fz)");
        return [$fz, $dok];
    }

    public function test_sicherung_vor_migration_004_ohne_fehler(): void
    {
        $this->stand003();
        $s = new Sicherung($this->pdo, sys_get_temp_dir(), new Einstellungen($this->pdo));
        self::assertSame(['anzahl' => 0, 'ziel' => ''], $s->nurImOneDrive());
    }

    public function test_dateiliste_vor_migration_004_ohne_fehler(): void
    {
        [$fz, $dok] = $this->dokumentMitFahrzeug();
        $this->stand003();
        $liste = (new DokumentRepository($this->pdo, sys_get_temp_dir(), new Auftraege($this->pdo)))->fuerObjekt('fahrzeug', $fz);
        self::assertCount(1, $liste);
        self::assertSame($dok, (int) $liste[0]['id']);
        self::assertSame(0, (int) $liste[0]['onedrive_offen']);
    }

    public function test_loeschen_vor_migration_007_legt_auftrag_ohne_laufwerk_an(): void
    {
        [, $dok] = $this->dokumentMitFahrzeug();
        $this->pdo->exec("UPDATE dokument SET ablage = 'onedrive', onedrive_item_id = 'ITEM' WHERE id = $dok");
        $this->pdo->exec('ALTER TABLE dokument DROP COLUMN onedrive_drive_id');
        $this->pdo->exec('ALTER TABLE onedrive_auftrag DROP COLUMN drive_id');
        (new DokumentRepository($this->pdo, sys_get_temp_dir(), new Auftraege($this->pdo)))->loeschen($dok);
        self::assertSame('ITEM', $this->pdo->query("SELECT item_id FROM onedrive_auftrag WHERE art = 'loeschen'")->fetchColumn());
    }
}
