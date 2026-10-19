<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\OneDrive;

use Emc\Auth\Auth;
use Emc\Core\App;
use Emc\Core\Instanz;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\OneDrive\HttpClient;

final class OneDriveHttpTest extends OneDriveTestCase
{
    private const CSRF = 'a';

    private array $sitzung = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->anmelden('admin@verein.de', 'admin');
    }

    /** @return array<string,mixed> */
    private function konfig(): array
    {
        return ['speicher_pfad' => $this->speicher, 'basis_url' => '/emc', 'oeffentliche_url' => 'https://verein.example'] + self::testConfig();
    }

    private function anmelden(string $email, string $rolle): void
    {
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle));
        $this->sitzung[Auth::INSTANZ_KEY] = Instanz::fingerabdruck($this->konfig());
        $this->sitzung['_csrf'] = str_repeat(self::CSRF, 64);
    }

    private function app(bool $konfiguriert = true): App
    {
        $config = $this->konfig();
        if ($konfiguriert) {
            $config += ['onedrive_client_id' => 'cid', 'onedrive_client_secret' => 'sec', 'app_schluessel' => base64_encode(str_repeat('k', 32))];
        }
        $app = App::erzeuge($config, new Session($this->sitzung));
        $app->container()->set(HttpClient::class, fn() => $this->http);
        return $app;
    }

    private function get(string $pfad, array $query = [], bool $konfiguriert = true): Response
    {
        return $this->app($konfiguriert)->handle(new Request('GET', $pfad, $query));
    }

    private function post(string $pfad, array $daten = [], bool $konfiguriert = true): Response
    {
        return $this->app($konfiguriert)->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat(self::CSRF, 64)]));
    }

    /** @return list<string> */
    private function meldungen(): array
    {
        return array_map(fn($m) => $m['text'], $this->sitzung['_meldungen'] ?? []);
    }

    private function alsRolle(string $rolle): void
    {
        $this->anmelden($rolle . '@verein.de', $rolle);
    }

    public function testNichtAdminBekommt403AufAllenRouten(): void
    {
        foreach (['mitglied', 'bearbeiter'] as $rolle) {
            $this->alsRolle($rolle);
            foreach (['/admin/onedrive', '/admin/onedrive/rueckruf', '/admin/onedrive/ordner'] as $pfad) {
                self::assertSame(403, $this->get($pfad)->status, "$rolle GET $pfad");
            }
            foreach (['/admin/onedrive/verbinden', '/admin/onedrive/ordner', '/admin/onedrive/uebertragen', '/admin/onedrive/umzug', '/admin/onedrive/trennen'] as $pfad) {
                self::assertSame(403, $this->post($pfad)->status, "$rolle POST $pfad");
            }
        }
    }

    public function testSeiteOhneKonfigurationZeigtEinrichtungshinweise(): void
    {
        $r = $this->get('/admin/onedrive', [], false);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('onedrive_client_id', $r->body);
        self::assertStringContainsString('app_schluessel', $r->body);
        self::assertStringContainsString('https://verein.example/emc/admin/onedrive/rueckruf', $r->body);
        self::assertStringNotContainsString('Mit OneDrive verbinden', $r->body);
        // Vorschlag für einen Schlüssel: 32 Byte base64, wird nicht gespeichert.
        self::assertSame(1, preg_match('/<code[^>]*id="schluessel-vorschlag"[^>]*>([A-Za-z0-9+\/=]{44})</', $r->body, $m));
        self::assertSame(32, strlen((string) base64_decode($m[1], true)));
        self::assertNull($this->einstellungen->hole('app_schluessel'));
    }

    public function testRueckrufUrlInContainerUndSeiteIdentischUndAbsolut(): void
    {
        $app = $this->app();
        $r = new \ReflectionProperty(\Emc\OneDrive\OAuth::class, 'rueckrufUrl');
        $url = $r->getValue($app->container()->get(\Emc\OneDrive\OAuth::class));
        self::assertSame('https://verein.example/emc/admin/onedrive/rueckruf', $url);
        self::assertStringContainsString($url, $app->handle(new Request('GET', '/admin/onedrive'))->body);
    }

    public function testOhneOeffentlicheUrlOderMitHttpNichtKonfiguriert(): void
    {
        foreach ([null, 'http://verein.example'] as $wert) {
            $config = $this->konfig() + ['onedrive_client_id' => 'cid', 'onedrive_client_secret' => 'sec', 'app_schluessel' => base64_encode(str_repeat('k', 32))];
            unset($config['oeffentliche_url']);
            if ($wert !== null) {
                $config['oeffentliche_url'] = $wert;
            }
            $app = App::erzeuge($config, new Session($this->sitzung));
            $body = $app->handle(new Request('GET', '/admin/onedrive'))->body;
            self::assertStringContainsString('oeffentliche_url', $body);
            self::assertStringNotContainsString('Mit OneDrive verbinden', $body);
        }
    }

    public function testGueltigerSchluesselBeiFehlenderUrlWirdNichtAlsUngueltigGemeldet(): void
    {
        $config = $this->konfig() + ['onedrive_client_id' => 'cid', 'onedrive_client_secret' => 'sec', 'app_schluessel' => base64_encode(str_repeat('k', 32))];
        unset($config['oeffentliche_url']);
        $body = App::erzeuge($config, new Session($this->sitzung))->handle(new Request('GET', '/admin/onedrive'))->body;
        self::assertStringNotContainsString('ungültig (32 Byte', $body);
        self::assertSame(1, preg_match('/app_schluessel<\/code>\s*<span class="badge text-bg-success">gesetzt/', $body));

        $config['app_schluessel'] = 'kaputt';
        $body = App::erzeuge($config, new Session($this->sitzung))->handle(new Request('GET', '/admin/onedrive'))->body;
        self::assertStringContainsString('ungültig (32 Byte', $body);
    }

    public function testKontowechselSetztZielordnerZurueckUndUebertraegtNicht(): void
    {
        $this->verbinde();
        $this->einstellungen->setze('onedrive_konto', 'alt@outlook.de');
        $this->einstellungen->setze('onedrive_konto_id', 'ID-ALT');
        $dok = $this->dokument();
        $this->pdo->prepare("INSERT INTO onedrive_auftrag (art, dokument_id, naechster_versuch) VALUES ('hochladen', ?, NOW())")->execute([$dok]);
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $this->http->antwort(200, ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]);
        $this->http->antwort(200, ['id' => 'ID-NEU', 'mail' => 'neu@outlook.de']);
        $r = $this->get('/admin/onedrive/rueckruf', ['code' => 'C', 'state' => str_repeat('a', 64)]);
        self::assertStringEndsWith('/admin/onedrive/ordner', $r->headers['Location']);
        self::assertSame('', (string) $this->einstellungen->hole('onedrive_ziel'));
        self::assertSame('ID-NEU', $this->einstellungen->hole('onedrive_konto_id'));
        self::assertCount(2, $this->http->anfragen, 'keine Übertragung nach Kontowechsel');
        self::assertSame('lokal', $this->dok($dok)['ablage']);
        self::assertStringContainsString('anderes Microsoft-Konto', implode(' ', $this->meldungen()));
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'onedrive' AND aenderungen LIKE '%zielordner%'")->fetchColumn();
        self::assertSame(1, $n);
    }

    public function testGleichesKontoBehaeltZielordnerUndMerktErstmaligDieKontoId(): void
    {
        $this->verbinde();
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $this->http->antwort(200, ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]);
        $this->http->antwort(200, ['id' => 'ID-1', 'mail' => 'a@outlook.de']);
        $this->get('/admin/onedrive/rueckruf', ['code' => 'C', 'state' => str_repeat('a', 64)]);
        self::assertSame('ID-1', $this->einstellungen->hole('onedrive_konto_id'));
        self::assertNotSame('', (string) $this->einstellungen->hole('onedrive_ziel'));

        $this->sitzung['onedrive_state'] = str_repeat('b', 64);
        $this->http->antwort(200, ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]);
        $this->http->antwort(200, ['id' => 'ID-1', 'mail' => 'a@outlook.de']);
        $this->get('/admin/onedrive/rueckruf', ['code' => 'C', 'state' => str_repeat('b', 64)]);
        self::assertNotSame('', (string) $this->einstellungen->hole('onedrive_ziel'));
    }

    /** Bereitet einen Rückruf mit Zielordner und einem wartenden Upload vor. */
    private function rueckrufMitWarteschlange(): int
    {
        $this->verbinde();
        $dok = $this->dokument();
        $this->pdo->prepare("INSERT INTO onedrive_auftrag (art, dokument_id, naechster_versuch) VALUES ('hochladen', ?, NOW())")->execute([$dok]);
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $this->http->antwort(200, ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]);
        return $dok;
    }

    public function testKontoNichtLesbarHaeltVerbindungAberUebertraegtNicht(): void
    {
        $this->einstellungen->setze('onedrive_konto', 'alt@outlook.de');
        $this->einstellungen->setze('onedrive_konto_id', 'ID-ALT');
        $dok = $this->rueckrufMitWarteschlange();
        $this->http->antwort(503, ['error' => ['code' => 'serviceNotAvailable']]);
        $r = $this->get('/admin/onedrive/rueckruf', ['code' => 'C', 'state' => str_repeat('a', 64)]);
        self::assertSame(303, $r->status);
        self::assertStringEndsWith('/admin/onedrive', $r->headers['Location']);
        self::assertSame('verbunden', $this->tokens->status());
        self::assertNotSame('', (string) $this->einstellungen->hole('onedrive_ziel'));
        self::assertSame('lokal', $this->dok($dok)['ablage']);
        foreach ($this->http->anfragen as $a) {
            self::assertStringNotContainsString('/content', $a['url'], 'kein Upload ohne bekanntes Konto');
        }
        // Bisherige Kontoangaben bleiben für eine spätere Wechselerkennung erhalten.
        self::assertSame('ID-ALT', $this->einstellungen->hole('onedrive_konto_id'));
        self::assertSame('alt@outlook.de', $this->einstellungen->hole('onedrive_konto'));
        self::assertStringContainsString('Zielordner noch stimmt', implode(' ', $this->meldungen()));
    }

    public function testKontoOhneIdUebertraegtNicht(): void
    {
        $dok = $this->rueckrufMitWarteschlange();
        $this->http->antwort(200, ['mail' => 'v@outlook.de']);
        $r = $this->get('/admin/onedrive/rueckruf', ['code' => 'C', 'state' => str_repeat('a', 64)]);
        self::assertStringEndsWith('/admin/onedrive', $r->headers['Location']);
        self::assertCount(2, $this->http->anfragen);
        self::assertSame('lokal', $this->dok($dok)['ablage']);
        self::assertSame('v@outlook.de', $this->einstellungen->hole('onedrive_konto'));
        self::assertStringContainsString('Zielordner noch stimmt', implode(' ', $this->meldungen()));
    }

    public function testKontowechselOhneGespeicherteIdWirdUeberDenNamenErkannt(): void
    {
        $this->einstellungen->setze('onedrive_konto', 'alt@outlook.de');
        $dok = $this->rueckrufMitWarteschlange();
        $this->http->antwort(200, ['id' => 'ID-NEU', 'mail' => 'neu@outlook.de']);
        $r = $this->get('/admin/onedrive/rueckruf', ['code' => 'C', 'state' => str_repeat('a', 64)]);
        self::assertStringEndsWith('/admin/onedrive/ordner', $r->headers['Location']);
        self::assertSame('', (string) $this->einstellungen->hole('onedrive_ziel'));
        self::assertSame('ID-NEU', $this->einstellungen->hole('onedrive_konto_id'));
        self::assertCount(2, $this->http->anfragen);
        self::assertSame('lokal', $this->dok($dok)['ablage']);
        self::assertStringContainsString('anderes Microsoft-Konto', implode(' ', $this->meldungen()));
    }

    public function testGleicherNameOhneGespeicherteIdBehaeltZielordner(): void
    {
        $this->einstellungen->setze('onedrive_konto', 'Verein@Outlook.de');
        $dok = $this->rueckrufMitWarteschlange();
        $this->http->antwort(200, ['id' => 'ID-1', 'mail' => 'verein@outlook.de']);
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'Anleitung.pdf']);
        $r = $this->get('/admin/onedrive/rueckruf', ['code' => 'C', 'state' => str_repeat('a', 64)]);
        self::assertStringEndsWith('/admin/onedrive', $r->headers['Location']);
        self::assertNotSame('', (string) $this->einstellungen->hole('onedrive_ziel'));
        self::assertSame('ID-1', $this->einstellungen->hole('onedrive_konto_id'));
        self::assertSame('onedrive', $this->dok($dok)['ablage']);
    }

    public function testIdMitZeilenumbruchAmEndeWirdAbgelehnt(): void
    {
        $this->verbinde(false);
        $r = $this->get('/admin/onedrive/ordner', ['drive' => "D1\n", 'item' => 'I1']);
        self::assertSame(303, $r->status);
        $r = $this->get('/admin/onedrive/ordner', ['drive' => 'D1', 'item' => "I1\n"]);
        self::assertSame(303, $r->status);
        self::assertSame([], $this->http->anfragen);
    }

    public function testProtokollHatEchteAltWerte(): void
    {
        $this->verbinde();
        $this->tokens->setzeStatus('abgelaufen');
        $this->post('/admin/onedrive/trennen');
        $z = (string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'onedrive' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertSame('abgelaufen', json_decode($z, true)['verbindung']['alt']);
    }

    public function testRueckrufMitGueltigemStateAberOhneCode(): void
    {
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $r = $this->get('/admin/onedrive/rueckruf', ['state' => str_repeat('a', 64)]);
        self::assertSame(303, $r->status);
        self::assertSame([], $this->http->anfragen);
        self::assertSame('', (string) $this->einstellungen->hole('onedrive_token'));
        self::assertStringContainsString('Anmeldecode', implode(' ', $this->meldungen()));
    }

    public function testUnbekannterFehlerparameterWirdNichtUngefiltertAusgegeben(): void
    {
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $this->get('/admin/onedrive/rueckruf', ['error' => 'server_error', 'state' => str_repeat('a', 64)]);
        self::assertStringContainsString('server_error', implode(' ', $this->meldungen()));
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $this->sitzung['_meldungen'] = [];
        $this->get('/admin/onedrive/rueckruf', ['error' => '<script>alert(1)</script>', 'state' => str_repeat('a', 64)]);
        self::assertStringNotContainsString('<script>', implode(' ', $this->meldungen()));
    }

    public function testOrdnernameMitMarkupWirdMaskiert(): void
    {
        $this->verbinde(false);
        $liste = ['value' => [['id' => 'F1', 'name' => '<script>alert(1)</script>', 'folder' => [], 'parentReference' => ['driveId' => 'D1']]]];
        $this->http->antwort(200, $liste)->antwort(200, ['value' => []]);
        $body = $this->get('/admin/onedrive/ordner')->body;
        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);

        $this->http->antwort(200, $liste)->antwort(200, ['value' => []]);
        $this->post('/admin/onedrive/ordner', ['drive_id' => 'D1', 'item_id' => 'F1']);
        $body = $this->get('/admin/onedrive')->body;
        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testPunktIdsWerdenAbgelehnt(): void
    {
        $this->verbinde(false);
        foreach (['..', '.'] as $id) {
            $r = $this->get('/admin/onedrive/ordner', ['drive' => 'D1', 'item' => $id]);
            self::assertSame(303, $r->status);
        }
        self::assertSame([], $this->http->anfragen);
    }

    public function testRueckrufOhneAnmeldungLeitetZumLogin(): void
    {
        $this->sitzung = [];
        $r = $this->get('/admin/onedrive/rueckruf', ['code' => 'C', 'state' => 'x']);
        self::assertSame(303, $r->status);
        self::assertStringContainsString('/login', $r->headers['Location']);
        self::assertSame([], $this->http->anfragen);
    }

    public function testOeffentlicheUrlPruefungIstGenau(): void
    {
        $ok = ['onedrive_client_id' => 'a', 'onedrive_client_secret' => 'b', 'app_schluessel' => 'c'];
        foreach (['https://-', 'https://.', 'https://a..b', 'https://a.de:99999', 'https://a.de:0', 'https://u@a.de', 'https://a.de/pfad', 'http://a.de'] as $u) {
            self::assertNull(\Emc\OneDrive\Uebertragung::rueckrufUrl(['oeffentliche_url' => $u] + $ok), $u);
        }
        self::assertSame('https://mitglieder.emc78.de:8443/admin/onedrive/rueckruf', \Emc\OneDrive\Uebertragung::rueckrufUrl(['oeffentliche_url' => 'HTTPS://mitglieder.emc78.de:8443'] + $ok));
    }

    public function testSeiteKonfiguriertZeigtVerbindenKnopfUndStatus(): void
    {
        $r = $this->get('/admin/onedrive');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Mit OneDrive verbinden', $r->body);
        self::assertStringContainsString('getrennt', $r->body);
    }

    public function testSeiteZeigtKontoZielordnerWarteschlangeUndLetztenFehler(): void
    {
        $this->verbinde();
        $this->einstellungen->setze('onedrive_konto', 'verein@outlook.de');
        $this->einstellungen->setze('onedrive_letzter_fehler', 'Anmeldung bei Microsoft fehlgeschlagen: invalid_client');
        $this->pdo->exec("INSERT INTO onedrive_auftrag (art, dokument_id, status, naechster_versuch) VALUES ('hochladen', 1, 'wartet', NOW()), ('hochladen', 2, 'fehler', NOW())");
        $r = $this->get('/admin/onedrive');
        self::assertStringContainsString('verein@outlook.de', $r->body);
        self::assertStringContainsString('EMC', $r->body);
        self::assertStringContainsString('invalid_client', $r->body);
        self::assertStringContainsString('verbunden', $r->body);
    }

    public function testVerbindenSetztStateUndLeitetZuMicrosoft(): void
    {
        $r = $this->post('/admin/onedrive/verbinden');
        self::assertSame(303, $r->status);
        $ziel = $r->headers['Location'];
        self::assertStringStartsWith('https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize?', $ziel);
        parse_str((string) parse_url($ziel, PHP_URL_QUERY), $q);
        self::assertSame($this->sitzung['onedrive_state'], $q['state']);
        self::assertSame(64, strlen($q['state']));
        self::assertSame('https://verein.example/emc/admin/onedrive/rueckruf', $q['redirect_uri']);
    }

    public function testVerbindenOhneKonfigurationLeitetMitMeldungZurueck(): void
    {
        $r = $this->post('/admin/onedrive/verbinden', [], false);
        self::assertSame(303, $r->status);
        self::assertStringEndsWith('/admin/onedrive', $r->headers['Location']);
        self::assertNotEmpty($this->meldungen());
    }

    public function testRueckrufOhneStateSpeichertNichts(): void
    {
        $r = $this->get('/admin/onedrive/rueckruf', ['code' => 'C']);
        self::assertSame(303, $r->status);
        self::assertSame([], $this->http->anfragen);
        self::assertSame('', (string) $this->einstellungen->hole('onedrive_token'));
        self::assertStringContainsString('ungültig', implode(' ', $this->meldungen()));
    }

    public function testRueckrufMitFalschemStateSpeichertNichts(): void
    {
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $this->get('/admin/onedrive/rueckruf', ['code' => 'C', 'state' => str_repeat('b', 64)]);
        self::assertSame([], $this->http->anfragen);
        self::assertSame('', (string) $this->einstellungen->hole('onedrive_token'));
        self::assertStringContainsString('ungültig', implode(' ', $this->meldungen()));
        // State ist einmalig: auch nach einem Fehlversuch verbraucht.
        self::assertArrayNotHasKey('onedrive_state', $this->sitzung);
    }

    public function testRueckrufMitStateAlsArrayWirdAbgewiesen(): void
    {
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $this->get('/admin/onedrive/rueckruf', ['code' => 'C', 'state' => ['x']]);
        self::assertSame([], $this->http->anfragen);
        self::assertSame('', (string) $this->einstellungen->hole('onedrive_token'));
    }

    public function testRueckrufMitFehlerparameter(): void
    {
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $r = $this->get('/admin/onedrive/rueckruf', ['error' => 'access_denied', 'error_description' => '<b>x</b>', 'state' => str_repeat('a', 64)]);
        self::assertSame(303, $r->status);
        self::assertSame([], $this->http->anfragen);
        self::assertSame('', (string) $this->einstellungen->hole('onedrive_token'));
        self::assertStringContainsString('abgebrochen', implode(' ', $this->meldungen()));
    }

    public function testErfolgreicherRueckrufSpeichertTokenKontoUndStatus(): void
    {
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $this->http->antwort(200, ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]);
        $this->http->antwort(200, ['mail' => 'verein@outlook.de', 'displayName' => 'EMC']);
        $r = $this->get('/admin/onedrive/rueckruf', ['code' => 'CODE', 'state' => str_repeat('a', 64)]);
        self::assertSame(303, $r->status);
        self::assertSame('verbunden', $this->tokens->status());
        self::assertSame('RT', $this->tokens->lade()['refresh_token']);
        self::assertSame('verein@outlook.de', $this->einstellungen->hole('onedrive_konto'));
        self::assertArrayNotHasKey('onedrive_state', $this->sitzung);
        self::assertStringContainsString('code=CODE', (string) $this->http->anfragen[0]['koerper']);
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'onedrive' AND aenderungen LIKE '%verbunden%'")->fetchColumn();
        self::assertSame(1, $n);
        // Ohne Zielordner geht es direkt zur Ordnerauswahl.
        self::assertStringEndsWith('/admin/onedrive/ordner', $r->headers['Location']);
    }

    public function testRueckrufNachVerbindungSetztWarteschlangeFort(): void
    {
        $this->einstellungen->setze('onedrive_ziel', json_encode(['drive_id' => 'D1', 'item_id' => 'ROOT', 'name' => 'EMC']));
        $this->einstellungen->setze('onedrive_letzter_fehler', 'alt');
        $dok = $this->dokument();
        $this->pdo->prepare("INSERT INTO onedrive_auftrag (art, dokument_id, naechster_versuch) VALUES ('hochladen', ?, NOW())")->execute([$dok]);
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $this->http->antwort(200, ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]);
        $this->http->antwort(200, ['id' => 'ID-V', 'userPrincipalName' => 'v@outlook.de']);
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'Anleitung.pdf']);
        $r = $this->get('/admin/onedrive/rueckruf', ['code' => 'CODE', 'state' => str_repeat('a', 64)]);
        self::assertStringEndsWith('/admin/onedrive', $r->headers['Location']);
        self::assertSame('onedrive', $this->dok($dok)['ablage']);
        self::assertNull($this->einstellungen->hole('onedrive_letzter_fehler') ?: null);
    }

    public function testRueckrufMitInvalidGrantZeigtEigeneMeldung(): void
    {
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $this->http->antwort(400, ['error' => 'invalid_grant']);
        $r = $this->get('/admin/onedrive/rueckruf', ['code' => 'CODE', 'state' => str_repeat('a', 64)]);
        self::assertSame(303, $r->status);
        self::assertSame('', (string) $this->einstellungen->hole('onedrive_token'));
        self::assertContains('Die Anmeldung bei Microsoft ist abgelaufen oder wurde schon verwendet – bitte erneut verbinden.', $this->meldungen());
    }

    public function testRueckrufMitServerfehlerIstKeine500(): void
    {
        $this->sitzung['onedrive_state'] = str_repeat('a', 64);
        $this->http->antwort(503);
        $r = $this->get('/admin/onedrive/rueckruf', ['code' => 'CODE', 'state' => str_repeat('a', 64)]);
        self::assertSame(303, $r->status);
        self::assertSame('', (string) $this->einstellungen->hole('onedrive_token'));
        self::assertNotEmpty($this->meldungen());
    }

    public function testOrdnerlisteZeigtOrdnerUndFehlerAlsMeldung(): void
    {
        $this->verbinde(false);
        $this->http->antwort(200, ['value' => [['id' => 'F1', 'name' => 'Vereinsordner', 'folder' => [], 'parentReference' => ['driveId' => 'D1']]]]);
        $this->http->antwort(200, ['value' => []]);
        $r = $this->get('/admin/onedrive/ordner');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Vereinsordner', $r->body);

        $this->http->antwort(503);
        $r = $this->get('/admin/onedrive/ordner');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('nicht geladen', $r->body);
    }

    public function testUnterordnerOeffnenValidiertIds(): void
    {
        $this->verbinde(false);
        $r = $this->get('/admin/onedrive/ordner', ['drive' => '../x?y', 'item' => 'F1']);
        self::assertSame(303, $r->status);
        self::assertSame([], $this->http->anfragen);

        $this->http->antwort(200, ['value' => [['id' => 'F2', 'name' => 'Unter', 'folder' => [], 'parentReference' => ['driveId' => 'D1']]]]);
        $r = $this->get('/admin/onedrive/ordner', ['drive' => 'D1', 'item' => 'F1!5']);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Unter', $r->body);
        self::assertStringContainsString('/drives/D1/items/F1%215/children', $this->http->anfragen[0]['url']);
    }

    public function testOrdnerAuswahlSpeichertZielMitNameAusDerListe(): void
    {
        $this->verbinde(false);
        $this->http->antwort(200, ['value' => [['id' => 'F1', 'name' => 'Vereinsordner', 'folder' => [], 'parentReference' => ['driveId' => 'D1']]]]);
        $this->http->antwort(200, ['value' => []]);
        $r = $this->post('/admin/onedrive/ordner', ['drive_id' => 'D1', 'item_id' => 'F1', 'name' => 'Gefälscht']);
        self::assertSame(303, $r->status);
        $ziel = json_decode((string) $this->einstellungen->hole('onedrive_ziel'), true);
        self::assertSame(['drive_id' => 'D1', 'item_id' => 'F1', 'name' => 'Vereinsordner'], $ziel);
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'onedrive' AND aenderungen LIKE '%zielordner%'")->fetchColumn();
        self::assertSame(1, $n);
    }

    public function testOrdnerAuswahlAusUnterordner(): void
    {
        $this->verbinde(false);
        $this->http->antwort(200, ['value' => [['id' => 'F2', 'name' => 'Unter', 'folder' => [], 'parentReference' => ['driveId' => 'D1']]]]);
        $this->post('/admin/onedrive/ordner', ['drive_id' => 'D1', 'item_id' => 'F2', 'eltern_drive' => 'D1', 'eltern_item' => 'F1']);
        self::assertStringContainsString('/drives/D1/items/F1/children', $this->http->anfragen[0]['url']);
        self::assertSame('F2', json_decode((string) $this->einstellungen->hole('onedrive_ziel'), true)['item_id']);
    }

    public function testOrdnerAuswahlMitFremderIdWirdAbgelehnt(): void
    {
        $this->verbinde(false);
        $this->http->antwort(200, ['value' => [['id' => 'F1', 'name' => 'Vereinsordner', 'folder' => [], 'parentReference' => ['driveId' => 'D1']]]]);
        $this->http->antwort(200, ['value' => []]);
        $this->post('/admin/onedrive/ordner', ['drive_id' => 'D1', 'item_id' => 'ANDERER']);
        self::assertNull($this->einstellungen->hole('onedrive_ziel'));
        self::assertNotEmpty($this->meldungen());
    }

    public function testOrdnerAuswahlBeiGraphFehlerSpeichertNichts(): void
    {
        $this->verbinde(false);
        $this->http->antwort(500);
        $r = $this->post('/admin/onedrive/ordner', ['drive_id' => 'D1', 'item_id' => 'F1']);
        self::assertSame(303, $r->status);
        self::assertNull($this->einstellungen->hole('onedrive_ziel'));
    }

    public function testUmzugLegtAuftraegeAnUndVerarbeitet(): void
    {
        $this->verbinde();
        $a = $this->dokument();
        $b = $this->dokument();
        $this->http->antwort(201, ['id' => 'I1', 'name' => 'Anleitung.pdf']);
        $this->http->antwort(201, ['id' => 'I2', 'name' => 'Anleitung (1).pdf']);
        $r = $this->post('/admin/onedrive/umzug');
        self::assertSame(303, $r->status);
        self::assertContains('2 Dateien zum Umzug vorgemerkt.', $this->meldungen());
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'hochladen'")->fetchColumn());
        self::assertSame('onedrive', $this->dok($a)['ablage']);
    }

    public function testUebertragenVerarbeitetWarteschlange(): void
    {
        $this->verbinde();
        $dok = $this->dokument();
        $this->pdo->prepare("INSERT INTO onedrive_auftrag (art, dokument_id, naechster_versuch) VALUES ('hochladen', ?, NOW())")->execute([$dok]);
        $this->http->antwort(201, ['id' => 'I1', 'name' => 'Anleitung.pdf']);
        $r = $this->post('/admin/onedrive/uebertragen');
        self::assertSame(303, $r->status);
        self::assertSame('onedrive', $this->dok($dok)['ablage']);
        self::assertStringContainsString('1', implode(' ', $this->meldungen()));
    }

    public function testUebertragenOhneVerbindungMeldetStattAbzustuerzen(): void
    {
        $r = $this->post('/admin/onedrive/uebertragen');
        self::assertSame(303, $r->status);
        self::assertNotEmpty($this->meldungen());
    }

    public function testTrennenLoeschtTokenUndProtokolliert(): void
    {
        $this->verbinde();
        $this->einstellungen->setze('onedrive_konto', 'verein@outlook.de');
        $r = $this->post('/admin/onedrive/trennen');
        self::assertSame(303, $r->status);
        self::assertSame('getrennt', $this->tokens->status());
        self::assertNull($this->tokens->lade());
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'onedrive' AND aenderungen LIKE '%getrennt%'")->fetchColumn();
        self::assertSame(1, $n);
    }

    public function testHinweisleisteBeiAbgelaufenerVerbindung(): void
    {
        $this->verbinde();
        $this->tokens->setzeStatus('abgelaufen');
        $r = $this->get('/fahrzeuge');
        self::assertStringContainsString('Die OneDrive-Verbindung ist abgelaufen. Neue Dateien werden lokal gesammelt – bitte unter Verwaltung › OneDrive neu verbinden.', $r->body);
    }

    public function testKeineHinweisleisteBeiVerbundenOderOhneKonfiguration(): void
    {
        $this->verbinde();
        self::assertStringNotContainsString('OneDrive-Verbindung ist abgelaufen', $this->get('/fahrzeuge')->body);
        $this->tokens->setzeStatus('abgelaufen');
        self::assertStringNotContainsString('OneDrive-Verbindung ist abgelaufen', $this->get('/fahrzeuge', [], false)->body);
    }

    public function testHinweisleisteNurFuerAdmins(): void
    {
        $this->verbinde();
        $this->tokens->setzeStatus('abgelaufen');
        $this->alsRolle('mitglied');
        self::assertStringNotContainsString('OneDrive-Verbindung ist abgelaufen', $this->get('/fahrzeuge')->body);
    }

    public function testNavigationZeigtOneDriveNurFuerAdmins(): void
    {
        self::assertStringContainsString('/admin/onedrive', $this->get('/fahrzeuge')->body);
        $this->alsRolle('bearbeiter');
        self::assertStringNotContainsString('/admin/onedrive', $this->get('/fahrzeuge')->body);
    }
}
