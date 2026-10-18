<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Auth;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

final class HttpZugriffTest extends DbTestCase
{
    private array $sitzung = [];

    private function app(): App
    {
        $app = App::erzeuge(self::testConfig(), new Session($this->sitzung));
        $router = $app->container()->get('router');
        $router->add('GET', '/fahrzeuge', fn() => new Response('Liste'));
        $router->add('GET', '/admin', $app->erfordert('benutzer_verwalten', fn() => new Response('Admin')));
        $router->add('GET', '/konto/passwort', fn() => new Response('Passwortseite'));
        return $app;
    }

    private function anmelden(string $rolle = 'mitglied', bool $mussAendern = false): void
    {
        $id = $this->legeBenutzerAn('max@verein.de', 'geheim-passwort-1', $rolle, true, $mussAendern);
        $this->sitzung['benutzer_id'] = $id;
    }

    private function csrf(): string
    {
        return $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    public function test_nicht_angemeldet_wird_zum_login_umgeleitet(): void
    {
        $a = $this->app()->handle(new Request('GET', '/fahrzeuge'));
        $this->assertSame(303, $a->status);
        $this->assertSame('/login?ziel=%2Ffahrzeuge', $a->headers['Location']);
    }

    public function test_angemeldet_sieht_seite(): void
    {
        $this->anmelden();
        $a = $this->app()->handle(new Request('GET', '/fahrzeuge'));
        $this->assertSame(200, $a->status);
        $this->assertSame('Liste', $a->body);
    }

    public function test_login_seite_ist_oeffentlich_und_hat_csrf_feld(): void
    {
        $a = $this->app()->handle(new Request('GET', '/login'));
        $this->assertSame(200, $a->status);
        $this->assertStringContainsString('name="_csrf"', $a->body);
        $this->assertStringContainsString('Anmelden', $a->body);
    }

    public function test_post_ohne_csrf_liefert_419(): void
    {
        $this->anmelden();
        $a = $this->app()->handle(new Request('POST', '/logout'));
        $this->assertSame(419, $a->status);
        $this->assertSame(419, $this->app()->handle(new Request('POST', '/logout', [], ['_csrf' => 'falsch']))->status);
    }

    public function test_csrf_token_nur_im_query_string_wird_abgelehnt(): void
    {
        $this->anmelden();
        $t = $this->csrf();
        $a = $this->app()->handle(new Request('POST', '/logout', ['_csrf' => $t]));
        $this->assertSame(419, $a->status);
    }

    public function test_login_post_ohne_csrf_liefert_419(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $a = $this->app()->handle(new Request('POST', '/login', [], ['email' => 'max@verein.de', 'passwort' => 'geheim-passwort-1']));
        $this->assertSame(419, $a->status);
        $this->assertArrayNotHasKey('benutzer_id', $this->sitzung);
    }

    public function test_logout_mit_csrf(): void
    {
        $this->anmelden();
        $a = $this->app()->handle(new Request('POST', '/logout', [], ['_csrf' => $this->csrf()]));
        $this->assertSame(303, $a->status);
        $this->assertSame('/login', $a->headers['Location']);
        $this->assertArrayNotHasKey('benutzer_id', $this->sitzung);
    }

    public function test_login_erfolgreich_leitet_auf_ziel(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $a = $this->app()->handle(new Request('POST', '/login', [], [
            'email' => 'max@verein.de', 'passwort' => 'geheim-passwort-1', 'ziel' => '/fahrzeuge', '_csrf' => $this->csrf(),
        ]));
        $this->assertSame(303, $a->status);
        $this->assertSame('/fahrzeuge', $a->headers['Location']);
        $this->assertArrayHasKey('benutzer_id', $this->sitzung);
    }

    /** @dataProvider unsichereZiele */
    public function test_ziel_wird_gegen_open_redirect_geschuetzt(string $ziel): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $a = $this->app()->handle(new Request('POST', '/login', [], [
            'email' => 'max@verein.de', 'passwort' => 'geheim-passwort-1', 'ziel' => $ziel, '_csrf' => $this->csrf(),
        ]));
        $this->assertSame('/', $a->headers['Location']);
    }

    /** @return array<string,array{string}> */
    public static function unsichereZiele(): array
    {
        return [
            'absolut' => ['https://evil.example/'],
            'protokollrelativ' => ['//evil.example/'],
            'backslash' => ['/\\evil.example'],
            'ohne slash' => ['evil'],
            'zeilenumbruch' => ["/a\r\nSet-Cookie: x=1"],
        ];
    }

    public function test_login_falsch_zeigt_neutrale_meldung(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $a = $this->app()->handle(new Request('POST', '/login', [], [
            'email' => 'max@verein.de', 'passwort' => 'falsch', '_csrf' => $this->csrf(),
        ]));
        $this->assertSame(200, $a->status);
        $this->assertStringContainsString('E-Mail oder Passwort ist falsch.', $a->body);
        $b = $this->app()->handle(new Request('POST', '/login', [], [
            'email' => 'unbekannt@verein.de', 'passwort' => 'falsch', '_csrf' => $this->csrf(),
        ]));
        $this->assertStringContainsString('E-Mail oder Passwort ist falsch.', $b->body);
    }

    public function test_login_gesperrt_und_inaktiv_meldungen(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        for ($i = 0; $i < 5; $i++) {
            $this->app()->handle(new Request('POST', '/login', [], ['email' => 'max@verein.de', 'passwort' => 'x', '_csrf' => $this->csrf()]));
        }
        $a = $this->app()->handle(new Request('POST', '/login', [], ['email' => 'max@verein.de', 'passwort' => 'geheim-passwort-1', '_csrf' => $this->csrf()]));
        $this->assertStringContainsString('Zu viele Fehlversuche. Bitte warte 15 Minuten.', $a->body);

        $this->legeBenutzerAn('weg@verein.de', 'geheim-passwort-1', 'mitglied', false);
        $b = $this->app()->handle(new Request('POST', '/login', [], ['email' => 'weg@verein.de', 'passwort' => 'geheim-passwort-1', '_csrf' => $this->csrf()]));
        $this->assertStringContainsString('Dein Zugang ist deaktiviert.', $b->body);
    }

    public function test_erfordert_liefert_403(): void
    {
        $this->anmelden('bearbeiter');
        $this->assertSame(403, $this->app()->handle(new Request('GET', '/admin'))->status);
    }

    public function test_erfordert_laesst_berechtigte_durch(): void
    {
        $this->anmelden('admin');
        $this->assertSame('Admin', $this->app()->handle(new Request('GET', '/admin'))->body);
    }

    public function test_passwortaenderung_erzwingen(): void
    {
        $this->anmelden('mitglied', true);
        $a = $this->app()->handle(new Request('GET', '/fahrzeuge'));
        $this->assertSame(303, $a->status);
        $this->assertSame('/konto/passwort', $a->headers['Location']);
        $this->assertSame(200, $this->app()->handle(new Request('GET', '/konto/passwort'))->status);
        $out = $this->app()->handle(new Request('POST', '/logout', [], ['_csrf' => $this->csrf()]));
        $this->assertSame('/login', $out->headers['Location']);
    }

    public function test_navigation_zeigt_menues_nach_recht(): void
    {
        $this->anmelden('mitglied');
        $body = $this->app()->handle(new Request('GET', '/fahrzeuge'))->body;
        $this->assertSame('Liste', $body); // eigene Antwort ohne Layout
        $seite = $this->app()->handle(new Request('GET', '/gibtsnicht'));
        $this->assertStringContainsString('Fahrzeuge', $seite->body);
        $this->assertStringContainsString('Abmelden', $seite->body);
        $this->assertStringNotContainsString('Verwaltung', $seite->body);
        $this->assertStringNotContainsString('>Personen<', $seite->body);
    }

    public function test_navigation_fuer_admin(): void
    {
        $this->anmelden('admin');
        $seite = $this->app()->handle(new Request('GET', '/gibtsnicht'));
        $this->assertStringContainsString('Verwaltung', $seite->body);
        $this->assertStringContainsString('>Personen<', $seite->body);
        $this->assertStringContainsString('Spurweiten', $seite->body);
    }

    public function test_install_nur_ohne_benutzer(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $this->assertSame(404, $this->app()->handle(new Request('GET', '/install'))->status);
        $this->assertSame(404, $this->app()->handle(new Request('POST', '/install', [], ['_csrf' => $this->csrf()]))->status);
    }

    public function test_install_zeigt_formular_ohne_benutzer(): void
    {
        $a = $this->app()->handle(new Request('GET', '/install'));
        $this->assertSame(200, $a->status);
        $this->assertStringContainsString('name="passwort"', $a->body);
    }

    public function test_install_legt_admin_an(): void
    {
        $a = $this->app()->handle(new Request('POST', '/install', [], [
            'vorname' => 'Erika', 'nachname' => 'Muster', 'email' => 'Erika@Verein.de',
            'passwort' => 'ein-langes-passwort', '_csrf' => $this->csrf(),
        ]));
        $this->assertSame(303, $a->status);
        $this->assertSame('/login', $a->headers['Location']);
        $row = $this->pdo->query('SELECT email, rolle, aktiv, passwort_hash FROM benutzer')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('erika@verein.de', $row['email']);
        $this->assertSame('admin', $row['rolle']);
        $this->assertSame(1, (int) $row['aktiv']);
        $this->assertTrue(password_verify('ein-langes-passwort', $row['passwort_hash']));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM person')->fetchColumn());
    }

    public function test_install_validiert(): void
    {
        $a = $this->app()->handle(new Request('POST', '/install', [], [
            'vorname' => '', 'nachname' => 'Muster', 'email' => 'kaputt',
            'passwort' => 'kurz', '_csrf' => $this->csrf(),
        ]));
        $this->assertSame(422, $a->status);
        $this->assertStringContainsString('mindestens 10 Zeichen', $a->body);
        $this->assertStringContainsString('gültige E-Mail', $a->body);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM benutzer')->fetchColumn());
    }
}
