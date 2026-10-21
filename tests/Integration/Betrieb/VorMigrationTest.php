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

    /** Vor Migration 010 (ohne kennwort_stand) funktionieren Anmeldung und laufende Sitzungen weiter. */
    public function test_anmeldung_vor_migration_010(): void
    {
        $id = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        $this->pdo->exec('DROP TABLE kennwort_versuch');
        $this->pdo->exec('DROP TABLE kennwort_anfrage');
        $this->pdo->exec('DROP TABLE kennwort_token');
        $this->pdo->exec('ALTER TABLE benutzer DROP COLUMN kennwort_stand');
        $this->pdo->exec("DELETE FROM schema_migration WHERE version >= '010'");

        $sitzung = [];
        $auth = new \Emc\Auth\Auth($this->pdo, new \Emc\Core\Session($sitzung), 'x');
        self::assertSame(\Emc\Auth\AnmeldeErgebnis::Erfolg, $auth->anmelden('admin@verein.de', 'geheim-passwort-1', '127.0.0.1'));
        self::assertSame($id, $auth->benutzer()?->id);

        $sitzung2 = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung2, $id);
        $app = \Emc\Core\App::erzeuge(self::testConfig(), new \Emc\Core\Session($sitzung2));
        $r = $app->handle(new \Emc\Core\Request('GET', '/admin/benutzer'));
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Datenbank-Update', $r->body);
    }

    private function stand010(): void
    {
        $this->pdo->exec('DROP TABLE anlage');
        $this->pdo->exec("DELETE FROM schema_migration WHERE version >= '011'");
    }

    /** Vor Migration 011 (ohne Tabelle anlage) lässt sich ein Fahrzeugdokument weiterhin löschen. */
    public function test_dokument_loeschen_vor_migration_011(): void
    {
        [$fz, $dok] = $this->dokumentMitFahrzeug();
        $this->pdo->exec("UPDATE fahrzeug SET titelbild_dokument_id = $dok WHERE id = $fz");
        $this->stand010();
        (new DokumentRepository($this->pdo, sys_get_temp_dir()))->loeschen($dok);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
        self::assertNull($this->pdo->query("SELECT titelbild_dokument_id FROM fahrzeug WHERE id = $fz")->fetchColumn() ?: null);
    }

    /** Vor Migration 011 führt die Anlagenliste Administratoren zum Update statt zu einem Fehler 500. */
    public function test_anlagen_vor_migration_011(): void
    {
        $id = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        $this->stand010();
        $sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung, $id);
        $app = \Emc\Core\App::erzeuge(self::testConfig(), new \Emc\Core\Session($sitzung));
        $r = $app->handle(new \Emc\Core\Request('GET', '/anlagen'));
        self::assertSame(303, $r->status);
        self::assertSame('/admin/update', $r->headers['Location']);
        self::assertSame(200, $app->handle(new \Emc\Core\Request('GET', '/fahrzeuge'))->status);
    }

    /** Vor Migration 011 führen Feld „Code“ und Kurzlink (suchen auch Anlagen) Administratoren zum Update. */
    public function test_code_und_kurzlink_vor_migration_011(): void
    {
        $id = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        $this->dokumentMitFahrzeug();
        $this->stand010();
        $sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung, $id);
        $app = \Emc\Core\App::erzeuge(self::testConfig(), new \Emc\Core\Session($sitzung));
        foreach ([['/code', ['q' => 'F1']], ['/code', ['q' => 'V 200']], ['/c/A1', []], ['/c/F1', []]] as [$pfad, $query]) {
            $r = $app->handle(new \Emc\Core\Request('GET', $pfad, $query));
            self::assertSame(303, $r->status, $pfad);
            self::assertSame('/admin/update', $r->headers['Location'], $pfad);
        }
    }

    /** Vor Migration 012 führt die Wartungsarten-Seite Administratoren zum Update statt zu einem Fehler 500. */
    public function test_wartungsarten_vor_migration_012(): void
    {
        $id = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        $this->pdo->exec('DROP TABLE wartung_art');
        $this->pdo->exec('DROP TABLE wartung');
        $this->pdo->exec('DROP TABLE wartungsplan_ausnahme');
        $this->pdo->exec('DROP TABLE wartungsart');
        $this->pdo->exec("DELETE FROM schema_migration WHERE version >= '012'");
        $sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung, $id);
        $app = \Emc\Core\App::erzeuge(self::testConfig(), new \Emc\Core\Session($sitzung));
        $r = $app->handle(new \Emc\Core\Request('GET', '/admin/wartungsarten'));
        self::assertSame(303, $r->status);
        self::assertSame('/admin/update', $r->headers['Location']);
        self::assertSame(200, $app->handle(new \Emc\Core\Request('GET', '/fahrzeuge'))->status);
    }
    /** Vor Migration 013 zeigt die Fahrzeugseite Administratoren weiter alles außer der Wartung (kein Fehler 500). */
    public function test_fahrzeugseite_vor_migration_013(): void
    {
        $id = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        [$fz] = $this->dokumentMitFahrzeug();
        $this->pdo->exec('DROP TABLE wartung_art');
        $this->pdo->exec('DROP TABLE wartung');
        $this->pdo->exec('DROP TABLE wartungsplan_ausnahme');
        $this->pdo->exec("DELETE FROM schema_migration WHERE version >= '013'");
        $sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung, $id);
        $app = \Emc\Core\App::erzeuge(self::testConfig(), new \Emc\Core\Session($sitzung));
        $r = $app->handle(new \Emc\Core\Request('GET', '/fahrzeuge/' . $fz));
        self::assertSame(200, $r->status);
        self::assertStringNotContainsString('id="tab-wartung"', $r->body);
        // Startseite ohne Wartungs-Kachel, die Übersicht führt zum Update.
        $start = $app->handle(new \Emc\Core\Request('GET', '/'));
        self::assertSame(200, $start->status);
        self::assertStringNotContainsString('Alle Fälligkeiten', $start->body);
        $uebersicht = $app->handle(new \Emc\Core\Request('GET', '/wartung'));
        self::assertSame(303, $uebersicht->status);
        self::assertSame('/admin/update', $uebersicht->headers['Location']);
    }

    /** Absenden von Wartung und Wartungsplan vor Migration 013 führt Administratoren zum Update, nicht zu einem Fehler 500. */
    public function test_wartung_absenden_vor_migration_013(): void
    {
        $id = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        [$fz] = $this->dokumentMitFahrzeug();
        $this->pdo->exec('DROP TABLE wartung_art');
        $this->pdo->exec('DROP TABLE wartung');
        $this->pdo->exec('DROP TABLE wartungsplan_ausnahme');
        $sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung, $id);
        $post = fn(string $pfad, array $daten = []) => \Emc\Core\App::erzeuge(self::testConfig(), new \Emc\Core\Session($sitzung))
            ->handle(new \Emc\Core\Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)]));
        // Tabellen fehlen, obwohl die Version eingetragen ist (z. B. abgebrochenes Update): der Controller fängt es ab.
        foreach (["/fahrzeuge/$fz/wartungen", "/fahrzeuge/$fz/wartungsplan", '/wartungen/1/loeschen'] as $pfad) {
            $r = $post($pfad, ['datum' => date('Y-m-d'), 'arten' => ['1']]);
            self::assertSame(303, $r->status, $pfad);
            self::assertSame('/admin/update', $r->headers['Location'], $pfad);
        }
        // Steht die Migration aus, leitet schon App weiter.
        $this->pdo->exec("DELETE FROM schema_migration WHERE version >= '013'");
        $r = $post("/fahrzeuge/$fz/wartungen", ['datum' => date('Y-m-d')]);
        self::assertSame(303, $r->status);
        self::assertSame('/admin/update', $r->headers['Location']);
    }

    private function ohneReparaturTabellen(): void
    {
        $this->pdo->exec('DROP TABLE reparatur_gesehen');
        $this->pdo->exec('DROP TABLE ersatzteil');
        $this->pdo->exec('DROP TABLE reparatur');
    }

    /** Vor Migration 014 führen „Defekt melden“ und „Meine Meldungen“ Administratoren zum Update, nicht zu einem Fehler 500. */
    public function test_defekt_melden_vor_migration_014(): void
    {
        $id = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        [$fz] = $this->dokumentMitFahrzeug();
        $this->ohneReparaturTabellen();
        $sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung, $id);
        $app = fn() => \Emc\Core\App::erzeuge(self::testConfig(), new \Emc\Core\Session($sitzung));
        $post = fn(string $pfad) => $app()->handle(new \Emc\Core\Request('POST', $pfad, [], ['_csrf' => str_repeat('a', 64), 'beschreibung' => 'Licht geht nicht']));

        // Tabellen fehlen, obwohl die Version eingetragen ist: der Controller fängt es ab.
        foreach (['/defekt', '/meine-meldungen', "/fahrzeuge/$fz/defekt"] as $pfad) {
            $r = $app()->handle(new \Emc\Core\Request('GET', $pfad));
            self::assertContains($r->status, [200, 303], $pfad);
            if ($r->status === 303) {
                self::assertSame('/admin/update', $r->headers['Location'], $pfad);
            }
        }
        $r = $post("/fahrzeuge/$fz/defekt");
        self::assertSame(303, $r->status);
        self::assertSame('/admin/update', $r->headers['Location']);
        $r = $app()->handle(new \Emc\Core\Request('GET', '/meine-meldungen'));
        self::assertSame(303, $r->status);
        self::assertSame('/admin/update', $r->headers['Location']);

        // Steht die Migration aus, leitet schon App weiter; die Fahrzeugseite bleibt lesbar.
        $this->pdo->exec("DELETE FROM schema_migration WHERE version >= '014'");
        foreach (['/defekt', '/meine-meldungen', "/fahrzeuge/$fz/defekt", '/reparaturen', '/etiketten'] as $pfad) {
            $r = $app()->handle(new \Emc\Core\Request('GET', $pfad));
            self::assertSame(303, $r->status, $pfad);
            self::assertSame('/admin/update', $r->headers['Location'], $pfad);
        }
        self::assertSame(200, $app()->handle(new \Emc\Core\Request('GET', "/fahrzeuge/$fz"))->status);
    }

    /** App merkt sich die ausstehenden Migrationen nur für eine Anfrage: nach dem Update gilt sofort der neue Stand. */
    public function test_ausstehende_migrationen_je_anfrage_neu_ermittelt(): void
    {
        $id = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        $this->pdo->exec("DELETE FROM schema_migration WHERE version = '013_wartung'");
        $sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung, $id);
        $app = \Emc\Core\App::erzeuge(self::testConfig(), new \Emc\Core\Session($sitzung));
        self::assertSame(303, $app->handle(new \Emc\Core\Request('GET', '/anlagen'))->status);
        $this->pdo->exec("INSERT INTO schema_migration (version) VALUES ('013_wartung')");
        self::assertSame(200, $app->handle(new \Emc\Core\Request('GET', '/anlagen'))->status);
    }
}
