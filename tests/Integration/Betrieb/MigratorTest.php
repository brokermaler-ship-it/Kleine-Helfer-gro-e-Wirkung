<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Betrieb;

use Emc\Betrieb\Migrator;
use Emc\Tests\Integration\DbTestCase;

final class MigratorTest extends DbTestCase
{
    private function migrationen(): string
    {
        return dirname(__DIR__, 3) . '/migrations';
    }

    public function test_frische_datenbank_hat_alle_migrationen_ausstehend(): void
    {
        self::alleTabellenLoeschen($this->pdo);
        $migrator = new Migrator($this->pdo, $this->migrationen());
        $this->assertFalse($migrator->istInstalliert());
        $this->assertSame(['001_grundgeruest', '002_spurweiten_start', '003_fahrzeug_angelegt_von', '004_onedrive', '005_onedrive_auftrag_sperre', '006_onedrive_sperr_token_index', '007_onedrive_laufwerk', '008_rolle_fahrzeugwart', '009_abstellgleis'], $migrator->ausstehend());
    }

    public function test_ausfuehren_legt_tabellen_an_und_ist_idempotent(): void
    {
        self::alleTabellenLoeschen($this->pdo);
        $migrator = new Migrator($this->pdo, $this->migrationen());
        $this->assertSame(['001_grundgeruest', '002_spurweiten_start', '003_fahrzeug_angelegt_von', '004_onedrive', '005_onedrive_auftrag_sperre', '006_onedrive_sperr_token_index', '007_onedrive_laufwerk', '008_rolle_fahrzeugwart', '009_abstellgleis'], $migrator->ausfuehren());
        $this->assertTrue($migrator->istInstalliert());
        $this->assertSame([], $migrator->ausstehend());
        $this->assertSame([], $migrator->ausfuehren());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fahrzeug'")->fetchColumn());
        $this->assertSame(9, (int) $this->pdo->query('SELECT COUNT(*) FROM spurweite')->fetchColumn());
    }

    public function test_fehlerhafte_migration_wird_nicht_als_ausgefuehrt_markiert(): void
    {
        self::alleTabellenLoeschen($this->pdo);
        $verz = sys_get_temp_dir() . '/emc_mig_' . uniqid();
        mkdir($verz);
        file_put_contents($verz . '/001_ok.sql', "CREATE TABLE t1 (id INT) ENGINE=InnoDB;\n");
        file_put_contents($verz . '/002_kaputt.sql', "SELEKT 1;\n");
        try {
            $migrator = new Migrator($this->pdo, $verz);
            try {
                $migrator->ausfuehren();
                $this->fail('PDOException erwartet');
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame(['002_kaputt'], $migrator->ausstehend());
        } finally {
            $this->pdo->exec('DROP TABLE IF EXISTS t1');
            array_map('unlink', glob($verz . '/*.sql'));
            rmdir($verz);
        }
    }

    /** Migration 007 übernimmt das Laufwerk des gespeicherten Zielordners für vorhandene Dokumente und Lösch-Aufträge. */
    public function test_migration_007_fuellt_laufwerk_aus_zielordner(): void
    {
        foreach (['{"drive_id":"LAUFWERK!1","item_id":"ROOT","name":"EMC 78 Fahrzeuge"}' => 'LAUFWERK!1', '' => null, 'kein json' => null] as $ziel => $erwartet) {
            $this->upgradeMitBestand($ziel, $erwartet);
        }
    }

    /** Migration 009 trägt das Abstelldatum für vorhandene ausgemusterte Fahrzeuge nach, ohne geaendert_am zu verändern. */
    public function test_migration_009_setzt_ausgemustert_am_fuer_bestand(): void
    {
        self::alleTabellenLoeschen($this->pdo);
        $verz = sys_get_temp_dir() . '/emc_mig_' . uniqid();
        mkdir($verz);
        try {
            foreach (glob($this->migrationen() . '/00[1-8]_*.sql') as $datei) {
                copy($datei, $verz . '/' . basename($datei));
            }
            $migrator = new Migrator($this->pdo, $verz);
            $migrator->ausfuehren();
            $h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
            $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, status, geaendert_am) VALUES (?, ?, ?, ?, ?)')
                ->execute(['EMC-0001', 'Alt', $h0, 'ausgemustert', '2025-11-03 17:45:12.123456']);
            $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, status, geaendert_am) VALUES (?, ?, ?, ?, ?)')
                ->execute(['EMC-0002', 'Aktiv', $h0, 'einsatzbereit', '2025-11-04 08:00:00.000000']);
            copy($this->migrationen() . '/009_abstellgleis.sql', $verz . '/009_abstellgleis.sql');
            $this->assertSame(['009_abstellgleis'], $migrator->ausfuehren());

            $zeilen = $this->pdo->query('SELECT inventarnummer, ausgemustert_am, geaendert_am FROM fahrzeug ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
            $this->assertSame([
                ['inventarnummer' => 'EMC-0001', 'ausgemustert_am' => '2025-11-03', 'geaendert_am' => '2025-11-03 17:45:12.123456'],
                ['inventarnummer' => 'EMC-0002', 'ausgemustert_am' => null, 'geaendert_am' => '2025-11-04 08:00:00.000000'],
            ], $zeilen);
        } finally {
            array_map('unlink', glob($verz . '/*.sql'));
            rmdir($verz);
            self::alleTabellenLoeschen($this->pdo);
            (new Migrator($this->pdo, $this->migrationen()))->ausfuehren();
        }
    }

    private function upgradeMitBestand(string $ziel, ?string $erwartet): void
    {
        self::alleTabellenLoeschen($this->pdo);
        $verz = sys_get_temp_dir() . '/emc_mig_' . uniqid();
        mkdir($verz);
        try {
            foreach (glob($this->migrationen() . '/00[1-6]_*.sql') as $datei) {
                copy($datei, $verz . '/' . basename($datei));
            }
            $migrator = new Migrator($this->pdo, $verz);
            $migrator->ausfuehren();
            $this->pdo->prepare("INSERT INTO einstellung (schluessel, wert) VALUES ('onedrive_ziel', ?)")->execute([$ziel]);
            $this->pdo->exec("INSERT INTO dokument (titel, kategorie, original_name, endung, mime, groesse, pfad, ablage, onedrive_item_id, original_lokal) VALUES "
                . "('Lok', 'foto', 'a.jpg', 'jpg', 'image/jpeg', 1, 'dateien/a', 'onedrive', 'ITEM!1', 0), "
                . "('Plan', 'anleitung', 'b.pdf', 'pdf', 'application/pdf', 1, 'dateien/b', 'lokal', NULL, 1)");
            $this->pdo->exec("INSERT INTO onedrive_auftrag (art, dokument_id, item_id, status, naechster_versuch) VALUES "
                . "('loeschen', NULL, 'ITEM!2', 'wartet', NOW()), ('loeschen', NULL, 'ITEM!3', 'erledigt', NOW()), ('hochladen', 2, NULL, 'wartet', NOW())");
            copy($this->migrationen() . '/007_onedrive_laufwerk.sql', $verz . '/007_onedrive_laufwerk.sql');
            $this->assertSame(['007_onedrive_laufwerk'], $migrator->ausfuehren());

            $dok = $this->pdo->query('SELECT onedrive_drive_id FROM dokument ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertSame([$erwartet, null], $dok);
            $auf = $this->pdo->query('SELECT drive_id FROM onedrive_auftrag ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertSame([$erwartet, null, null], $auf);
        } finally {
            array_map('unlink', glob($verz . '/*.sql'));
            rmdir($verz);
            self::alleTabellenLoeschen($this->pdo);
            (new Migrator($this->pdo, $this->migrationen()))->ausfuehren();
        }
    }
}
