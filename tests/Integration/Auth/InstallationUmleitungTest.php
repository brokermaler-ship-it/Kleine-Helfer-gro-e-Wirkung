<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Auth;

use Emc\Betrieb\Migrator;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

final class InstallationUmleitungTest extends DbTestCase
{
    protected function tearDown(): void
    {
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->ausfuehren();
    }

    public function test_ohne_schema_wird_zu_install_umgeleitet_und_install_laeuft(): void
    {
        self::alleTabellenLoeschen($this->pdo);
        $sitzung = [];
        $app = App::erzeuge(self::testConfig(), new Session($sitzung));

        $a = $app->handle(new Request('GET', '/fahrzeuge'));
        $this->assertSame(303, $a->status);
        $this->assertSame('/install', $a->headers['Location']);

        $this->assertSame(200, $app->handle(new Request('GET', '/install'))->status);

        $sitzung['_csrf'] = str_repeat('b', 64);
        $p = $app->handle(new Request('POST', '/install', [], [
            'vorname' => 'Erika', 'nachname' => 'Muster', 'email' => 'erika@verein.de',
            'passwort' => 'ein-langes-passwort', '_csrf' => $sitzung['_csrf'],
        ]));
        $this->assertSame('/login', $p->headers['Location']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM benutzer')->fetchColumn());
    }
}
