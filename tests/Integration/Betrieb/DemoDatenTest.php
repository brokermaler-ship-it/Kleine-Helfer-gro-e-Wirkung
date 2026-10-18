<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Betrieb;

use Emc\Auth\AnmeldeErgebnis;
use Emc\Auth\Auth;
use Emc\Betrieb\DemoDaten;
use Emc\Core\App;
use Emc\Core\DomainFehler;
use Emc\Core\Request;
use Emc\Core\Session;
use Emc\Digital\Adresspruefung;
use Emc\Tests\Integration\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DemoDatenTest extends DbTestCase
{
    private string $speicher;
    private array $sitzung = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->speicher = sys_get_temp_dir() . '/emc_demo_' . bin2hex(random_bytes(6));
        mkdir($this->speicher . '/dateien', 0750, true);
    }

    protected function tearDown(): void
    {
        $this->loescheOrdner($this->speicher);
    }

    private function loescheOrdner(string $pfad): void
    {
        if (!is_dir($pfad)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($pfad);
    }

    private const ADMIN_PASSWORT = 'Admin-Geheim-2026';

    /** @param array<string,mixed> $extra */
    private function app(mixed $test = true, array $extra = []): App
    {
        return App::erzeuge(
            $extra + ['testumgebung' => $test, 'testumgebung_admin_passwort' => self::ADMIN_PASSWORT, 'speicher_pfad' => $this->speicher] + self::testConfig(),
            new Session($this->sitzung)
        );
    }

    /** @return list<\SplFileInfo> Originaldateien (ohne .anzeige.jpg/.vorschau.jpg und .gitkeep) */
    private function originalDateien(): array
    {
        $liste = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->speicher . '/dateien', \FilesystemIterator::SKIP_DOTS));
        return array_values(array_filter(iterator_to_array($liste, false), fn(\SplFileInfo $f) => $f->isFile() && !str_contains($f->getFilename(), '.')));
    }

    private function zaehle(string $tabelle): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM `$tabelle`")->fetchColumn();
    }

    public function test_bricht_ausserhalb_testumgebung_ab(): void
    {
        $this->expectException(DomainFehler::class);
        $this->expectExceptionMessage('Beispieldaten nur in der Testumgebung');
        (new DemoDaten($this->app(false)))->erzeuge(false);
    }

    /** @return array<string,array{mixed}> */
    public static function keinEchtesTrue(): array
    {
        return ['String false' => ['false'], 'String true' => ['true'], 'Zahl 1' => [1]];
    }

    #[DataProvider('keinEchtesTrue')]
    public function test_nur_echtes_true_schaltet_testumgebung_ein(mixed $wert): void
    {
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn('a@verein.de', 'geheim-passwort-1', 'admin'));
        $this->assertSame(404, $this->app($wert)->handle(new Request('GET', '/admin/testdaten'))->status);
        $this->assertSame(404, $this->app($wert)->handle(new Request('POST', '/admin/testdaten', [], ['_csrf' => $this->sitzung['_csrf'] = str_repeat('a', 64)]))->status);
        $this->assertStringNotContainsString('Testumgebung', $this->app($wert)->handle(new Request('GET', '/fahrzeuge'))->body);
        try {
            (new DemoDaten($this->app($wert)))->erzeuge(true);
            $this->fail('DemoDaten darf nicht laufen');
        } catch (DomainFehler $f) {
            $this->assertSame('Beispieldaten nur in der Testumgebung', $f->getMessage());
        }
        $this->assertSame(1, $this->zaehle('benutzer'), 'nichts gelöscht');
    }

    /** @return array<string,array{mixed}> */
    public static function ungueltigeAdminPasswoerter(): array
    {
        return ['fehlt' => [null], 'leer' => [''], 'kein String' => [12345678901], 'zu kurz' => ['kurz']];
    }

    #[DataProvider('ungueltigeAdminPasswoerter')]
    public function test_ohne_admin_passwort_in_config_wird_abgebrochen(mixed $passwort): void
    {
        $this->legeBenutzerAn('x@verein.de');
        $extra = $passwort === null ? [] : ['testumgebung_admin_passwort' => $passwort];
        $config = $extra + ['testumgebung' => true, 'speicher_pfad' => $this->speicher] + self::testConfig();
        try {
            (new DemoDaten(App::erzeuge($config, new Session($this->sitzung))))->erzeuge(true);
            $this->fail('DemoDaten darf ohne Admin-Passwort nicht laufen');
        } catch (DomainFehler $f) {
            $this->assertStringContainsString("'testumgebung_admin_passwort'", $f->getMessage());
            $this->assertStringContainsString('mindestens 10 Zeichen', $f->getMessage());
        }
        $this->assertSame(1, $this->zaehle('benutzer'), 'vorhandene Daten bleiben unangetastet');
    }

    public function test_admin_passwort_kommt_aus_der_config(): void
    {
        (new DemoDaten($this->app()))->erzeuge(true);
        $auth = fn() => $this->app()->container()->get(Auth::class);
        $this->assertSame(AnmeldeErgebnis::Falsch, $auth()->anmelden('admin@test.emc78.de', 'Test-EMC78!', '127.0.0.1'));
        $this->assertSame(AnmeldeErgebnis::Erfolg, $auth()->anmelden('admin@test.emc78.de', self::ADMIN_PASSWORT, '127.0.0.1'));
        $this->assertSame(AnmeldeErgebnis::Erfolg, $auth()->anmelden('bearbeiter@test.emc78.de', 'Test-EMC78!', '127.0.0.1'));
    }

    public function test_login_zeigt_nur_mitglied_und_bearbeiter_zugang(): void
    {
        $this->legeBenutzerAn('x@verein.de');
        $body = $this->app()->handle(new Request('GET', '/login'))->body;
        $this->assertStringContainsString('mitglied@test.emc78.de', $body);
        $this->assertStringContainsString('bearbeiter@test.emc78.de', $body);
        $this->assertStringContainsString('Test-EMC78!', $body);
        $this->assertStringContainsString('Admin-Zugang: beim Vorstand erfragen', $body);
        $this->assertStringNotContainsString('admin@test.emc78.de', $body);
        $this->assertStringNotContainsString(self::ADMIN_PASSWORT, $body);
    }

    public function test_legt_beispieldaten_mit_doppelter_adresse_an(): void
    {
        $app = $this->app();
        (new DemoDaten($app))->erzeuge(true);

        $this->assertSame(12, $this->zaehle('fahrzeug'));
        $this->assertSame(6, $this->zaehle('person'));
        $this->assertSame(3, $this->zaehle('benutzer'));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM fahrzeug WHERE status = 'ausgemustert'")->fetchColumn());
        $this->assertGreaterThan(0, $this->zaehle('funktion'));
        $this->assertGreaterThan(0, $this->zaehle('funktionsvorlage'));
        $this->assertGreaterThan(0, $this->zaehle('cv_wert'));
        $this->assertGreaterThan(0, $this->zaehle('dokument'));
        $this->assertCount($this->zaehle('dokument'), $this->originalDateien());
        $this->assertGreaterThan(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug WHERE titelbild_dokument_id IS NOT NULL')->fetchColumn());

        $h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $n = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'N'")->fetchColumn();
        $pruefung = $app->container()->get(Adresspruefung::class);
        $doppelt = array_filter($pruefung->uebersicht($h0), fn(array $z) => $z['doppelt']);
        $this->assertCount(2, $doppelt, 'genau eine doppelte Adresse (zwei Fahrzeuge) in H0');
        $this->assertCount(1, array_unique(array_map(fn(array $z) => $z['protokoll']->value . ':' . $z['adresse'], $doppelt)));
        $this->assertSame([], array_filter($pruefung->uebersicht($n), fn(array $z) => $z['doppelt']));

        $this->assertSame(AnmeldeErgebnis::Erfolg, $app->container()->get(Auth::class)->anmelden('mitglied@test.emc78.de', 'Test-EMC78!', '127.0.0.1'));
        $this->assertSame('admin', (string) $this->pdo->query("SELECT rolle FROM benutzer WHERE email = 'admin@test.emc78.de'")->fetchColumn());
        $this->assertSame('bearbeiter', (string) $this->pdo->query("SELECT rolle FROM benutzer WHERE email = 'bearbeiter@test.emc78.de'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM benutzer WHERE muss_passwort_aendern = 1')->fetchColumn());

        $carla = (int) $this->pdo->query("SELECT id FROM benutzer WHERE email = 'mitglied@test.emc78.de'")->fetchColumn();
        $von = $this->pdo->query("SELECT bezeichnung FROM fahrzeug WHERE angelegt_von_benutzer_id = $carla ORDER BY bezeichnung")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['E 94 279', 'Eilzugwagen Bymb 421'], $von);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug WHERE angelegt_von_benutzer_id IS NULL')->fetchColumn());
    }

    public function test_zuruecksetzen_ist_wiederholbar(): void
    {
        $app = $this->app();
        (new DemoDaten($app))->erzeuge(true);
        (new DemoDaten($app))->erzeuge(true);
        $this->assertSame(12, $this->zaehle('fahrzeug'));
        $this->assertSame(6, $this->zaehle('person'));
        $this->assertSame('EMC-0001', (string) $this->pdo->query('SELECT MIN(inventarnummer) FROM fahrzeug')->fetchColumn());
        $this->assertCount($this->zaehle('dokument'), $this->originalDateien(), 'keine verwaisten Dateien nach dem Zurücksetzen');
    }

    public function test_testdaten_seite_nur_in_testumgebung_und_nur_fuer_admin(): void
    {
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn('a@verein.de', 'geheim-passwort-1', 'admin'));
        $this->assertSame(404, $this->app(false)->handle(new Request('GET', '/admin/testdaten'))->status);

        (new DemoDaten($this->app()))->erzeuge(true);
        $this->sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->assertSame(AnmeldeErgebnis::Erfolg, $this->app()->container()->get(Auth::class)->anmelden('mitglied@test.emc78.de', 'Test-EMC78!', '127.0.0.1'));
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        $this->assertSame(403, $this->app()->handle(new Request('GET', '/admin/testdaten'))->status);
        $this->assertSame(403, $this->app()->handle(new Request('POST', '/admin/testdaten', [], ['_csrf' => str_repeat('a', 64)]))->status);
    }

    public function test_admin_laedt_neu_wird_abgemeldet_und_sieht_hinweis_auf_login(): void
    {
        (new DemoDaten($this->app()))->erzeuge(true);
        $this->sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->app()->container()->get(Auth::class)->anmelden('admin@test.emc78.de', self::ADMIN_PASSWORT, '127.0.0.1');
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        $this->pdo->exec('DELETE FROM decoder');

        $seite = $this->app()->handle(new Request('GET', '/admin/testdaten'));
        $this->assertSame(200, $seite->status);
        $this->assertStringContainsString('Beispieldaten neu laden', $seite->body);
        $this->assertStringContainsString('Testumgebung – Daten können jederzeit zurückgesetzt werden.', $seite->body);

        $r = $this->app()->handle(new Request('POST', '/admin/testdaten', [], ['_csrf' => str_repeat('a', 64)]));
        $this->assertSame(303, $r->status);
        $this->assertStringEndsWith('/login', $r->headers['Location']);
        $this->assertGreaterThan(0, $this->zaehle('decoder'));
        $this->assertArrayNotHasKey('benutzer_id', $this->sitzung);

        $login = $this->app()->handle(new Request('GET', '/login'));
        $this->assertStringContainsString('Beispieldaten wurden neu geladen. Bitte mit einem Test-Zugang anmelden.', $login->body);
        $this->assertStringContainsString('mitglied@test.emc78.de', $login->body);
        $this->assertStringContainsString('Test-EMC78!', $login->body);
    }

    public function test_login_zeigt_zugaenge_nur_in_testumgebung(): void
    {
        $this->legeBenutzerAn('x@verein.de');
        $a = App::erzeuge(['testumgebung' => false] + self::testConfig(), new Session($this->sitzung));
        $body = $a->handle(new Request('GET', '/login'))->body;
        $this->assertStringNotContainsString('Test-EMC78!', $body);
        $this->assertStringNotContainsString('Testumgebung', $body);
    }
}
