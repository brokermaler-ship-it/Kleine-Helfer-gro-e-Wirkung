<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Core;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

/** Läuft gegen die Testdatenbank, da die Middleware Schema und Anmeldung prüft. */
final class AppTest extends DbTestCase
{
    private array $sitzung = [];

    private function app(bool $debug = false): App
    {
        $this->sitzung['benutzer_id'] = $this->legeBenutzerAn('max@verein.de');
        $app = App::erzeuge(['debug' => $debug] + self::testConfig(), new Session($this->sitzung));
        $router = $app->container()->get('router');
        $router->add('GET', '/eigen404', fn() => new Response('Seite nicht gefunden.', 404));
        $router->add('GET', '/kaputt', function () {
            throw new \RuntimeException('geheime Details');
        });
        return $app;
    }

    public function test_unbekannte_route_zeigt_404_seite(): void
    {
        $antwort = $this->app()->handle(new Request('GET', '/gibtsnicht'));
        $this->assertSame(404, $antwort->status);
        $this->assertStringContainsString('<h1 class="h3">Nicht gefunden</h1>', $antwort->body);
        $this->assertStringContainsString('<nav', $antwort->body);
    }

    public function test_falsche_methode_zeigt_405_seite_mit_allow(): void
    {
        $antwort = $this->app()->handle(new Request('POST', '/kaputt', [], ['_csrf' => $this->sitzung['_csrf'] = str_repeat('c', 64)]));
        $this->assertSame(405, $antwort->status);
        $this->assertSame('GET', $antwort->headers['Allow']);
        $this->assertStringContainsString('Methode nicht erlaubt', $antwort->body);
    }

    public function test_eigene_404_antwort_wird_nicht_ersetzt(): void
    {
        $antwort = $this->app()->handle(new Request('GET', '/eigen404'));
        $this->assertSame(404, $antwort->status);
        $this->assertSame('Seite nicht gefunden.', $antwort->body);
    }

    public function test_ausnahme_liefert_500_ohne_meldung_ohne_debug(): void
    {
        $prev = ini_set('error_log', '/dev/null');
        try {
            $antwort = $this->app(false)->handle(new Request('GET', '/kaputt'));
        } finally {
            ini_set('error_log', (string) $prev);
        }
        $this->assertSame(500, $antwort->status);
        $this->assertStringContainsString('Es ist ein technischer Fehler aufgetreten.', $antwort->body);
        $this->assertStringNotContainsString('geheime Details', $antwort->body);
    }

    public function test_ausnahme_zeigt_meldung_mit_debug(): void
    {
        $prev = ini_set('error_log', '/dev/null');
        try {
            $antwort = $this->app(true)->handle(new Request('GET', '/kaputt'));
        } finally {
            ini_set('error_log', (string) $prev);
        }
        $this->assertSame(500, $antwort->status);
        $this->assertStringContainsString('geheime Details', $antwort->body);
    }

    public function test_datenbankdienste_sind_registriert_und_verbinden_erst_bei_bedarf(): void
    {
        $sitzung = [];
        $app = App::erzeuge(['db_dsn' => 'mysql:host=127.0.0.1;port=1;dbname=gibtsnicht', 'db_benutzer' => 'x', 'db_passwort' => 'y'], new Session($sitzung));
        $this->assertInstanceOf(App::class, $app);
        $this->expectException(\PDOException::class);
        $app->container()->get(\Emc\Betrieb\Migrator::class);
    }

    public function test_ohne_erreichbare_datenbank_liefert_die_app_eine_500_seite(): void
    {
        $sitzung = [];
        $app = App::erzeuge(['db_dsn' => 'mysql:host=127.0.0.1;port=1;dbname=gibtsnicht', 'db_benutzer' => 'x', 'db_passwort' => 'y'], new Session($sitzung));
        $prev = ini_set('error_log', '/dev/null');
        try {
            $antwort = $app->handle(new Request('GET', '/login'));
        } finally {
            ini_set('error_log', (string) $prev);
        }
        $this->assertSame(500, $antwort->status);
    }
}
