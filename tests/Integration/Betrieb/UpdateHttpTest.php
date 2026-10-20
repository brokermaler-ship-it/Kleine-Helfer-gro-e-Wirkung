<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Betrieb;

use Emc\Betrieb\Einstellungen;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

final class UpdateHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private string $migrationen;
    private string $speicher;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS test_update');
        $this->sitzung = [];
        $this->migrationen = sys_get_temp_dir() . '/emc_upd_' . bin2hex(random_bytes(6));
        mkdir($this->migrationen);
        foreach (glob(dirname(__DIR__, 3) . '/migrations/*.sql') as $datei) {
            copy($datei, $this->migrationen . '/' . basename($datei));
        }
        $this->speicher = sys_get_temp_dir() . '/emc_upd_sp_' . bin2hex(random_bytes(6));
        mkdir($this->speicher . '/dateien', 0750, true);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS test_update');
        $this->pdo->exec("DELETE FROM schema_migration WHERE version = '999_test'");
        foreach (glob($this->migrationen . '/*') ?: [] as $d) {
            unlink($d);
        }
        rmdir($this->migrationen);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
        @unlink($this->speicher . '/dateien/.gitkeep');
        @rmdir($this->speicher . '/dateien');
        @rmdir($this->speicher);
    }

    private function ausstehendeMigration(): void
    {
        file_put_contents($this->migrationen . '/999_test.sql', "CREATE TABLE test_update (id INT) ENGINE=InnoDB;\n");
    }

    private function anmelden(string $rolle): void
    {
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn($rolle . '@verein.de', 'geheim-passwort-1', $rolle));
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    private function app(): App
    {
        return App::erzeuge(['speicher_pfad' => $this->speicher] + self::testConfig(), new Session($this->sitzung), $this->migrationen);
    }

    private function get(string $pfad): Response
    {
        return $this->app()->handle(new Request('GET', $pfad));
    }

    private function post(string $pfad, array $d = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $d + ['_csrf' => str_repeat('a', 64)]));
    }

    public function test_mitglied_sieht_wartungsseite_bei_ausstehender_migration(): void
    {
        $this->ausstehendeMigration();
        $this->anmelden('mitglied');
        $r = $this->get('/fahrzeuge');
        $this->assertSame(503, $r->status);
        $this->assertStringContainsString('Die Anwendung wird gerade aktualisiert. Bitte versuche es in ein paar Minuten noch einmal.', $r->body);
        $this->assertSame(403, $this->get('/admin/update')->status, 'Mitglied darf die Update-Seite nicht nutzen');
    }

    public function test_login_und_logout_bleiben_bei_ausstehender_migration_erreichbar(): void
    {
        $this->ausstehendeMigration();
        $this->assertSame(200, $this->get('/login')->status);
        $this->anmelden('mitglied');
        $this->assertSame(303, $this->post('/logout')->status);
    }

    public function test_bearbeiter_sieht_wartungsseite(): void
    {
        $this->ausstehendeMigration();
        $this->anmelden('bearbeiter');
        $this->assertSame(503, $this->get('/fahrzeuge')->status);
    }

    public function test_admin_sieht_hinweisleiste_mit_link(): void
    {
        $this->ausstehendeMigration();
        $this->anmelden('admin');
        $r = $this->get('/fahrzeuge');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Es steht ein Datenbank-Update an.', $r->body);
        $this->assertStringContainsString('/admin/update', $r->body);
    }

    public function test_admin_post_bei_ausstehender_migration_leitet_zum_update(): void
    {
        $this->ausstehendeMigration();
        $this->anmelden('admin');
        $h0 = (string) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $r = $this->post('/fahrzeuge', ['bezeichnung' => 'Lok', 'gattung' => 'lok', 'spurweite_id' => $h0, 'stromsystem' => '2L', 'status' => 'einsatzbereit', 'betriebsstunden' => '0']);
        $this->assertSame(303, $r->status);
        $this->assertSame('/admin/update', $r->headers['Location']);
        $this->assertSame(['typ' => 'warnung', 'text' => 'Bitte zuerst das Datenbank-Update ausführen.'], $this->sitzung['_meldungen'][0]);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
        $this->assertSame(200, $this->get('/fahrzeuge')->status, 'Lesen bleibt möglich');

        // Sicherung und Abmelden bleiben erreichbar
        $sicherung = $this->post('/admin/sicherung');
        $this->assertNotSame('/admin/update', $sicherung->headers['Location'] ?? null);
        $this->assertSame(200, $sicherung->status);
        $abmelden = $this->post('/logout');
        $this->assertSame(303, $abmelden->status);
        $this->assertNotSame('/admin/update', $abmelden->headers['Location'] ?? null);
    }

    public function test_ohne_ausstehende_migration_keine_update_leiste(): void
    {
        $this->anmelden('admin');
        $this->assertStringNotContainsString('Datenbank-Update', $this->get('/fahrzeuge')->body);
    }

    public function test_update_seite_listet_ausstehende_migrationen(): void
    {
        $this->ausstehendeMigration();
        $this->anmelden('admin');
        $r = $this->get('/admin/update');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('999_test', $r->body);
    }

    public function test_admin_fuehrt_update_aus(): void
    {
        $this->ausstehendeMigration();
        $this->anmelden('admin');
        $r = $this->post('/admin/update');
        $this->assertSame(303, $r->status);
        $this->assertSame('Datenbank wurde aktualisiert (1 Schritte).', $this->sitzung['_meldungen'][0]['text']);
        $this->assertSame([], (new \Emc\Betrieb\Migrator($this->pdo, $this->migrationen))->ausstehend());
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn('m@verein.de', 'geheim-passwort-1', 'mitglied'));
        $this->assertSame(200, $this->get('/fahrzeuge')->status);
    }

    public function test_mitglied_darf_update_nicht_ausfuehren(): void
    {
        $this->anmelden('mitglied');
        $this->assertSame(403, $this->post('/admin/update')->status);
        $this->assertSame(403, $this->get('/admin/update')->status);
    }

    public function test_update_ohne_ausstehende_migration_meldet_aktuell(): void
    {
        $this->anmelden('admin');
        $this->post('/admin/update');
        $this->assertStringContainsString('aktuell', $this->sitzung['_meldungen'][0]['text']);
    }

    public function test_sicherungshinweis_nur_fuer_admin_wenn_ueberfaellig(): void
    {
        $this->anmelden('admin');
        $this->assertStringContainsString('Die letzte Datensicherung ist älter als 30 Tage.', $this->get('/fahrzeuge')->body);
        (new Einstellungen($this->pdo))->setze('letzte_sicherung', (new \DateTimeImmutable('-2 days'))->format(\DATE_ATOM));
        $this->assertStringNotContainsString('Datensicherung ist älter', $this->get('/fahrzeuge')->body);
        (new Einstellungen($this->pdo))->setze('letzte_sicherung', (new \DateTimeImmutable('-40 days'))->format(\DATE_ATOM));
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn('b@verein.de', 'geheim-passwort-1', 'bearbeiter'));
        $this->assertStringNotContainsString('Datensicherung', $this->get('/fahrzeuge')->body);
    }

    public function test_sicherungsseite_und_download(): void
    {
        $this->anmelden('admin');
        $seite = $this->get('/admin/sicherung');
        $this->assertSame(200, $seite->status);
        $this->assertStringContainsString('Sicherung herunterladen', $seite->body);
        $this->assertStringContainsString('noch nie', $seite->body);

        $r = $this->post('/admin/sicherung');
        $this->assertSame(200, $r->status);
        $this->assertSame('application/zip', $r->headers['Content-Type']);
        $this->assertMatchesRegularExpression(
            '/^attachment; filename="emc78-sicherung-\d{4}-\d{2}-\d{2}\.zip"/',
            $r->headers['Content-Disposition']
        );
        $this->assertNotNull($r->datei);
        $this->assertTrue($r->loescheNachSenden);
        $this->temp[] = (string) $r->datei;
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open((string) $r->datei));
        $this->assertNotFalse($zip->locateName('datenbank.sql'));
        $zip->close();
        $this->assertStringNotContainsString('noch nie', $this->get('/admin/sicherung')->body);
    }

    public function test_sicherung_nur_fuer_admin(): void
    {
        $this->anmelden('bearbeiter');
        $this->assertSame(403, $this->get('/admin/sicherung')->status);
        $this->assertSame(403, $this->post('/admin/sicherung')->status);
    }
}
