<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Core;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private function app(bool $debug = false): App
    {
        $app = App::erzeuge(['app_name' => 'Test', 'debug' => $debug]);
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
        $antwort = $this->app()->handle(new Request('POST', '/kaputt'));
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
}
