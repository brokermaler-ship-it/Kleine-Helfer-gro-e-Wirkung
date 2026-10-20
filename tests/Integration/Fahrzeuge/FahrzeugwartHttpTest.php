<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Fahrzeuge;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadService;
use Emc\Tests\Integration\DbTestCase;
use Emc\Tests\Integration\Dateien\UploadServiceTest;

/** Der Fahrzeugwart darf alles rund um alle Fahrzeuge (auch private), aber keine Personen-, Benutzer- oder Betriebsseiten. */
final class FahrzeugwartHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private string $speicher;
    private int $h0;
    private int $wart;
    private int $mitglied;
    private int $admin;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->wart = $this->legeBenutzerAn('wart@verein.de', 'geheim-passwort-1', 'fahrzeugwart');
        $this->mitglied = $this->legeBenutzerAn('mitglied@verein.de', 'geheim-passwort-1', 'mitglied');
        $this->admin = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    private function als(int $benutzerId): void
    {
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $benutzerId);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    private function app(): App
    {
        $app = App::erzeuge(['speicher_pfad' => $this->speicher, 'upload_max_mb' => 64] + self::testConfig(), new Session($this->sitzung));
        $app->container()->set(UploadService::class, fn() => new UploadService(
            $this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b)
        ));
        return $app;
    }

    private function get(string $pfad): Response
    {
        return $this->app()->handle(new Request('GET', $pfad));
    }

    private function post(string $pfad, array $d = [], array $dateien = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $d + ['_csrf' => str_repeat('a', 64)], $dateien));
    }

    private function personVon(int $benutzerId): int
    {
        return (int) $this->pdo->query('SELECT person_id FROM benutzer WHERE id = ' . $benutzerId)->fetchColumn();
    }

    /** Privates Fahrzeug des Mitglieds, von ihm selbst angelegt. */
    private function privatesFahrzeug(): int
    {
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, eigentuemer_person_id, angelegt_von_benutzer_id) VALUES (?, ?, ?, ?, ?)')
            ->execute(['EMC-0001', 'Private Lok', $this->h0, $this->personVon($this->mitglied), $this->mitglied]);
        return (int) $this->pdo->lastInsertId();
    }

    private function feld(int $fz, string $spalte): mixed
    {
        return $this->pdo->query("SELECT $spalte FROM fahrzeug WHERE id = $fz")->fetchColumn();
    }

    private function fahrzeugDaten(array $u = []): array
    {
        return $u + ['bezeichnung' => 'Lok', 'gattung' => 'lok', 'spurweite_id' => (string) $this->h0, 'stromsystem' => '2L', 'status' => 'einsatzbereit', 'betriebsstunden' => '0'];
    }

    private function datei(string $fixture): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $this->temp[] = $tmp;
        copy(dirname(__DIR__, 2) . '/fixtures/dateien/' . $fixture, $tmp);
        return ['name' => $fixture, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    }

    public function test_fahrzeugwart_bearbeitet_und_loescht_fremdes_privates_fahrzeug(): void
    {
        $fz = $this->privatesFahrzeug();
        $this->als($this->wart);
        $form = $this->get("/fahrzeuge/$fz/bearbeiten");
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('name="eigentuemer_person_id"', $form->body, 'Fahrzeugwart wählt den Eigentümer frei');

        $detail = $this->get("/fahrzeuge/$fz")->body;
        $this->assertStringContainsString("/fahrzeuge/$fz/bearbeiten", $detail);
        $this->assertStringContainsString("/fahrzeuge/$fz/loeschen", $detail);
        $this->assertStringContainsString("/fahrzeuge/$fz/decoder", $detail);
        $this->assertStringContainsString("/fahrzeuge/$fz/dateien", $detail);

        // Eigentümer auf den Verein umstellen
        $stand = (string) $this->feld($fz, 'geaendert_am');
        $r = $this->post("/fahrzeuge/$fz", $this->fahrzeugDaten(['bezeichnung' => 'Vom Wart', 'eigentuemer_person_id' => '', '_stand' => $stand]));
        $this->assertSame(303, $r->status);
        $this->assertSame('Vom Wart', $this->feld($fz, 'bezeichnung'));
        $this->assertNull($this->feld($fz, 'eigentuemer_person_id'));

        $r = $this->post("/fahrzeuge/$fz/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    public function test_fahrzeugwart_legt_fahrzeug_fuer_anderes_mitglied_an(): void
    {
        $this->als($this->wart);
        $liste = $this->get('/fahrzeuge')->body;
        $this->assertStringContainsString('+ Fahrzeug anlegen', $liste);
        $this->assertStringContainsString('name="eigentuemer_person_id"', $this->get('/fahrzeuge/neu')->body);
        $fremd = $this->personVon($this->mitglied);
        $this->assertSame(303, $this->post('/fahrzeuge', $this->fahrzeugDaten(['eigentuemer_person_id' => (string) $fremd]))->status);
        $id = (int) $this->pdo->query('SELECT id FROM fahrzeug')->fetchColumn();
        $this->assertSame($fremd, (int) $this->feld($id, 'eigentuemer_person_id'));
        $this->assertSame($this->wart, (int) $this->feld($id, 'angelegt_von_benutzer_id'));
    }

    public function test_fahrzeugwart_decoder_funktionen_cvs_und_dateien_am_fremden_fahrzeug(): void
    {
        $fz = $this->privatesFahrzeug();
        $this->als($this->wart);
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/decoder", ['bezeichnung' => 'Motor', 'protokoll' => 'dcc', 'adresse' => '5'])->status);
        $dec = (int) $this->pdo->query('SELECT id FROM decoder')->fetchColumn();
        $this->assertSame(303, $this->post("/decoder/$dec/funktionen", ['beschreibung' => [0 => 'Licht']])->status);
        $this->assertSame(303, $this->post("/decoder/$dec/cvs", ['cv' => '3', 'wert' => '10', 'kommentar' => ''])->status);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM cv_wert')->fetchColumn());

        $this->assertSame(303, $this->post("/fahrzeuge/$fz/dateien", ['kategorie' => 'foto'], ['datei' => $this->datei('foto_gedreht.jpg')])->status);
        $dok = (int) $this->pdo->query('SELECT id FROM dokument')->fetchColumn();
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/titelbild", ['dokument_id' => (string) $dok])->status);
        $this->assertSame($dok, (int) $this->feld($fz, 'titelbild_dokument_id'));
        $this->assertStringContainsString("/dateien/$dok/loeschen", $this->get("/fahrzeuge/$fz")->body);
        $this->assertSame(303, $this->post("/dateien/$dok/loeschen")->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());

        $this->assertSame(303, $this->post("/decoder/$dec/loeschen")->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM decoder')->fetchColumn());
    }

    public function test_geteiltes_dokument_bleibt_dem_bearbeiter_vorbehalten(): void
    {
        $fz = $this->privatesFahrzeug();
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) VALUES (?, ?, ?)')->execute(['EMC-0002', 'Zweite', $this->h0]);
        $zweites = (int) $this->pdo->lastInsertId();
        $service = new UploadService($this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b));
        $dok = $service->speichere($this->datei('anleitung.pdf'), '', 'anleitung', null);
        $repo = new DokumentRepository($this->pdo, $this->speicher);
        $repo->zuordnen($dok, 'fahrzeug', $fz);
        $repo->zuordnen($dok, 'fahrzeug', $zweites);
        $this->als($this->wart);
        $this->assertSame(403, $this->post("/dateien/$dok/loeschen")->status);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
    }

    public function test_fahrzeugwart_hat_keinen_zugriff_auf_personen_benutzer_und_betrieb(): void
    {
        $this->als($this->wart);
        foreach (['/personen', '/personen/neu', '/admin/benutzer', '/admin/spurweiten', '/admin/sicherung', '/admin/update', '/admin/onedrive'] as $pfad) {
            $this->assertSame(403, $this->get($pfad)->status, $pfad);
        }
        $pid = $this->personVon($this->mitglied);
        $this->assertSame(403, $this->get("/personen/$pid/bearbeiten")->status);
        $this->assertSame(403, $this->post('/personen', ['vorname' => 'X', 'nachname' => 'Y'])->status);
        $this->assertSame(403, $this->post('/admin/benutzer', ['person_id' => (string) $pid, 'email' => 'x@verein.de', 'rolle' => 'admin', 'passwort' => 'start-passwort-1'])->status);

        $nav = $this->get('/fahrzeuge')->body;
        $this->assertStringContainsString('href="/fahrzeuge"', $nav);
        $this->assertStringNotContainsString('href="/personen"', $nav);
        $this->assertStringNotContainsString('/admin/benutzer', $nav);
        $this->assertStringContainsString('Fahrzeugwart', $nav, 'Rollenname im Benutzermenü');
    }

    public function test_admin_vergibt_rolle_fahrzeugwart(): void
    {
        $this->als($this->admin);
        $this->assertStringContainsString('<option value="fahrzeugwart"', $this->get('/admin/benutzer/neu')->body);
        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('Dora', 'Neu')");
        $pid = (int) $this->pdo->lastInsertId();
        $r = $this->post('/admin/benutzer', ['person_id' => (string) $pid, 'email' => 'dora@verein.de', 'rolle' => 'fahrzeugwart', 'passwort' => 'start-passwort-1']);
        $this->assertSame(303, $r->status);
        $this->assertSame('fahrzeugwart', (string) $this->pdo->query("SELECT rolle FROM benutzer WHERE email = 'dora@verein.de'")->fetchColumn());
        $liste = $this->get('/admin/benutzer')->body;
        $this->assertStringContainsString('<td>Fahrzeugwart</td>', $liste);
    }
}
