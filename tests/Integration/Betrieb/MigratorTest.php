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
        $this->assertSame(['001_grundgeruest', '002_spurweiten_start', '003_fahrzeug_angelegt_von', '004_onedrive', '005_onedrive_auftrag_sperre', '006_onedrive_sperr_token_index', '007_onedrive_laufwerk', '008_rolle_fahrzeugwart', '009_abstellgleis', '010_kennwort_vergessen', '011_anlage', '012_wartungsart', '013_wartung', '014_reparatur', '015_etikett', '016_magazin', '017_boerse', '018_titelbild_index', '019_boerse_anfrage'], $migrator->ausstehend());
    }

    public function test_ausfuehren_legt_tabellen_an_und_ist_idempotent(): void
    {
        self::alleTabellenLoeschen($this->pdo);
        $migrator = new Migrator($this->pdo, $this->migrationen());
        $this->assertSame(['001_grundgeruest', '002_spurweiten_start', '003_fahrzeug_angelegt_von', '004_onedrive', '005_onedrive_auftrag_sperre', '006_onedrive_sperr_token_index', '007_onedrive_laufwerk', '008_rolle_fahrzeugwart', '009_abstellgleis', '010_kennwort_vergessen', '011_anlage', '012_wartungsart', '013_wartung', '014_reparatur', '015_etikett', '016_magazin', '017_boerse', '018_titelbild_index', '019_boerse_anfrage'], $migrator->ausfuehren());
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

    /** Migration 016: Börsenwart-Häkchen (bestehende Benutzer ohne) und Magazin mit dem Startbestand der Kategorien. */
    public function test_migration_016_magazin(): void
    {
        self::alleTabellenLoeschen($this->pdo);
        $verz = sys_get_temp_dir() . '/emc_mig_' . uniqid();
        mkdir($verz);
        try {
            foreach (glob($this->migrationen() . '/0*.sql') as $datei) {
                if (basename($datei) < '016') {
                    copy($datei, $verz . '/' . basename($datei));
                }
            }
            $migrator = new Migrator($this->pdo, $verz);
            $migrator->ausfuehren();
            $this->legeBenutzerAn('alt@verein.de', 'geheim-passwort-1', 'fahrzeugwart');
            $this->legeBenutzerAn('chef@verein.de', 'geheim-passwort-1', 'admin');
            copy($this->migrationen() . '/016_magazin.sql', $verz . '/016_magazin.sql');
            $this->assertSame(['016_magazin'], $migrator->ausfuehren());

            $this->assertSame(['0', '0'], array_map('strval', $this->pdo->query('SELECT ist_boersenwart FROM benutzer ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)));
            $kategorien = $this->pdo->query('SELECT name, schluessel FROM magazin_kategorie ORDER BY sortierung, id')->fetchAll(\PDO::FETCH_NUM);
            $this->assertSame([
                ['Gleise und Weichen', null],
                ['Fahrzeuge', 'fahrzeuge'],
                ['Elektrik und Digital', null],
                ['Gebäude und Bausätze', null],
                ['Landschaft und Figuren', null],
                ['Ersatzteile', null],
                ['Werkzeug', null],
                ['Literatur', 'literatur'],
                ['Sonstiges', 'sonstiges'],
            ], $kategorien);
            foreach (['magazin_kategorie', 'magazin_artikel', 'magazin_sammlung', 'magazin_sammlung_artikel', 'magazin_buchung'] as $tabelle) {
                $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$tabelle'")->fetchColumn(), $tabelle);
            }
        } finally {
            array_map('unlink', glob($verz . '/*.sql'));
            rmdir($verz);
            self::alleTabellenLoeschen($this->pdo);
            (new Migrator($this->pdo, $this->migrationen()))->ausfuehren();
        }
    }

    /** Eindeutigkeiten und Fremdschlüssel aus 016, auf die sich die Dienste verlassen. */
    public function test_migration_016_regeln(): void
    {
        $sonst = (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'")->fetchColumn();
        $this->assertGreaterThan(0, $sonst, 'Startbestand der Kategorien bleibt zwischen den Tests erhalten');
        $h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, status) VALUES (?, ?, ?, ?)')->execute(['EMC-0001', 'Köf', $h0, 'ausgemustert']);
        $fz = (int) $this->pdo->lastInsertId();
        $artikel = $this->pdo->prepare('INSERT INTO magazin_artikel (bezeichnung, kategorie_id, fahrzeug_id) VALUES (?, ?, ?)');
        $artikel->execute(['Köf', $sonst, $fz]);
        $a1 = (int) $this->pdo->lastInsertId();
        $artikel->execute(['Gleis', $sonst, null]);
        $a2 = (int) $this->pdo->lastInsertId();
        $this->assertSame('gebraucht', $this->pdo->query("SELECT zustand FROM magazin_artikel WHERE id = $a1")->fetchColumn());
        $this->assertDoppelt(fn() => $artikel->execute(['Köf 2', $sonst, $fz]), 'ein Fahrzeug höchstens einmal im Magazin');

        $this->pdo->exec("INSERT INTO magazin_sammlung (name) VALUES ('Kiste 1'), ('Kiste 2')");
        $this->pdo->exec("INSERT INTO magazin_sammlung_artikel (sammlung_id, artikel_id) VALUES (1, $a1)");
        $this->assertDoppelt(fn() => $this->pdo->exec("INSERT INTO magazin_sammlung_artikel (sammlung_id, artikel_id) VALUES (2, $a1)"), 'ein Artikel in höchstens einer Sammlung');

        $buchung = $this->pdo->prepare("INSERT INTO magazin_buchung (artikel_id, art, datum, menge, herkunft, betrag, gegenbuchung_zu_id, erstellt_am) VALUES (?, ?, '2026-10-01', ?, ?, ?, ?, NOW())");
        $buchung->execute([$a2, 'eingang', 5, 'spende', '12.50', null]);
        $b1 = (int) $this->pdo->lastInsertId();
        $buchung->execute([$a2, 'eingang', -5, 'spende', null, $b1]);
        $this->assertDoppelt(fn() => $buchung->execute([$a2, 'eingang', -5, 'spende', null, $b1]), 'höchstens eine Gegenbuchung je Buchung');
        $this->assertSame('12.50', $this->pdo->query("SELECT betrag FROM magazin_buchung WHERE id = $b1")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT SUM(menge) FROM magazin_buchung WHERE artikel_id = $a2")->fetchColumn());

        try {
            $this->pdo->exec("DELETE FROM magazin_artikel WHERE id = $a2");
            $this->fail('Artikel mit Buchungen darf nicht gelöscht werden');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
        try {
            $this->pdo->exec("DELETE FROM magazin_kategorie WHERE id = $sonst");
            $this->fail('benutzte Kategorie darf nicht gelöscht werden');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
        $this->pdo->exec("DELETE FROM fahrzeug WHERE id = $fz");
        $this->assertNull($this->pdo->query("SELECT fahrzeug_id FROM magazin_artikel WHERE id = $a1")->fetchColumn());
    }

    /** Migration 017: Börse, Angebote mit Nummer je Jahr, Verkäufe, Auszahlungen und der Fremdschlüssel der Kassenbuchung. */
    public function test_migration_017_boerse(): void
    {
        foreach (['boerse', 'boerse_angebot_zaehler', 'boerse_angebot', 'boerse_verkauf', 'boerse_verkauf_position', 'boerse_auszahlung'] as $tabelle) {
            $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$tabelle'")->fetchColumn(), $tabelle);
        }
        $this->pdo->exec("INSERT INTO boerse (name, datum) VALUES ('Herbstbörse', '2026-11-08')");
        $boerse = $this->pdo->query('SELECT * FROM boerse')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['10.00', 'in_vorbereitung'], [$boerse['provision_prozent'], $boerse['status']]);
        $sonst = (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'")->fetchColumn();
        $angebot = $this->pdo->prepare("INSERT INTO boerse_angebot (jahr, laufnummer, bezeichnung, kategorie_id, wunschpreis, herkunft) VALUES (2026, ?, 'Lok', ?, '12.50', 'verein')");
        $angebot->execute([1, $sonst]);
        $a = (int) $this->pdo->lastInsertId();
        $z = $this->pdo->query("SELECT status, oeffentlich, menge, wunschpreis, erloes_spende FROM boerse_angebot WHERE id = $a")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['angeboten', 'nein', '1', '12.50', '0'], array_map('strval', array_values($z)));
        $this->assertDoppelt(fn() => $angebot->execute([1, $sonst]), 'Nummer je Jahr eindeutig');

        // Kassenbuchung → Position: Fremdschlüssel aus 017 (SET NULL).
        $this->pdo->exec("INSERT INTO magazin_artikel (bezeichnung, kategorie_id) VALUES ('Gleis', $sonst)");
        $artikel = (int) $this->pdo->lastInsertId();
        try {
            $this->pdo->exec("INSERT INTO magazin_buchung (artikel_id, art, datum, menge, verkauf_position_id, erstellt_am) VALUES ($artikel, 'ausgang', '2026-11-08', -1, 999, NOW())");
            $this->fail('verkauf_position_id ohne Position');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
        $this->pdo->exec("INSERT INTO boerse_verkauf (boerse_id, summe, zahlart, verkauft_am) VALUES ({$boerse['id']}, '12.50', 'bar', NOW())");
        $verkauf = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO boerse_verkauf_position (verkauf_id, angebot_id, preis, provision, herkunft, erloes_spende) VALUES ($verkauf, $a, '12.50', '0.00', 'verein', 0)");
        $position = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO magazin_buchung (artikel_id, art, datum, menge, verkauf_position_id, erstellt_am) VALUES ($artikel, 'ausgang', '2026-11-08', -1, $position, NOW())");
        $this->pdo->exec("DELETE FROM boerse_verkauf WHERE id = $verkauf");
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM boerse_verkauf_position')->fetchColumn(), 'Positionen gehören zum Verkauf');
        $this->assertNull($this->pdo->query("SELECT verkauf_position_id FROM magazin_buchung WHERE artikel_id = $artikel")->fetchColumn());

        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('Carla', 'Beispiel')");
        $person = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO boerse_auszahlung (boerse_id, person_id, betrag, ausgezahlt_am) VALUES ({$boerse['id']}, $person, '11.29', '2026-11-08')");
        $this->assertDoppelt(fn() => $this->pdo->exec("INSERT INTO boerse_auszahlung (boerse_id, person_id, betrag, ausgezahlt_am) VALUES ({$boerse['id']}, $person, '1.00', '2026-11-08')"), 'eine Auszahlung je Person und Börse');
        $this->pdo->exec("INSERT INTO boerse_verkauf (boerse_id, summe, zahlart, verkauft_am) VALUES ({$boerse['id']}, '12.50', 'bar', NOW())");
        $verkauf = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO boerse_verkauf_position (verkauf_id, angebot_id, preis, provision, herkunft, erloes_spende) VALUES ($verkauf, $a, '12.50', '0.00', 'verein', 0)");
        try {
            $this->pdo->exec("DELETE FROM boerse_angebot WHERE id = $a");
            $this->fail('verkauftes Angebot (Position) darf nicht gelöscht werden');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode());
        }
    }

    /** Migration 018: Indizes auf titelbild_dokument_id (Foto löschen sperrt nur die passenden Zeilen). */
    public function test_migration_018_titelbild_index(): void
    {
        foreach (['fahrzeug', 'anlage', 'magazin_artikel', 'boerse_angebot'] as $tabelle) {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'titelbild_dokument_id' AND seq_in_index = 1");
            $st->execute([$tabelle]);
            $this->assertSame(1, (int) $st->fetchColumn(), $tabelle);
        }
    }

    /** Migration 019: Anfragen aus dem öffentlichen Bereich und gehashte Sperrzeilen. */
    public function test_migration_019_boerse_anfrage(): void
    {
        $spalten = $this->pdo->query("SELECT column_name, is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'boerse_anfrage' ORDER BY ordinal_position")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(['id', 'art', 'angebot_id', 'name', 'email', 'telefon', 'nachricht', 'wunsch', 'einwilligung_am', 'eingegangen_am', 'status', 'bearbeitet_von_benutzer_id', 'magazin_artikel_id', 'geaendert_am'], array_keys($spalten));
        $this->assertSame('YES', $spalten['angebot_id']);
        $this->assertSame('NO', $spalten['einwilligung_am']);
        $regeln = $this->pdo->query("SELECT referenced_table_name, delete_rule FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'boerse_anfrage' ORDER BY referenced_table_name")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(['benutzer' => 'SET NULL', 'boerse_angebot' => 'SET NULL', 'magazin_artikel' => 'SET NULL'], $regeln);
        $this->pdo->exec("INSERT INTO boerse_anfrage (art, name, nachricht, einwilligung_am, eingegangen_am) VALUES ('sammlung', 'X', 'Y', NOW(), NOW())");
        $this->assertSame('offen', $this->pdo->query('SELECT status FROM boerse_anfrage')->fetchColumn());
        $index = $this->pdo->query("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'boerse_anfrage_sperre' AND index_name = 'idx_boerse_sperre_ip'")->fetchColumn();
        $this->assertSame('ip_hash,zeitpunkt', $index);
    }

    private function assertDoppelt(callable $aktion, string $grund): void
    {
        try {
            $aktion();
            $this->fail($grund);
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->getCode(), $grund);
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
