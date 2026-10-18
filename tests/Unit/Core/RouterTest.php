<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Core;

use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function test_platzhalter_werden_uebergeben(): void
    {
        $router = new Router();
        $router->add('GET', '/fahrzeuge/{id}', fn(Request $r, array $p) => new Response('id=' . $p['id']));
        $antwort = $router->dispatch(new Request('GET', '/fahrzeuge/42'));
        $this->assertSame(200, $antwort->status);
        $this->assertSame('id=42', $antwort->body);
    }

    public function test_unbekannter_pfad_liefert_404(): void
    {
        $router = new Router();
        $router->add('GET', '/fahrzeuge', fn() => new Response('x'));
        $this->assertSame(404, $router->dispatch(new Request('GET', '/gibtsnicht'))->status);
    }

    public function test_falsche_methode_liefert_405(): void
    {
        $router = new Router();
        $router->add('GET', '/fahrzeuge/{id}', fn() => new Response('x'));
        $this->assertSame(405, $router->dispatch(new Request('POST', '/fahrzeuge/1'))->status);
    }

    public function test_abschliessender_schraegstrich_wird_ignoriert(): void
    {
        $router = new Router();
        $router->add('GET', '/fahrzeuge', fn() => new Response('liste'));
        $antwort = $router->dispatch(new Request('GET', '/fahrzeuge/'));
        $this->assertSame(200, $antwort->status);
        $this->assertSame('liste', $antwort->body);
    }

    public function test_platzhalter_matcht_keinen_schraegstrich(): void
    {
        $router = new Router();
        $router->add('GET', '/fahrzeuge/{id}', fn() => new Response('x'));
        $this->assertSame(404, $router->dispatch(new Request('GET', '/fahrzeuge/1/2'))->status);
    }
}
