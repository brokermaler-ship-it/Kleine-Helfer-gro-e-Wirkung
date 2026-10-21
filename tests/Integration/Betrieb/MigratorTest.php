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
        $this->assertSame(['001_grundgeruest', '002_spurweiten_start', '003_fahrzeug_angelegt_von', '004_onedrive', '005_onedrive_auftrag_sperre', '006_onedrive_sperr_token_index', '007_onedrive_laufwerk', '008_rolle_fahrzeugwart', '009_abstellgleis', '010_kennwort_vergessen', '011_anlage', '012_wartungsart', '013_wartung', '014_reparatur'], $migrator->ausstehend());
    }

    public function test_ausfuehren_legt_tabellen_an_und_ist_idempotent(): void
    {
        self::alleTabellenLoeschen($this->pdo);
        $migrator = new Migrator($this->pdo, $this->migrationen());
        $this->assertSame(['001_grundgeruest', '002_spurweiten_start', '003_fahrzeug_angelegt_von', '004_onedrive', '005_onedrive_auftrag_sperre', '006_onedrive_sperr_token_index', '007_onedrive_laufwerk', '008_rolle_fahrzeugwart', '009_abstellgleis', '010_kennwort_vergessen', '011_anlage', '012_wartungsart', '013_wartung', '014_reparatur'], $migrator->ausfuehren());
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

    /** Migration 012 legt genau den Startbestand der Wartungsarten an (Spezifikation 5.2). */
    public function test_migration_012_startbestand(): void
    {
        self::alleTabellenLoeschen($this->pdo);
        (new Migrator($this->pdo, $this->migrationen()))->ausfuehren();
        $zeilen = $this->pdo->query('SELECT name, gilt_fuer, intervall_monate, intervall_stunden, aktiv, sortierung FROM wartungsart ORDER BY sortierung')
            ->fetchAll(\PDO::FETCH_NUM);
        $zeilen = array_map(fn(array $z) => [$z[0], $z[1], $z[2] === null ? null : (int) $z[2], $z[3] === null ? null : (int) $z[3], (int) $z[4], (int) $z[5]], $zeilen);
        $this->assertSame([
            ['Räder und Radschleifer reinigen', 'fahrzeug_alle', 6, 20, 1, 10],
            ['Getriebe und Lager schmieren', 'fahrzeug_antrieb', 12, 50, 1, 20],
            ['Haftreifen prüfen/tauschen', 'fahrzeug_antrieb', 12, 50, 1, 30],
            ['Motor/Kohlen prüfen', 'fahrzeug_antrieb', 24, 100, 1, 40],
            ['Kupplungen prüfen', 'fahrzeug_alle', 12, null, 1, 50],
            ['Gleise reinigen', 'anlage', 3, null, 1, 60],
            ['Weichen und Antriebe prüfen', 'anlage', 12, null, 1, 70],
            ['Verkabelung und Stecker prüfen', 'anlage', 12, null, 1, 80],
        ], $zeilen);
    }

    /** Migration 014 legt die Reparaturtabellen an; vorhandene Fahrzeuge und Anlagen gelten als von Hand gesetzt. */
    public function test_migration_014_status_automatisch_fuer_bestand(): void
    {
        self::alleTabellenLoeschen($this->pdo);
        $verz = sys_get_temp_dir() . '/emc_mig_' . uniqid();
        mkdir($verz);
        try {
            foreach (glob($this->migrationen() . '/0*.sql') as $datei) {
                if (basename($datei) < '014') {
                    copy($datei, $verz . '/' . basename($datei));
                }
            }
            $migrator = new Migrator($this->pdo, $verz);
            $migrator->ausfuehren();
            $h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
            $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, status, geaendert_am) VALUES (?, ?, ?, ?, ?)')
                ->execute(['EMC-0001', 'Kaputt', $h0, 'defekt', '2025-11-03 17:45:12.123456']);
            $this->pdo->prepare('INSERT INTO anlage (nummer, name, spurweite_id, status, geaendert_am) VALUES (?, ?, ?, ?, ?)')
                ->execute([1, 'Clubanlage', $h0, 'in_arbeit', '2025-11-04 08:00:00.000000']);
            copy($this->migrationen() . '/014_reparatur.sql', $verz . '/014_reparatur.sql');
            $this->assertSame(['014_reparatur'], $migrator->ausfuehren());

            $this->assertSame([['defekt', 0, '2025-11-03 17:45:12.123456']], array_map(
                fn(array $z) => [$z[0], (int) $z[1], $z[2]],
                $this->pdo->query('SELECT status, status_automatisch, geaendert_am FROM fahrzeug')->fetchAll(\PDO::FETCH_NUM)
            ));
            $this->assertSame([['in_arbeit', 0, '2025-11-04 08:00:00.000000']], array_map(
                fn(array $z) => [$z[0], (int) $z[1], $z[2]],
                $this->pdo->query('SELECT status, status_automatisch, geaendert_am FROM anlage')->fetchAll(\PDO::FETCH_NUM)
            ));
            foreach (['reparatur', 'ersatzteil', 'reparatur_gesehen'] as $tabelle) {
                $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$tabelle'")->fetchColumn(), $tabelle);
            }
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
