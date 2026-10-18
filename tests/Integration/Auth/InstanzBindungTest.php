<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Auth;

use Emc\Auth\AnmeldeErgebnis;
use Emc\Auth\Auth;
use Emc\Core\App;
use Emc\Core\Instanz;
use Emc\Core\Request;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

/** Eine Anmeldung gilt nur für die Instanz (Datenbank + basis_url), an der sie erfolgt ist. */
final class InstanzBindungTest extends DbTestCase
{
    private array $sitzung = [];

    private function app(): App
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung));
    }

    public function test_anmeldung_speichert_fingerabdruck_der_instanz(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $this->assertSame(AnmeldeErgebnis::Erfolg, $this->app()->container()->get(Auth::class)->anmelden('max@verein.de', 'geheim-passwort-1', '127.0.0.1'));
        $this->assertSame(Instanz::fingerabdruck(self::testConfig()), $this->sitzung[Auth::INSTANZ_KEY]);
        $this->assertSame(200, $this->app()->handle(new Request('GET', '/fahrzeuge'))->status);
    }

    public function test_sitzung_einer_anderen_instanz_wird_abgelehnt_und_geleert(): void
    {
        $id = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        // Gleiche Benutzer-ID, aber in der Testinstanz (andere Datenbank) angemeldet.
        $andere = ['db_dsn' => 'mysql:host=localhost;dbname=emc78_test;charset=utf8mb4', 'basis_url' => ''];
        $this->sitzung = ['benutzer_id' => $id, Auth::INSTANZ_KEY => Instanz::fingerabdruck($andere), 'fremd' => 'x'];

        $r = $this->app()->handle(new Request('GET', '/admin/sicherung'));
        $this->assertSame(303, $r->status);
        $this->assertSame('/login?ziel=%2Fadmin%2Fsicherung', $r->headers['Location']);
        $this->assertArrayNotHasKey('benutzer_id', $this->sitzung);
        $this->assertArrayNotHasKey('fremd', $this->sitzung);
    }

    public function test_sitzung_ohne_fingerabdruck_wird_abgelehnt(): void
    {
        $this->sitzung = ['benutzer_id' => $this->legeBenutzerAn('max@verein.de')];
        $r = $this->app()->handle(new Request('GET', '/fahrzeuge'));
        $this->assertSame(303, $r->status);
        $this->assertArrayNotHasKey('benutzer_id', $this->sitzung);
    }

    public function test_gleiche_instanz_bleibt_angemeldet(): void
    {
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn('max@verein.de'));
        $this->assertSame(200, $this->app()->handle(new Request('GET', '/fahrzeuge'))->status);
    }

    public function test_abmelden_leert_die_ganze_sitzung(): void
    {
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn('max@verein.de'));
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        $this->sitzung['fahrzeugfilter'] = ['status' => 'defekt'];

        $r = $this->app()->handle(new Request('POST', '/logout', [], ['_csrf' => str_repeat('a', 64)]));
        $this->assertSame(303, $r->status);
        $this->assertSame(['_meldungen'], array_keys($this->sitzung));
        $this->assertSame('Du wurdest abgemeldet.', $this->sitzung['_meldungen'][0]['text']);
    }
}
