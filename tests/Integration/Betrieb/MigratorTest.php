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
        $this->assertSame(['001_grundgeruest', '002_spurweiten_start', '003_fahrzeug_angelegt_von', '004_onedrive', '005_onedrive_auftrag_sperre', '006_onedrive_sperr_token_index'], $migrator->ausstehend());
    }

    public function test_ausfuehren_legt_tabellen_an_und_ist_idempotent(): void
    {
        self::alleTabellenLoeschen($this->pdo);
        $migrator = new Migrator($this->pdo, $this->migrationen());
        $this->assertSame(['001_grundgeruest', '002_spurweiten_start', '003_fahrzeug_angelegt_von', '004_onedrive', '005_onedrive_auftrag_sperre', '006_onedrive_sperr_token_index'], $migrator->ausfuehren());
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
}
