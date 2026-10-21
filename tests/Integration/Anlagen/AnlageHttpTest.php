<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Anlagen;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\UploadService;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;

final class AnlageHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private int $spurweite;
    private string $speicher;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->spurweite = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    private function app(): App
    {
        $app = App::erzeuge(['speicher_pfad' => $this->speicher] + self::testConfig(), new Session($this->sitzung));
        // Testnaht: keine echten HTTP-Uploads.
        $app->container()->set(UploadService::class, fn() => new UploadService(
            $this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b)
        ));
        return $app;
    }

    private function anmelden(string $rolle): int
    {
        $id = $this->legeBenutzerAn($rolle . '@verein.de', 'geheim-passwort-1', $rolle);
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        return $id;
    }

    private function get(string $pfad, array $query = []): Response
    {
        return $this->app()->handle(new Request('GET', $pfad, $query));
    }

    private function post(string $pfad, array $daten = [], array $dateien = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)], $dateien));
    }

    /** @return array<string,mixed> */
    private function formdaten(array $u = []): array
    {
        return $u + ['name' => 'Clubanlage', 'spurweite_id' => (string) $this->spurweite, 'standort' => 'Vereinsheim, Raum 2', 'kurzbeschreibung' => 'Ovale H0-Anlage', 'status' => 'in_ordnung'];
    }

    private function anlage(string $name = 'Clubanlage'): int
    {
        $this->pdo->prepare("INSERT INTO anlage (nummer, name, spurweite_id) VALUES ((SELECT COALESCE(MAX(a.nummer), 0) + 1 FROM anlage a), ?, ?)")
            ->execute([$name, $this->spurweite]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{name:array<int,string>,tmp_name:array<int,string>,error:array<int,int>,size:array<int,int>} */
    private function upload(string ...$fixtures): array
    {
        $f = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($fixtures as $fixture) {
            $tmp = tempnam(sys_get_temp_dir(), 'up');
            $this->temp[] = $tmp;
            copy(dirname(__DIR__, 2) . '/fixtures/dateien/' . $fixture, $tmp);
            $f['name'][] = $fixture;
            $f['tmp_name'][] = $tmp;
            $f['error'][] = UPLOAD_ERR_OK;
            $f['size'][] = (int) filesize($tmp);
        }
        return $f;
    }

    public function test_mitglied_liest_liste_und_detail(): void
    {
        $id = $this->anlage();
        $this->anmelden('mitglied');
        $liste = $this->get('/anlagen');
        $this->assertSame(200, $liste->status);
        $this->assertStringContainsString('A1', $liste->body);
        $this->assertStringContainsString('Clubanlage', $liste->body);
        $this->assertStringContainsString('in Ordnung', $liste->body);
        $this->assertStringNotContainsString('/anlagen/neu', $liste->body);
        $detail = $this->get("/anlagen/$id");
        $this->assertSame(200, $detail->status);
        $this->assertStringContainsString('A1', $detail->body);
        $this->assertStringNotContainsString("/anlagen/$id/bearbeiten", $detail->body);
        $this->assertStringNotContainsString("/anlagen/$id/dateien", $detail->body);
        $this->assertSame(404, $this->get('/anlagen/999')->status);
        $this->assertSame(404, $this->get('/anlagen/abc')->status);
    }

    public function test_menue_enthaelt_anlagen(): void
    {
        $this->anmelden('mitglied');
        $body = $this->get('/fahrzeuge')->body;
        $this->assertMatchesRegularExpression('~href="/anlagen">Anlagen</a>~', $body);
        $this->assertLessThan(strpos($body, '>Anlagen<'), strpos($body, '>Fahrzeuge<'));
    }

    public function test_nur_ab_bearbeiter_schreiben(): void
    {
        $id = $this->anlage();
        foreach (['mitglied', 'fahrzeugwart'] as $rolle) {
            $this->anmelden($rolle);
            $this->assertSame(403, $this->get('/anlagen/neu')->status, $rolle);
            $this->assertSame(403, $this->post('/anlagen', $this->formdaten())->status, $rolle);
            $this->assertSame(403, $this->get("/anlagen/$id/bearbeiten")->status, $rolle);
            $this->assertSame(403, $this->post("/anlagen/$id", $this->formdaten(['_stand' => 'x']))->status, $rolle);
            $this->assertSame(403, $this->post("/anlagen/$id/loeschen")->status, $rolle);
            $this->assertSame(403, $this->post("/anlagen/$id/dateien", [], ['datei' => $this->upload('foto_gedreht.jpg')])->status, $rolle);
            $this->assertSame(403, $this->post("/anlagen/$id/titelbild", ['dokument_id' => '1'])->status, $rolle);
        }
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM anlage')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
    }

    public function test_bearbeiter_legt_an(): void
    {
        $this->anmelden('bearbeiter');
        $this->assertSame(200, $this->get('/anlagen/neu')->status);
        $r = $this->post('/anlagen', $this->formdaten());
        $this->assertSame(303, $r->status);
        $id = (int) $this->pdo->query('SELECT id FROM anlage')->fetchColumn();
        $this->assertSame("/anlagen/$id", $r->headers['Location']);
        $this->assertSame('Die Anlage wurde angelegt.', $this->sitzung['_meldungen'][0]['text']);
        $p = $this->pdo->query("SELECT aktion, aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'anlage' AND objekt_id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('angelegt', $p['aktion']);
        $this->assertSame('Clubanlage', json_decode((string) $p['aenderungen'], true)['name']['neu']);
        $detail = $this->get("/anlagen/$id");
        $this->assertStringContainsString('A1', $detail->body);
        $this->assertStringContainsString('Vereinsheim, Raum 2', $detail->body);
        $this->assertStringContainsString("/anlagen/$id/bearbeiten", $detail->body);
    }

    public function test_validierungsfehler_422(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->post('/anlagen', $this->formdaten(['name' => '', 'spurweite_id' => '']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte einen Namen eingeben.', $r->body);
        $this->assertStringContainsString('Bitte eine Spurweite wählen.', $r->body);
        $this->assertStringContainsString('Ovale H0-Anlage', $r->body);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM anlage')->fetchColumn());
    }

    public function test_aendern_mit_stand_und_konflikt(): void
    {
        $this->anmelden('bearbeiter');
        $this->post('/anlagen', $this->formdaten());
        $id = (int) $this->pdo->query('SELECT id FROM anlage')->fetchColumn();
        $stand = (string) $this->pdo->query("SELECT geaendert_am FROM anlage WHERE id = $id")->fetchColumn();

        $form = $this->get("/anlagen/$id/bearbeiten");
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('name="_stand" value="' . $stand . '"', $form->body);

        $ok = $this->post("/anlagen/$id", $this->formdaten(['status' => 'defekt', '_stand' => $stand]));
        $this->assertSame(303, $ok->status);
        $this->assertSame("/anlagen/$id", $ok->headers['Location']);
        $json = (string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'anlage' AND aktion = 'geaendert'")->fetchColumn();
        $this->assertSame(['status' => ['alt' => 'in_ordnung', 'neu' => 'defekt']], json_decode($json, true));

        $alt = $this->post("/anlagen/$id", $this->formdaten(['name' => 'Veraltet', '_stand' => $stand]));
        $this->assertSame(409, $alt->status);
        $this->assertStringContainsString('inzwischen von jemand anderem geändert', $alt->body);
        $this->assertStringContainsString('Veraltet', $alt->body);
        $this->assertSame('Clubanlage', $this->pdo->query("SELECT name FROM anlage WHERE id = $id")->fetchColumn());
        $this->assertSame('1', (string) $this->pdo->query("SELECT nummer FROM anlage WHERE id = $id")->fetchColumn());
    }

    public function test_fehlender_oder_kaputter_stand_ist_konflikt(): void
    {
        $this->anmelden('bearbeiter');
        $this->post('/anlagen', $this->formdaten());
        $id = (int) $this->pdo->query('SELECT id FROM anlage')->fetchColumn();
        foreach (['', 'abc', '2026-02-30 10:00:00', "x' OR 1=1 -- "] as $stand) {
            $r = $this->post("/anlagen/$id", $this->formdaten(['name' => 'Neu', '_stand' => $stand]));
            $this->assertSame(409, $r->status, $stand);
        }
        $this->assertSame('Clubanlage', $this->pdo->query("SELECT name FROM anlage WHERE id = $id")->fetchColumn());
    }

    public function test_foto_hochladen_wird_titelbild_und_loeschen_raeumt_auf(): void
    {
        $this->anmelden('bearbeiter');
        $id = $this->anlage();
        $r = $this->post("/anlagen/$id/dateien", ['kategorie' => 'sonstiges'], ['datei' => $this->upload('foto_gedreht.jpg', 'anleitung.pdf')]);
        $this->assertSame(303, $r->status);
        $this->assertSame("/anlagen/$id#dateien", $r->headers['Location']);
        $zuordnungen = $this->pdo->query("SELECT objekt_typ, objekt_id FROM dokument_zuordnung")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $zuordnungen);
        foreach ($zuordnungen as $z) {
            $this->assertSame(['objekt_typ' => 'anlage', 'objekt_id' => (string) $id], array_map('strval', $z));
        }
        $foto = (int) $this->pdo->query("SELECT id FROM dokument WHERE kategorie = 'foto'")->fetchColumn();
        $pdf = (int) $this->pdo->query("SELECT id FROM dokument WHERE kategorie <> 'foto'")->fetchColumn();
        $this->assertSame($foto, (int) $this->pdo->query("SELECT titelbild_dokument_id FROM anlage WHERE id = $id")->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'anlage' AND objekt_id = $id")->fetchColumn());

        $detail = $this->get("/anlagen/$id");
        $this->assertStringContainsString("/dateien/$foto/vorschau", $detail->body);
        $this->assertStringContainsString('Titelbild', $detail->body);
        $this->assertStringContainsString('anleitung.pdf', $detail->body);

        // Titelbild nur aus den eigenen Fotos.
        $this->post("/anlagen/$id/titelbild", ['dokument_id' => (string) $pdf]);
        $this->assertSame('Bitte ein Foto dieser Anlage als Titelbild wählen.', end($this->sitzung['_meldungen'])['text']);

        $pfade = $this->pdo->query('SELECT pfad FROM dokument')->fetchAll(\PDO::FETCH_COLUMN);
        $r = $this->post("/anlagen/$id/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame('/anlagen', $r->headers['Location']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM anlage')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument_zuordnung')->fetchColumn());
        foreach ($pfade as $pfad) {
            $this->assertFileDoesNotExist($this->speicher . '/' . $pfad);
            $this->assertFileDoesNotExist($this->speicher . '/' . $pfad . '.vorschau.jpg');
        }
        $this->assertSame('geloescht', $this->pdo->query("SELECT aktion FROM aenderungsprotokoll WHERE objekt_typ = 'anlage' AND objekt_id = $id ORDER BY id DESC LIMIT 1")->fetchColumn());
        $this->assertSame(404, $this->get("/anlagen/$id")->status);
    }

    public function test_datei_einer_anlage_loeschen_leert_titelbild_und_fuehrt_zur_anlage(): void
    {
        $this->anmelden('bearbeiter');
        $id = $this->anlage();
        $this->post("/anlagen/$id/dateien", [], ['datei' => $this->upload('foto_gedreht.jpg')]);
        $foto = (int) $this->pdo->query('SELECT id FROM dokument')->fetchColumn();
        $stand = (string) $this->pdo->query("SELECT geaendert_am FROM anlage WHERE id = $id")->fetchColumn();
        $r = $this->post("/dateien/$foto/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame("/anlagen/$id#dateien", $r->headers['Location']);
        $a = $this->pdo->query("SELECT titelbild_dokument_id, geaendert_am FROM anlage WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($a['titelbild_dokument_id']);
        $this->assertSame($stand, $a['geaendert_am']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
    }

    public function test_titelbild_wechseln(): void
    {
        $this->anmelden('bearbeiter');
        $id = $this->anlage();
        $this->post("/anlagen/$id/dateien", [], ['datei' => $this->upload('foto_gedreht.jpg', 'foto_gedreht.jpg')]);
        $ids = array_map('intval', $this->pdo->query('SELECT id FROM dokument ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame($ids[0], (int) $this->pdo->query("SELECT titelbild_dokument_id FROM anlage WHERE id = $id")->fetchColumn());
        $r = $this->post("/anlagen/$id/titelbild", ['dokument_id' => (string) $ids[1]]);
        $this->assertSame("/anlagen/$id#dateien", $r->headers['Location']);
        $this->assertSame($ids[1], (int) $this->pdo->query("SELECT titelbild_dokument_id FROM anlage WHERE id = $id")->fetchColumn());
        $this->assertSame('Das Titelbild wurde geändert.', end($this->sitzung['_meldungen'])['text']);
    }

    public function test_liste_filtert_nach_spurweite_und_text(): void
    {
        $this->anlage('Clubanlage');
        $this->anlage('Modul Bahnhof');
        $this->anmelden('mitglied');
        $body = $this->get('/anlagen', ['q' => 'bahnhof'])->body;
        $this->assertStringContainsString('Modul Bahnhof', $body);
        $this->assertStringNotContainsString('Clubanlage', $body);
        $andere = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name <> 'H0' ORDER BY id LIMIT 1")->fetchColumn();
        $body = $this->get('/anlagen', ['spurweite' => (string) $andere])->body;
        $this->assertStringContainsString('Keine Anlagen gefunden', $body);
    }
}
