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
use Emc\Wartung\WartungFilter;
use Emc\Wartung\Wartungsplan;
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
        $this->assertSame(AnmeldeErgebnis::Erfolg, $auth()->anmelden('fahrzeugwart@test.emc78.de', 'Test-EMC78!', '127.0.0.1'));
    }

    public function test_login_zeigt_testzugaenge_ohne_admin(): void
    {
        $this->legeBenutzerAn('x@verein.de');
        $body = $this->app()->handle(new Request('GET', '/login'))->body;
        $this->assertStringContainsString('mitglied@test.emc78.de', $body);
        $this->assertStringContainsString('bearbeiter@test.emc78.de', $body);
        $this->assertStringContainsString('fahrzeugwart@test.emc78.de', $body);
        $this->assertStringContainsString('Test-EMC78!', $body);
        $this->assertStringContainsString('Admin-Zugang: beim Vorstand erfragen', $body);
        $this->assertStringNotContainsString('admin@test.emc78.de', $body);
        $this->assertStringNotContainsString(self::ADMIN_PASSWORT, $body);
    }

    public function test_legt_beispieldaten_mit_doppelter_adresse_an(): void
    {
        $app = $this->app();
        (new DemoDaten($app))->erzeuge(true);

        $this->assertSame(13, $this->zaehle('fahrzeug'));
        $this->assertSame(7, $this->zaehle('person'));
        $this->assertSame(4, $this->zaehle('benutzer'));
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM fahrzeug WHERE status = 'ausgemustert'")->fetchColumn());
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
        $this->assertSame('fahrzeugwart', (string) $this->pdo->query("SELECT rolle FROM benutzer WHERE email = 'fahrzeugwart@test.emc78.de'")->fetchColumn());
        $this->assertSame('Greta Wagner', (string) $this->pdo->query("SELECT CONCAT(p.vorname, ' ', p.nachname) FROM benutzer b JOIN person p ON p.id = b.person_id WHERE b.email = 'fahrzeugwart@test.emc78.de'")->fetchColumn());
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
        $this->assertSame(13, $this->zaehle('fahrzeug'));
        $this->assertSame(2, $this->zaehle('boerse'));
        $this->assertSame(2, $this->zaehle('boerse_anfrage'));
        $this->assertSame(7, $this->zaehle('person'));
        $this->assertSame('EMC-0001', (string) $this->pdo->query('SELECT MIN(inventarnummer) FROM fahrzeug')->fetchColumn());
        $this->assertCount($this->zaehle('dokument'), $this->originalDateien(), 'keine verwaisten Dateien nach dem Zurücksetzen');
    }

    public function test_anlage_und_wartungen_mit_ampel(): void
    {
        $app = $this->app();
        (new DemoDaten($app))->erzeuge(true);

        $this->assertSame(1, $this->zaehle('anlage'));
        $anlage = $this->pdo->query('SELECT * FROM anlage')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $anlage['nummer']); // Code A1
        $this->assertSame('Clubanlage H0', $anlage['name']);
        $this->assertSame('Vereinsheim, Raum 2', $anlage['standort']);
        $this->assertSame(8, $this->zaehle('wartungsart'), 'Stammliste bleibt beim Zurücksetzen');
        $this->assertSame(9, $this->zaehle('magazin_kategorie'), 'Kategorien des Magazins bleiben beim Zurücksetzen');
        $this->assertSame(['fahrzeuge', 'literatur', 'sonstiges'], $this->pdo->query('SELECT schluessel FROM magazin_kategorie WHERE schluessel IS NOT NULL ORDER BY schluessel')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(3, $this->zaehle('wartung'));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM wartung WHERE ausgefuehrt_von_person_id IS NULL')->fetchColumn());

        $s = $app->container()->get(Wartungsplan::class)->zusammenfassung(new \DateTimeImmutable('today'));
        $this->assertGreaterThanOrEqual(1, $s['rot']);
        $this->assertGreaterThanOrEqual(1, $s['gelb']);
        $this->assertGreaterThan(0, $s['nie']);
        $ampel = [];
        foreach ($app->container()->get(Wartungsplan::class)->uebersicht(new WartungFilter(), new \DateTimeImmutable('today')) as $z) {
            $ampel[Wartungsplan::objektName($z['objekt_typ'], $z['objekt']) . '|' . $z['art']['name']] = $z['faelligkeit']->kategorie();
        }
        $this->assertSame('rot', $ampel['Clubanlage H0|Gleise reinigen']);
        $this->assertSame('gruen', $ampel['V 200 060|Räder und Radschleifer reinigen']);
        $this->assertSame('gelb', $ampel['BR 01 118|Getriebe und Lager schmieren']);

        // Erneut zurücksetzen: wieder genau eine Anlage A1.
        (new DemoDaten($app))->erzeuge(true);
        $this->assertSame(1, $this->zaehle('anlage'));
        $this->assertSame('1', (string) $this->pdo->query('SELECT nummer FROM anlage')->fetchColumn());
    }

    public function test_reparaturen_mit_automatischem_status(): void
    {
        $app = $this->app();
        (new DemoDaten($app))->erzeuge(true);
        $wert = fn(string $sql): mixed => $this->pdo->query($sql)->fetchColumn();

        $this->assertSame(3, $this->zaehle('reparatur'));
        $defekte = $app->container()->get(\Emc\Reparatur\ReparaturRepository::class)->offeneDefekte();
        $this->assertGreaterThanOrEqual(1, $defekte['dringend']);
        $this->assertSame(2, $defekte['anzahl']);

        // Dringende Meldung eines Demo-Mitglieds an einer Demo-Lok: Lok automatisch „defekt“.
        $dringend = $this->pdo->query("SELECT r.*, b.email FROM reparatur r JOIN benutzer b ON b.id = r.gemeldet_von_benutzer_id WHERE r.prioritaet = 'dringend'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Lok bleibt auf der Weiche stehen, Motor brummt', $dringend['beschreibung']);
        $this->assertSame('gemeldet', $dringend['status']);
        $this->assertSame('mitglied@test.emc78.de', $dringend['email']);
        $this->assertSame('fahrzeug', $dringend['objekt_typ']);
        $lok = $this->pdo->query('SELECT gattung, status, status_automatisch FROM fahrzeug WHERE id = ' . (int) $dringend['objekt_id'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['gattung' => 'lok', 'status' => 'defekt', 'status_automatisch' => 1], ['gattung' => $lok['gattung'], 'status' => $lok['status'], 'status_automatisch' => (int) $lok['status_automatisch']]);

        // Anlage: „W12 Antrieb brummt“, in Arbeit, zuständig der Demo-Bearbeiter, Termin in 7 Tagen → Anlage „in Arbeit“.
        $anl = $this->pdo->query("SELECT r.*, CONCAT(p.vorname, ' ', p.nachname) AS zustaendig FROM reparatur r JOIN person p ON p.id = r.zustaendig_person_id WHERE r.objekt_typ = 'anlage'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('W12 Antrieb brummt', $anl['wo_bauteil']);
        $this->assertSame('normal', $anl['prioritaet']);
        $this->assertSame('in_arbeit', $anl['status']);
        $this->assertSame('Bernd Probemeier', $anl['zustaendig']);
        $this->assertSame((new \DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d'), $anl['termin']);
        $this->assertSame(['in_arbeit', 1], [(string) $wert('SELECT status FROM anlage'), (int) $wert('SELECT status_automatisch FROM anlage')]);

        // Erledigte Reparatur mit zwei Ersatzteilen (Summe exakt 12,40 €) und „Was wurde gemacht“; Fahrzeug einsatzbereit.
        $erledigt = $this->pdo->query("SELECT * FROM reparatur WHERE status = 'erledigt'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($erledigt['was_gemacht']);
        $this->assertNotNull($erledigt['abgeschlossen_am']);
        $this->assertSame(['Haftreifen' => '4.90', 'Schleifer' => '7.50'], $this->pdo->query('SELECT bezeichnung, kosten FROM ersatzteil WHERE reparatur_id = ' . (int) $erledigt['id'] . ' ORDER BY sortierung')->fetchAll(\PDO::FETCH_KEY_PAIR));
        $this->assertSame('12.40', (string) $wert('SELECT SUM(kosten) FROM ersatzteil'));
        $this->assertSame('einsatzbereit', (string) $wert('SELECT status FROM fahrzeug WHERE id = ' . (int) $erledigt['objekt_id']));
        // Meldungen liegen in der Vergangenheit (relativ zu heute), die erledigte vor der dringenden.
        $this->assertLessThan($dringend['gemeldet_am'], $erledigt['gemeldet_am']);
        $this->assertLessThanOrEqual(date('Y-m-d H:i:s'), $dringend['gemeldet_am']);

        // Das von Hand „in Wartung“ gestellte Fahrzeug bleibt unberührt.
        $this->assertSame(['in_wartung', 0], [(string) $wert("SELECT status FROM fahrzeug WHERE bezeichnung = 'BR 64 419'"), (int) $wert("SELECT status_automatisch FROM fahrzeug WHERE bezeichnung = 'BR 64 419'")]);
        // Statuswechsel stehen im Protokoll (wie bei echten Eingaben).
        $this->assertGreaterThanOrEqual(2, (int) $wert("SELECT COUNT(*) FROM aenderungsprotokoll WHERE aenderungen LIKE '%automatisch%'"));
    }

    /** Etiketten: einige Fahrzeuge gelten als schon beschriftet, damit „noch nicht gedruckt“ etwas auswählt. */
    public function test_etiketten_teils_gedruckt(): void
    {
        (new DemoDaten($this->app()))->erzeuge(true);
        $gedruckt = (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug WHERE etikett_gedruckt_am IS NOT NULL')->fetchColumn();
        $this->assertGreaterThan(0, $gedruckt);
        $this->assertLessThan(12, $gedruckt);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM fahrzeug WHERE etikett_gedruckt_am IS NOT NULL AND status = 'ausgemustert'")->fetchColumn());
        $this->assertNull($this->pdo->query('SELECT etikett_gedruckt_am FROM anlage')->fetchColumn());
    }

    public function test_fehlende_wartungsart_wird_uebersprungen(): void
    {
        $this->pdo->exec("UPDATE wartungsart SET name = 'Gleise putzen' WHERE name = 'Gleise reinigen'");
        $this->pdo->exec("DELETE FROM wartungsart WHERE name = 'Getriebe und Lager schmieren'");
        (new DemoDaten($this->app()))->erzeuge(true);
        $this->assertSame(1, $this->zaehle('anlage'));
        $this->assertSame(1, $this->zaehle('wartung'));
    }

    public function test_testdaten_seite_nur_in_testumgebung_und_nur_fuer_admin(): void
    {
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn('a@verein.de', 'geheim-passwort-1', 'admin'));
        $this->assertSame(404, $this->app(false)->handle(new Request('GET', '/admin/testdaten'))->status);
        $seite = $this->app()->handle(new Request('GET', '/admin/testdaten'));
        $this->assertSame(200, $seite->status);
        $this->assertStringContainsString('Nie eine Sicherung des Echtbetriebs in die Testinstanz einspielen', $seite->body);

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

    /** Magazin und Börse über die Dienste: Häkchen, Bestände, Übergabe, laufende Börse mit Verkäufen, Anfrage. */
    public function test_magazin_und_boerse(): void
    {
        $app = $this->app();
        (new DemoDaten($app))->erzeuge(true);

        $greta = $this->pdo->query("SELECT rolle, ist_boersenwart FROM benutzer WHERE email = 'fahrzeugwart@test.emc78.de'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['rolle' => 'fahrzeugwart', 'ist_boersenwart' => 1], ['rolle' => $greta['rolle'], 'ist_boersenwart' => (int) $greta['ist_boersenwart']]);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM benutzer WHERE ist_boersenwart = 1 AND email <> 'fahrzeugwart@test.emc78.de'")->fetchColumn());
        $auth = $this->app()->container()->get(Auth::class);
        $this->assertSame(AnmeldeErgebnis::Erfolg, $auth->anmelden('fahrzeugwart@test.emc78.de', 'Test-EMC78!', '127.0.0.1'));
        $this->assertTrue(\Emc\Auth\Berechtigung::istBoersenwart($auth->benutzer()));
        $this->assertSame(\Emc\Auth\Rolle::Fahrzeugwart, $auth->benutzer()?->rolle);

        $bestand = fn(string $bez): int => (int) $this->pdo->query('SELECT SUM(b.menge) FROM magazin_buchung b JOIN magazin_artikel a ON a.id = b.artikel_id WHERE a.bezeichnung = ' . $this->pdo->quote($bez))->fetchColumn();
        $this->assertSame(12, $bestand('Märklin C-Gleis 24188 gerade'));
        $this->assertSame(1, $bestand('Fahrpult 6647'));
        $this->assertSame(0, $bestand('Dampflokomotiven der Deutschen Bundesbahn'), 'an der Kasse verkauft');
        $this->assertSame([], $this->pdo->query('SELECT artikel_id FROM magazin_buchung GROUP BY artikel_id HAVING SUM(menge) < 0')->fetchAll(), 'kein Bestand negativ');
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM magazin_sammlung_artikel sa JOIN magazin_sammlung s ON s.id = sa.sammlung_id WHERE s.name = 'Nachlass Märklin-Gleise, 3 Kisten'")->fetchColumn());

        // Vereins-Köf vom Abstellgleis im Magazin, mit Verweis und Titelfoto; Dieters BR 103 bleibt privat.
        $koef = $this->pdo->query("SELECT a.id, a.fahrzeug_id, a.titelbild_dokument_id, f.eigentuemer_person_id, f.status FROM magazin_artikel a JOIN fahrzeug f ON f.id = a.fahrzeug_id")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $koef);
        $this->assertNull($koef[0]['eigentuemer_person_id']);
        $this->assertSame('ausgemustert', $koef[0]['status']);
        $this->assertSame(1, $bestand((string) $this->pdo->query('SELECT bezeichnung FROM magazin_artikel WHERE id = ' . (int) $koef[0]['id'])->fetchColumn()));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM fahrzeug WHERE bezeichnung = 'BR 103 113-7' AND status = 'ausgemustert' AND eigentuemer_person_id IS NOT NULL")->fetchColumn());

        // Laufende Börse mit Angeboten, zwei Verkäufen und stimmiger Abrechnung.
        $boerse = $this->pdo->query("SELECT * FROM boerse WHERE name = 'Herbstbörse'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['Herbstbörse', 'laeuft', 'Vereinsheim'], [$boerse['name'], $boerse['status'], $boerse['ort']]);
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM boerse_verkauf WHERE storniert_am IS NULL AND boerse_id = {$boerse['id']}")->fetchColumn());
        $abr = new \Emc\Boerse\AbrechnungRepository($this->pdo);
        $r = \Emc\Boerse\Abrechnung::berechne($abr->positionen((int) $boerse['id']), $abr->summeVerkaeufe((int) $boerse['id']));
        $this->assertTrue($r['kontrolle']);
        $this->assertSame(['vereinsstuecke' => 1250, 'provisionen' => 0, 'spenden' => 900, 'gesamt' => 2150], $r['verein']);
        $this->assertSame(['nein' => 7, 'vorgeschlagen' => 1, 'freigegeben' => 4], array_map('intval', $this->pdo->query('SELECT oeffentlich, COUNT(*) FROM boerse_angebot GROUP BY oeffentlich ORDER BY oeffentlich')->fetchAll(\PDO::FETCH_KEY_PAIR)));
        $this->assertSame(7, (int) $this->pdo->query("SELECT COUNT(*) FROM boerse_angebot WHERE herkunft = 'mitglied'")->fetchColumn());
        // Öffentlich sichtbar: zwei Angebote mit Foto, eins davon reserviert
        $oeffentlich = (new \Emc\Boerse\AngebotRepository($this->pdo))->oeffentliche(null, null, null);
        $this->assertCount(2, $oeffentlich);
        $status = array_column($oeffentlich, 'status');
        sort($status);
        $this->assertSame(['angeboten', 'reserviert'], $status);
        foreach ($oeffentlich as $a) {
            $this->assertNotNull($a['titelbild_dokument_id'], 'mit Foto');
        }

        // Abgeschlossene Frühjahrsbörse vor 5 Monaten: drei Verkäufe, Auszahlung an Carla, Rest zurück
        $fruehjahr = $this->pdo->query("SELECT * FROM boerse WHERE name = 'Frühjahrsbörse'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('abgeschlossen', $fruehjahr['status']);
        $this->assertSame((new \DateTimeImmutable('today'))->modify('-5 months')->format('Y-m-d'), $fruehjahr['datum']);
        $r = \Emc\Boerse\Abrechnung::berechne($abr->positionen((int) $fruehjahr['id']), $abr->summeVerkaeufe((int) $fruehjahr['id']));
        $this->assertTrue($r['kontrolle']);
        $this->assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM boerse_verkauf_position p JOIN boerse_verkauf v ON v.id = p.verkauf_id WHERE v.boerse_id = {$fruehjahr['id']}")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM boerse_auszahlung WHERE boerse_id = {$fruehjahr['id']}")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM boerse_angebot WHERE boerse_id = {$fruehjahr['id']} AND status = 'zurueck'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM boerse_angebot WHERE boerse_id IS NULL')->fetchColumn(), 'ein dauerhaftes Angebot');

        // Eine offene Kaufanfrage und eine angebotene Sammlung mit Foto, Testadressen; keine Sperrzeile übrig.
        $anfrage = $this->pdo->query('SELECT * FROM boerse_anfrage ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $anfrage);
        $this->assertSame([['kaufanfrage', 'offen'], ['sammlung', 'offen']], array_map(fn(array $a): array => [$a['art'], $a['status']], $anfrage));
        foreach ($anfrage as $a) {
            $this->assertStringEndsWith('@test.emc78.de', (string) $a['email']);
        }
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'boerse_anfrage' AND objekt_id = {$anfrage[1]['id']}")->fetchColumn());
        $this->assertSame(0, $this->zaehle('boerse_anfrage_sperre'));
    }
}
