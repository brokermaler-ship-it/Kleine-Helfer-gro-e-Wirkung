<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Dateien;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadService;
use Emc\Tests\Integration\DbTestCase;

final class DateiHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private string $speicher;
    private int $fz;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
        $this->pdo->exec("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) SELECT 'EMC-0001', 'Lok', id FROM spurweite LIMIT 1");
        $this->fz = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    private function anmelden(string $rolle): void
    {
        $this->sitzung['benutzer_id'] = $this->legeBenutzerAn($rolle . '@verein.de', 'geheim-passwort-1', $rolle);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    private function app(array $extra = []): App
    {
        $app = App::erzeuge(['speicher_pfad' => $this->speicher, 'upload_max_mb' => 64] + $extra + self::testConfig(), new Session($this->sitzung));
        // Testnaht: Tests liefern keine echten HTTP-Uploads; nur hier werden die Prüfungen ersetzt.
        $app->container()->set(UploadService::class, fn() => $this->service());
        return $app;
    }

    private function get(string $pfad): Response
    {
        return $this->app()->handle(new Request('GET', $pfad));
    }

    private function post(string $pfad, array $d = [], array $dateien = [], array $server = [], array $config = []): Response
    {
        return $this->app($config)->handle(new Request('POST', $pfad, [], $d + ['_csrf' => str_repeat('a', 64)], $dateien, $server));
    }

    /** @return array{name:string,tmp_name:string,error:int,size:int} */
    private function datei(string $fixture): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $this->temp[] = $tmp;
        copy(dirname(__DIR__, 2) . '/fixtures/dateien/' . $fixture, $tmp);
        return ['name' => $fixture, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    }

    private function service(): UploadService
    {
        return new UploadService($this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b));
    }

    private function dokument(string $fixture, string $kategorie): int
    {
        $id = $this->service()->speichere($this->datei($fixture), '', $kategorie, null);
        (new DokumentRepository($this->pdo, $this->speicher))->zuordnen($id, 'fahrzeug', $this->fz);
        return $id;
    }

    private function meldungen(): array
    {
        return $this->sitzung['_meldungen'] ?? [];
    }

    public function test_dateien_nur_angemeldet(): void
    {
        $id = $this->dokument('anleitung.pdf', 'anleitung');
        $r = $this->get("/dateien/$id");
        $this->assertSame(303, $r->status);
        $this->assertStringStartsWith('/login', $r->headers['Location']);
    }

    public function test_pdf_wird_inline_mit_nosniff_geliefert(): void
    {
        $this->anmelden('mitglied');
        $id = $this->dokument('anleitung.pdf', 'anleitung');
        $r = $this->get("/dateien/$id");
        $this->assertSame(200, $r->status);
        $this->assertSame('application/pdf', $r->headers['Content-Type']);
        $this->assertStringStartsWith('inline', $r->headers['Content-Disposition']);
        $this->assertStringContainsString("filename*=UTF-8''anleitung.pdf", $r->headers['Content-Disposition']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('private, max-age=3600', $r->headers['Cache-Control']);
        $this->assertSame(file_get_contents(dirname(__DIR__, 2) . '/fixtures/dateien/anleitung.pdf'), file_get_contents((string) $r->datei));
    }

    public function test_variante_und_rueckfall_auf_original(): void
    {
        $this->anmelden('mitglied');
        $foto = $this->dokument('foto_gedreht.jpg', 'foto');
        $r = $this->get("/dateien/$foto/vorschau");
        $this->assertSame(200, $r->status);
        $this->assertStringEndsWith('.vorschau.jpg', (string) $r->datei);
        $this->assertSame('image/jpeg', $r->headers['Content-Type']);
        $pdf = $this->dokument('anleitung.pdf', 'anleitung');
        $r = $this->get("/dateien/$pdf/vorschau");
        $this->assertSame('application/pdf', $r->headers['Content-Type']);
        $this->assertSame(404, $this->get("/dateien/$foto/original")->status);
        $this->assertSame(404, $this->get('/dateien/99999')->status);
    }

    public function test_fehlende_datei_ergibt_404(): void
    {
        $this->anmelden('mitglied');
        $id = $this->dokument('anleitung.pdf', 'anleitung');
        unlink($this->speicher . '/' . (new DokumentRepository($this->pdo, $this->speicher))->finde($id)['pfad']);
        $this->assertSame(404, $this->get("/dateien/$id")->status);
    }

    public function test_download_typ_als_attachment(): void
    {
        $this->anmelden('mitglied');
        $this->pdo->exec("INSERT INTO dokument (titel, kategorie, original_name, endung, mime, groesse, pfad) VALUES ('t', 'sonstiges', 'Bär; \"x\".docx', 'docx', 'application/zip', 3, 'dateien/2026/01/abc')");
        $id = (int) $this->pdo->lastInsertId();
        mkdir($this->speicher . '/dateien/2026/01', 0750, true);
        file_put_contents($this->speicher . '/dateien/2026/01/abc', 'zip');
        $r = $this->get("/dateien/$id");
        $this->assertStringStartsWith('attachment', $r->headers['Content-Disposition']);
        $this->assertStringContainsString("filename*=UTF-8''B%C3%A4r%3B%20%22x%22.docx", $r->headers['Content-Disposition']);
        $this->assertStringNotContainsString("\n", $r->headers['Content-Disposition']);
    }

    public function test_mitglied_darf_nicht_hochladen(): void
    {
        $this->anmelden('mitglied');
        $this->assertSame(403, $this->post("/fahrzeuge/{$this->fz}/dateien", [], ['datei' => $this->datei('anleitung.pdf')])->status);
        $id = $this->dokument('anleitung.pdf', 'anleitung');
        $this->assertSame(403, $this->post("/dateien/$id/loeschen")->status);
        $this->assertSame(403, $this->post("/fahrzeuge/{$this->fz}/titelbild", ['dokument_id' => (string) $id])->status);
    }

    public function test_zu_grosser_post_zeigt_verstaendliche_meldung(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->app(['post_max_bytes' => 8 * 1048576, 'upload_max_mb' => 64])->handle(new Request(
            'POST',
            "/fahrzeuge/{$this->fz}/dateien",
            [],
            [],
            [],
            ['CONTENT_LENGTH' => (string) (9 * 1048576), 'HTTP_HOST' => 'verein.example', 'HTTP_REFERER' => "https://verein.example/fahrzeuge/{$this->fz}?x=1"]
        ));
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/{$this->fz}?x=1", $r->headers['Location']);
        $this->assertSame('fehler', $this->meldungen()[0]['typ']);
        $this->assertSame('Die Datei ist zu groß (höchstens 8 MB).', $this->meldungen()[0]['text']);
    }

    public function test_zu_grosser_post_fremder_referer_geht_auf_startseite(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->app(['post_max_bytes' => 1048576])->handle(new Request('POST', '/fahrzeuge/1/dateien', [], [], [], [
            'CONTENT_LENGTH' => '5000000', 'HTTP_HOST' => 'verein.example', 'HTTP_REFERER' => 'https://boese.example/x',
        ]));
        $this->assertSame('/', $r->headers['Location']);
        $r = $this->app(['post_max_bytes' => 1048576])->handle(new Request('POST', '/fahrzeuge/1/dateien', [], [], [], [
            'CONTENT_LENGTH' => '5000000', 'HTTP_HOST' => 'verein.example', 'HTTP_REFERER' => 'https://verein.example//boese.example/x',
        ]));
        $this->assertSame('/', $r->headers['Location']);
    }

    public function test_kleiner_post_ohne_csrf_bleibt_419(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->app(['post_max_bytes' => 8 * 1048576])->handle(new Request('POST', '/fahrzeuge/1/dateien', [], [], [], ['CONTENT_LENGTH' => '100']));
        $this->assertSame(419, $r->status);
    }

    public function test_upload_erstes_foto_wird_titelbild_und_protokolliert(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->post("/fahrzeuge/{$this->fz}/dateien", ['titel' => ''], ['datei' => $this->datei('foto_gedreht.jpg')]);
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/{$this->fz}#dateien", $r->headers['Location']);
        $this->assertSame('erfolg', $this->meldungen()[0]['typ']);
        $titelbild = $this->pdo->query("SELECT titelbild_dokument_id FROM fahrzeug WHERE id = {$this->fz}")->fetchColumn();
        $this->assertNotNull($titelbild);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ='fahrzeug' AND aktion='geaendert'")->fetchColumn());
    }

    public function test_mehrere_dateien_mit_einzelfehler(): void
    {
        $this->anmelden('bearbeiter');
        $a = $this->datei('anleitung.pdf');
        $b = $this->datei('getarnt.jpg');
        $multi = [
            'name' => [$a['name'], $b['name']], 'tmp_name' => [$a['tmp_name'], $b['tmp_name']],
            'error' => [0, 0], 'size' => [$a['size'], $b['size']],
        ];
        $this->post("/fahrzeuge/{$this->fz}/dateien", [], ['datei' => $multi]);
        $texte = array_column($this->meldungen(), 'text', null);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
        $this->assertContains('erfolg', array_column($this->meldungen(), 'typ'));
        $this->assertStringContainsString('getarnt.jpg', implode('|', $texte));
        $this->assertStringContainsString('passt nicht zur Endung .jpg', implode('|', $texte));
    }

    public function test_ohne_datei_meldung(): void
    {
        $this->anmelden('bearbeiter');
        $this->post("/fahrzeuge/{$this->fz}/dateien");
        $this->assertSame('Es wurde keine Datei ausgewählt.', $this->meldungen()[0]['text']);
    }

    public function test_titelbild_setzen_und_loeschen(): void
    {
        $this->anmelden('bearbeiter');
        $a = $this->dokument('foto_gedreht.jpg', 'foto');
        $b = $this->dokument('foto_gedreht.jpg', 'foto');
        $pdf = $this->dokument('anleitung.pdf', 'anleitung');
        $this->post("/fahrzeuge/{$this->fz}/titelbild", ['dokument_id' => (string) $b]);
        $this->assertSame($b, (int) $this->pdo->query("SELECT titelbild_dokument_id FROM fahrzeug WHERE id = {$this->fz}")->fetchColumn());
        $this->post("/fahrzeuge/{$this->fz}/titelbild", ['dokument_id' => (string) $pdf]);
        $this->assertSame($b, (int) $this->pdo->query("SELECT titelbild_dokument_id FROM fahrzeug WHERE id = {$this->fz}")->fetchColumn());
        $stand = $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = {$this->fz}")->fetchColumn();
        usleep(10000);
        $r = $this->post("/dateien/$b/loeschen");
        $this->assertSame("/fahrzeuge/{$this->fz}#dateien", $r->headers['Location']);
        $this->assertNull($this->pdo->query("SELECT titelbild_dokument_id FROM fahrzeug WHERE id = {$this->fz}")->fetchColumn());
        $this->assertSame($stand, $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = {$this->fz}")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM dokument WHERE id = $b")->fetchColumn());
        $this->assertGreaterThanOrEqual(1, (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_id = {$this->fz}")->fetchColumn());
    }

    public function test_detailseite_zeigt_dateien_tab(): void
    {
        $this->anmelden('bearbeiter');
        $this->dokument('foto_gedreht.jpg', 'foto');
        $this->dokument('anleitung.pdf', 'anleitung');
        $r = $this->get("/fahrzeuge/{$this->fz}");
        $this->assertStringContainsString('/vorschau', $r->body);
        $this->assertStringContainsString('anleitung.pdf', $r->body);
        $this->assertStringContainsString('Als Titelbild', $r->body);
    }
}
