<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\OneDrive;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\UploadService;
use Emc\OneDrive\HttpClient;
use Emc\OneDrive\Uebertragung;

final class DateiOneDriveHttpTest extends OneDriveTestCase
{
    private array $sitzung = [];
    private int $fz;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->fz = $this->fahrzeug();
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin'));
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $t) {
            @unlink($t);
        }
        parent::tearDown();
    }

    private function app(bool $konfiguriert = true, array $extra = []): App
    {
        $config = ['speicher_pfad' => $this->speicher, 'upload_max_mb' => 64, 'onedrive_originale_lokal_behalten' => false] + $extra + self::testConfig();
        if ($konfiguriert) {
            $config += ['onedrive_client_id' => 'cid', 'onedrive_client_secret' => 'sec', 'app_schluessel' => base64_encode(str_repeat('k', 32))];
        }
        $app = App::erzeuge($config, new Session($this->sitzung));
        $app->container()->set(HttpClient::class, fn() => $this->http);
        // Testnaht: keine echten HTTP-Uploads; Einbindung der Übertragung bleibt die echte.
        $app->container()->set(UploadService::class, fn($c) => new UploadService(
            $this->pdo,
            $this->speicher,
            64 * 1048576,
            new Bildbearbeitung(),
            fn($t) => is_file($t),
            fn($a, $b) => copy($a, $b),
            $c->get(Uebertragung::class)
        ));
        return $app;
    }

    private function get(string $pfad, bool $konfiguriert = true): Response
    {
        return $this->app($konfiguriert)->handle(new Request('GET', $pfad));
    }

    private function hochladen(string $fixture, bool $konfiguriert = true): Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $this->temp[] = $tmp;
        copy(dirname(__DIR__, 2) . '/fixtures/dateien/' . $fixture, $tmp);
        $datei = ['name' => $fixture, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
        return $this->app($konfiguriert)->handle(new Request('POST', "/fahrzeuge/{$this->fz}/dateien", [], ['_csrf' => str_repeat('a', 64)], ['datei' => $datei]));
    }

    /** @return array<string,mixed> */
    private function letztes(): array
    {
        return $this->pdo->query('SELECT * FROM dokument ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
    }

    public function testUploadUebertraegtSofortUndEntferntOriginal(): void
    {
        $this->verbinde();
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'foto_gedreht.jpg']);
        $r = $this->hochladen('foto_gedreht.jpg');
        self::assertSame(303, $r->status);
        $d = $this->letztes();
        self::assertSame('onedrive', $d['ablage']);
        self::assertSame('Fahrzeuge/EMC-0001 V 200 060/foto_gedreht.jpg', $d['onedrive_pfad']);
        self::assertSame(0, (int) $d['original_lokal']);
        self::assertFileDoesNotExist($this->speicher . '/' . $d['pfad']);
        self::assertFileExists($this->speicher . '/' . $d['pfad'] . '.vorschau.jpg');
        self::assertSame(200, $this->get('/dateien/' . $d['id'] . '/vorschau')->status);
    }

    public function testUploadBeiServerfehlerBleibtLokalNutzbar(): void
    {
        $this->verbinde();
        $this->http->antwort(503);
        $r = $this->hochladen('anleitung.pdf');
        self::assertSame(303, $r->status);
        $d = $this->letztes();
        self::assertSame('lokal', $d['ablage']);
        self::assertFileExists($this->speicher . '/' . $d['pfad']);
        self::assertSame('wartet', $this->pdo->query('SELECT status FROM onedrive_auftrag')->fetchColumn());
        $abruf = $this->get('/dateien/' . $d['id']);
        self::assertSame(200, $abruf->status);
        self::assertSame('application/pdf', $abruf->headers['Content-Type']);
        $tab = $this->get("/fahrzeuge/{$this->fz}");
        self::assertStringContainsString('wird übertragen', $tab->body);
        self::assertStringNotContainsString('503', json_encode($this->sitzung['_meldungen'] ?? []));
    }

    public function testUploadMitAusnahmeInUebertragungBrichtNichtAb(): void
    {
        $this->verbinde();
        $this->http->antwort(500, 'kaputt');
        $r = $this->hochladen('anleitung.pdf');
        self::assertSame(303, $r->status);
        self::assertSame('lokal', $this->letztes()['ablage']);
    }

    public function testOriginalAusOneDriveMitHeadern(): void
    {
        $this->verbinde();
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'anleitung.pdf']);
        $this->hochladen('anleitung.pdf');
        $d = $this->letztes();
        $inhalt = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/dateien/anleitung.pdf');
        $this->http->antwort(200, $inhalt);
        $r = $this->get('/dateien/' . $d['id']);
        self::assertSame(200, $r->status);
        self::assertSame($inhalt, file_get_contents((string) $r->datei));
        self::assertTrue($r->loescheNachSenden);
        self::assertSame('application/pdf', $r->headers['Content-Type']);
        self::assertStringStartsWith('inline', $r->headers['Content-Disposition']);
        self::assertStringContainsString("filename*=UTF-8''anleitung.pdf", $r->headers['Content-Disposition']);
        self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        self::assertSame('private, max-age=3600', $r->headers['Cache-Control']);
        $letzte = end($this->http->anfragen);
        self::assertStringEndsWith('/drives/D1/items/ITEM1/content', $letzte['url']);
    }

    public function testBildOriginalAusOneDriveMitCsp(): void
    {
        $this->verbinde();
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'foto_gedreht.jpg']);
        $this->hochladen('foto_gedreht.jpg');
        $d = $this->letztes();
        $this->http->antwort(200, 'JPEGBYTES');
        $r = $this->get('/dateien/' . $d['id']);
        self::assertSame('JPEGBYTES', file_get_contents((string) $r->datei));
        self::assertSame("default-src 'none'; sandbox", $r->headers['Content-Security-Policy']);
    }

    public function testOneDriveNichtErreichbarErgibt503AberVorschauBleibt(): void
    {
        $this->verbinde();
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'foto_gedreht.jpg']);
        $this->hochladen('foto_gedreht.jpg');
        $d = $this->letztes();
        $this->http->antwort(503);
        $r = $this->get('/dateien/' . $d['id']);
        self::assertSame(503, $r->status);
        self::assertStringContainsString('<html', $r->body, 'normale Layout-Fehlerseite');
        self::assertStringContainsString('Die Originaldatei liegt im OneDrive und ist gerade nicht erreichbar. Die Vorschau bleibt verfügbar; bitte später erneut versuchen.', $r->body);
        self::assertSame(200, $this->get('/dateien/' . $d['id'] . '/vorschau')->status);
        self::assertSame(200, $this->get('/dateien/' . $d['id'] . '/anzeige')->status);
    }

    public function testPdfVorschauAnfrageFaelltAufOneDriveOriginalZurueck(): void
    {
        $this->verbinde();
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'anleitung.pdf']);
        $this->hochladen('anleitung.pdf');
        $d = $this->letztes();
        $this->http->antwort(200, '%PDF-fake');
        $r = $this->get('/dateien/' . $d['id'] . '/vorschau');
        self::assertSame(200, $r->status);
        self::assertSame('%PDF-fake', file_get_contents((string) $r->datei));
    }

    public function testLoeschenReihtLoeschauftragEinUndFuehrtAus(): void
    {
        $this->verbinde();
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'anleitung.pdf']);
        $this->hochladen('anleitung.pdf');
        $d = $this->letztes();
        $this->http->antwort(204);
        $r = $this->app()->handle(new Request('POST', '/dateien/' . $d['id'] . '/loeschen', [], ['_csrf' => str_repeat('a', 64)]));
        self::assertSame(303, $r->status);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
        $letzte = end($this->http->anfragen);
        self::assertSame('DELETE', $letzte['methode']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'loeschen' AND status = 'erledigt'")->fetchColumn());
    }

    public function testLoeschenBeiAusfallBleibtAuftragOffen(): void
    {
        $this->verbinde();
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'anleitung.pdf']);
        $this->hochladen('anleitung.pdf');
        $d = $this->letztes();
        $this->http->antwort(503);
        $r = $this->app()->handle(new Request('POST', '/dateien/' . $d['id'] . '/loeschen', [], ['_csrf' => str_repeat('a', 64)]));
        self::assertSame(303, $r->status);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'loeschen' AND status = 'wartet'")->fetchColumn());
    }

    public function testOhneKonfigurationKeinerleiAuftraegeUndKeineAnfragen(): void
    {
        $r = $this->hochladen('anleitung.pdf', false);
        self::assertSame(303, $r->status);
        $d = $this->letztes();
        self::assertSame('lokal', $d['ablage']);
        self::assertSame(200, $this->get('/dateien/' . $d['id'], false)->status);
        $tab = $this->get("/fahrzeuge/{$this->fz}", false);
        self::assertSame(200, $tab->status);
        self::assertStringNotContainsString('nur lokal', $tab->body);
        $this->app(false)->handle(new Request('POST', '/dateien/' . $d['id'] . '/loeschen', [], ['_csrf' => str_repeat('a', 64)]));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM onedrive_auftrag')->fetchColumn());
        self::assertSame([], $this->http->anfragen);
    }

    public function testStatusSymbolImOneDrive(): void
    {
        $this->verbinde();
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'anleitung.pdf']);
        $this->hochladen('anleitung.pdf');
        self::assertStringContainsString('im OneDrive', $this->get("/fahrzeuge/{$this->fz}")->body);
    }

    public function testStatusSymbolNurLokalWennKonfiguriertAberNichtVerbunden(): void
    {
        $this->hochladen('anleitung.pdf', true);
        self::assertStringContainsString('nur lokal', $this->get("/fahrzeuge/{$this->fz}")->body);
    }

    public function testFalscheKonfigurationBrichtAppNichtAb(): void
    {
        $app = App::erzeuge(['speicher_pfad' => $this->speicher, 'onedrive_client_id' => 'a', 'onedrive_client_secret' => 'b', 'app_schluessel' => 'kein-gueltiger-schluessel'] + self::testConfig(), new Session($this->sitzung));
        self::assertNull($app->container()->get(Uebertragung::class));
        $app2 = App::erzeuge(['speicher_pfad' => $this->speicher] + self::testConfig(), new Session($this->sitzung));
        self::assertNull($app2->container()->get(Uebertragung::class));
    }

    public function testStandardBehaeltOriginalLokal(): void
    {
        $this->verbinde();
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'anleitung.pdf']);
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $this->temp[] = $tmp;
        copy(dirname(__DIR__, 2) . '/fixtures/dateien/anleitung.pdf', $tmp);
        $config = ['speicher_pfad' => $this->speicher, 'upload_max_mb' => 64, 'onedrive_client_id' => 'cid', 'onedrive_client_secret' => 'sec', 'app_schluessel' => base64_encode(str_repeat('k', 32))] + self::testConfig();
        $app = App::erzeuge($config, new Session($this->sitzung));
        $app->container()->set(HttpClient::class, fn() => $this->http);
        $app->container()->set(UploadService::class, fn($c) => new UploadService($this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b), $c->get(Uebertragung::class)));
        $datei = ['name' => 'anleitung.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
        $app->handle(new Request('POST', "/fahrzeuge/{$this->fz}/dateien", [], ['_csrf' => str_repeat('a', 64)], ['datei' => $datei]));
        $d = $this->letztes();
        self::assertSame('onedrive', $d['ablage']);
        self::assertSame(1, (int) $d['original_lokal']);
        self::assertFileExists($this->speicher . '/' . $d['pfad']);
    }

    public function testUploadBeiAbgelaufenerVerbindungReihtAuftragEin(): void
    {
        $this->verbinde();
        $this->tokens->setzeStatus('abgelaufen');
        $r = $this->hochladen('anleitung.pdf');
        self::assertSame(303, $r->status);
        $d = $this->letztes();
        self::assertSame('lokal', $d['ablage']);
        self::assertSame([], $this->http->anfragen);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'hochladen' AND status = 'wartet'")->fetchColumn());
        $this->tokens->setzeStatus('verbunden');
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'anleitung.pdf']);
        $u = $this->app()->container()->get(Uebertragung::class);
        self::assertSame(1, $u->verarbeite(5)['erledigt']);
        self::assertSame('onedrive', $this->dok((int) $d['id'])['ablage']);
    }

    public function testOriginalNachAbbruchOhneVermerkWirdAusOneDriveGeliefert(): void
    {
        $this->verbinde();
        $id = $this->dokument();
        $this->pdo->prepare("UPDATE dokument SET ablage = 'onedrive', onedrive_item_id = 'ITEMX', original_lokal = 1 WHERE id = ?")->execute([$id]);
        unlink($this->speicher . '/' . $this->dok($id)['pfad']);
        $this->http->antwort(200, 'AUS-ONEDRIVE');
        $r = $this->get("/dateien/$id");
        self::assertSame(200, $r->status);
        self::assertSame('AUS-ONEDRIVE', file_get_contents((string) $r->datei));
    }

    public function testFehlendeLokaleDateiOhneOneDriveBleibt404(): void
    {
        $id = $this->dokument();
        unlink($this->speicher . '/' . $this->dok($id)['pfad']);
        self::assertSame(404, $this->get("/dateien/$id")->status);
    }

    public function testSicherungZeigtOriginaleNurImOneDrive(): void
    {
        $this->einstellungen->setze('onedrive_ziel', json_encode(['drive_id' => 'D1', 'item_id' => 'R', 'name' => 'EMC-Archiv']));
        $a = $this->dokument();
        $b = $this->dokument();
        $c = $this->dokument();
        $this->pdo->exec("UPDATE dokument SET ablage = 'onedrive', original_lokal = 0 WHERE id IN ($a, $b)");
        $this->pdo->exec("UPDATE dokument SET ablage = 'onedrive', original_lokal = 1 WHERE id = $c");
        $seite = $this->get('/admin/sicherung')->body;
        self::assertStringContainsString('2 Originale liegen nur im OneDrive', $seite);
        self::assertStringContainsString('EMC-Archiv', $seite);
        $s = new \Emc\Betrieb\Sicherung($this->pdo, $this->speicher, $this->einstellungen);
        $zip = sys_get_temp_dir() . '/emc_od_' . bin2hex(random_bytes(4)) . '.zip';
        $s->erstelle($zip);
        $z = new \ZipArchive();
        $z->open($zip);
        self::assertStringContainsString('2 Originale liegen nur im OneDrive (Zielordner: EMC-Archiv)', (string) $z->getFromName('info.txt'));
        $z->close();
        unlink($zip);
    }

    public function testSicherungOhneNurOneDriveDokumenteKeineWarnung(): void
    {
        $this->dokument();
        self::assertStringNotContainsString('nur im OneDrive', $this->get('/admin/sicherung', false)->body);
    }

    public function testAppSetztZeitzone(): void
    {
        $alt = date_default_timezone_get();
        try {
            App::erzeuge(['zeitzone' => 'Asia/Tokyo'] + self::testConfig(), new Session($this->sitzung));
            self::assertSame('Asia/Tokyo', date_default_timezone_get());
            App::erzeuge(['zeitzone' => 'Quatsch/Zone'] + self::testConfig(), new Session($this->sitzung));
            self::assertSame('Europe/Berlin', date_default_timezone_get());
            App::erzeuge(self::testConfig(), new Session($this->sitzung));
            self::assertSame('Europe/Berlin', date_default_timezone_get());
        } finally {
            date_default_timezone_set($alt);
        }
    }
}
