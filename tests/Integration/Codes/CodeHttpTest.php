<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Codes;

use Emc\Auth\Auth;
use Emc\Core\App;
use Emc\Core\Instanz;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

/** Feld „Code“ in der Kopfzeile und Kurzlink /c/<Code>. */
final class CodeHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private int $h0;
    private int $mitglied;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->mitglied = $this->legeBenutzerAn('mitglied@verein.de', 'geheim-passwort-1', 'mitglied');
    }

    private function anmelden(): void
    {
        $this->sitzungAnmelden($this->sitzung, $this->mitglied);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    private function get(string $pfad, array $query = [], ?array $config = null): Response
    {
        return App::erzeuge($config ?? self::testConfig(), new Session($this->sitzung))->handle(new Request('GET', $pfad, $query));
    }

    private function fahrzeug(string $nummer, string $bezeichnung, string $status = 'einsatzbereit'): int
    {
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, status) VALUES (?, ?, ?, ?)')
            ->execute([$nummer, $bezeichnung, $this->h0, $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function decoder(int $fahrzeug, int $adresse): void
    {
        $this->pdo->prepare("INSERT INTO decoder (fahrzeug_id, bezeichnung, protokoll, adresse) VALUES (?, 'Decoder', 'dcc', ?)")
            ->execute([$fahrzeug, $adresse]);
    }

    private function anlage(int $nummer, string $name): int
    {
        $this->pdo->prepare('INSERT INTO anlage (nummer, name, spurweite_id) VALUES (?, ?, ?)')->execute([$nummer, $name, $this->h0]);
        return (int) $this->pdo->lastInsertId();
    }

    public function test_code_oeffnet_fahrzeug_direkt(): void
    {
        $fz = $this->fahrzeug('EMC-0012', 'V 200');
        $this->anmelden();
        $r = $this->get('/code', ['q' => 'f12']);
        $this->assertSame(303, $r->status);
        $this->assertSame('/fahrzeuge/' . $fz, $r->headers['Location']);
    }

    public function test_leeres_feld_fuehrt_zur_startseite(): void
    {
        $this->anmelden();
        $r = $this->get('/code', ['q' => '  ']);
        $this->assertSame(303, $r->status);
        $this->assertSame('/', $r->headers['Location']);
        $r = $this->get('/code', ['q' => ['F12']]); // kein Text
        $this->assertSame('/', $r->headers['Location']);
    }

    public function test_adresse_mit_mehreren_treffern_zeigt_auswahl(): void
    {
        $a = $this->fahrzeug('EMC-0001', 'Fleißige V 100');
        $b = $this->fahrzeug('EMC-0002', 'Alte Dampflok', 'ausgemustert');
        $this->decoder($a, 3);
        $this->decoder($b, 3);
        $this->anmelden();
        $r = $this->get('/code', ['q' => '3']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Mehrere Fahrzeuge mit Adresse 3', $r->body);
        $this->assertStringContainsString('Fleißige V 100', $r->body);
        $this->assertStringContainsString('Alte Dampflok', $r->body);
        $this->assertStringContainsString('Abstellgleis', $r->body);
        $this->assertStringContainsString('F1', $r->body);
        $this->assertStringContainsString('href="/fahrzeuge/' . $b . '"', $r->body);
        $this->assertSame(1, substr_count($r->body, '>Abstellgleis</span>'));
    }

    public function test_unbekannter_code_zeigt_meldung(): void
    {
        $this->anmelden();
        $r = $this->get('/code', ['q' => 'F99']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Kein Fahrzeug und keine Anlage mit diesem Code.', $r->body);
        $this->assertStringContainsString('name="q"', $r->body);
    }

    public function test_textsuche_zeigt_fahrzeuge_und_anlagen(): void
    {
        $this->fahrzeug('ALT-7', 'Museumslok');
        $anl = $this->anlage(4, 'Museumsanlage');
        $this->anmelden();
        $r = $this->get('/code', ['q' => 'Museum']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Museumslok', $r->body);
        $this->assertStringContainsString('ALT-7', $r->body);
        $this->assertStringContainsString('Museumsanlage', $r->body);
        $this->assertStringContainsString('A4', $r->body);
        $this->assertStringContainsString('href="/anlagen/' . $anl . '"', $r->body);
        $this->assertStringContainsString('value="Museum"', $r->body);
    }

    public function test_kurzlink_ohne_anmeldung_ueber_login_zur_anlage(): void
    {
        $anl = $this->anlage(3, 'Clubanlage H0');
        $r = $this->get('/c/A3');
        $this->assertSame(303, $r->status);
        $this->assertSame('/login?ziel=%2Fc%2FA3', $r->headers['Location']);

        $this->sitzung['_csrf'] = str_repeat('a', 64);
        $r = App::erzeuge(self::testConfig(), new Session($this->sitzung))->handle(new Request('POST', '/login', [], [
            'email' => 'mitglied@verein.de', 'passwort' => 'geheim-passwort-1', 'ziel' => '/c/A3', '_csrf' => str_repeat('a', 64),
        ]));
        $this->assertSame(303, $r->status);
        $this->assertSame('/c/A3', $r->headers['Location']);

        $r = $this->get('/c/A3');
        $this->assertSame(303, $r->status);
        $this->assertSame('/anlagen/' . $anl, $r->headers['Location']);
    }

    public function test_kurzlink_angemeldet_fahrzeug(): void
    {
        $fz = $this->fahrzeug('EMC-0012', 'V 200');
        $this->anmelden();
        $r = $this->get('/c/f12');
        $this->assertSame(303, $r->status);
        $this->assertSame('/fahrzeuge/' . $fz, $r->headers['Location']);
    }

    public function test_seltsame_kurzlinks_bleiben_in_der_app(): void
    {
        $this->fahrzeug('EMC-0012', 'V 200');
        $config = self::testConfig() + ['basis_url' => '/emc'];
        $pruefe = function (array $config, string $basis): void {
            // Pfade, wie der Router sie bekommt: noch kodiert und bereits dekodiert (Request::fromGlobals).
            foreach (['/c/%2F%2Fbeispiel.de', '/c///beispiel.de', '/c/F12%0A', "/c/F12\n", '/c/%5C%5Cbeispiel.de', '/c/https:%2F%2Fbeispiel.de'] as $pfad) {
                $r = $this->get($pfad, [], $config);
                $this->assertContains($r->status, [200, 303, 404], $pfad);
                if ($r->status === 303) {
                    $this->assertStringStartsWith($basis . '/', $r->headers['Location'], $pfad);
                    $this->assertStringStartsNotWith($basis . '//', $r->headers['Location'], $pfad);
                    $this->assertStringNotContainsString("\n", $r->headers['Location'], $pfad);
                }
                if ($r->status === 200) {
                    $this->assertStringContainsString('name="q"', $r->body, $pfad);
                }
            }
        };
        $this->anmelden();
        $pruefe(self::testConfig(), '');
        $this->sitzung[Auth::INSTANZ_KEY] = Instanz::fingerabdruck($config);
        $pruefe($config, '/emc');
        // Ohne Anmeldung: Login mit internem Ziel, nie eine fremde Adresse.
        $this->sitzung = [];
        foreach (['/c/%2F%2Fbeispiel.de', '/c/F12%0A'] as $pfad) {
            $r = $this->get($pfad);
            $this->assertSame(303, $r->status);
            $this->assertStringStartsWith('/login', $r->headers['Location']);
        }
    }

    public function test_jede_seite_hat_das_codefeld(): void
    {
        $this->anmelden();
        foreach (['/', '/fahrzeuge', '/anlagen'] as $pfad) {
            $body = $this->get($pfad)->body;
            $this->assertStringContainsString('name="q"', $body, $pfad);
            $this->assertStringContainsString('action="/code"', $body, $pfad);
            $this->assertStringContainsString('role="search"', $body, $pfad);
            $this->assertStringContainsString('placeholder="Code, z. B. F12"', $body, $pfad);
        }
    }

    public function test_fahrzeugseite_zeigt_code(): void
    {
        $fz = $this->fahrzeug('EMC-0012', 'V 200');
        $alt = $this->fahrzeug('ALT-7', 'Museumslok');
        $this->anmelden();
        $body = $this->get('/fahrzeuge/' . $fz)->body;
        $this->assertMatchesRegularExpression('/class="[^"]*fahrzeug-code[^"]*"[^>]*>F12</', $body);
        $body = $this->get('/fahrzeuge/' . $alt)->body;
        $this->assertStringNotContainsString('fahrzeug-code', $body);
    }
}
