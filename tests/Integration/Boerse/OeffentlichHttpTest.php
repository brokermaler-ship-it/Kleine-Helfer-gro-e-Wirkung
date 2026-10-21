<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Boerse;

use Emc\Auth\Benutzer;
use Emc\Auth\Rolle;
use Emc\Boerse\AngebotDienst;
use Emc\Boerse\AngebotRepository;
use Emc\Boerse\BoerseRepository;
use Emc\Boerse\Spamschutz;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadService;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;
use Emc\Tests\Support\ExifJpeg;

/**
 * Öffentlicher Bereich /boerse ohne Anmeldung: abschaltbar, nur freigegebene Angebote ohne interne oder persönliche
 * Daten, Fotos nur als Variante, Kaufanfrage und „Sammlung anbieten“ mit CSRF, Fangfeld, Mindestzeit und Begrenzung.
 */
final class OeffentlichHttpTest extends DbTestCase
{
    private const AN = ['boerse_oeffentlich' => true, 'datenschutz_url' => 'https://www.emc78.de/datenschutz'];
    private const IP = '203.0.113.7';

    private array $sitzung = [];
    private string $speicher;
    /** @var list<string> */
    private array $temp = [];
    private AngebotDienst $angebote;
    private MagazinDienst $magazin;
    private Benutzer $wart;
    private Benutzer $carla;
    private int $jahr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
        $this->jahr = (int) date('Y');
        $rep = new AngebotRepository($this->pdo);
        $magRep = new MagazinRepository($this->pdo);
        $protokoll = new Aenderungsprotokoll($this->pdo);
        $this->angebote = new AngebotDienst($this->pdo, $rep, $magRep, $protokoll, new BoerseRepository($this->pdo));
        $this->magazin = new MagazinDienst($this->pdo, $magRep, $protokoll, $rep);
        $this->wart = $this->benutzer('greta@verein.de', Rolle::Fahrzeugwart, true, 'Greta', 'Wartmann');
        $this->carla = $this->benutzer('carla@verein.de', Rolle::Mitglied, false, 'Carla', 'Mustermann');
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    private function benutzer(string $email, Rolle $rolle, bool $boersenwart, string $vorname, string $nachname): Benutzer
    {
        $id = $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle->value);
        $person = (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = $id")->fetchColumn();
        $this->pdo->prepare('UPDATE person SET vorname = ?, nachname = ? WHERE id = ?')->execute([$vorname, $nachname, $person]);
        $this->pdo->prepare('UPDATE benutzer SET ist_boersenwart = ? WHERE id = ?')->execute([$boersenwart ? 1 : 0, $id]);
        return new Benutzer($id, $person, $email, $email, $rolle, false, $vorname, $boersenwart);
    }

    private function app(array $config = self::AN): App
    {
        $app = App::erzeuge($config + ['speicher_pfad' => $this->speicher] + self::testConfig(), new Session($this->sitzung));
        $app->container()->set(UploadService::class, fn() => new UploadService(
            $this->pdo, $this->speicher, 8 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b)
        ));
        return $app;
    }

    private function get(string $pfad, array $query = [], array $config = self::AN): Response
    {
        return $this->app($config)->handle(new Request('GET', $pfad, $query, [], [], ['REMOTE_ADDR' => self::IP, 'HTTP_HOST' => 'verein.example']));
    }

    private function post(string $pfad, array $daten, array $dateien = [], array $config = self::AN, string $ip = self::IP): Response
    {
        return $this->app($config)->handle(new Request('POST', $pfad, [], $daten, $dateien, ['REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'verein.example']));
    }

    /** Zeitmarke wie im Formular, gebunden an das CSRF-Token der Test-Sitzung. */
    private function zeitmarke(string $vor = '-5 seconds', string $token = ''): string
    {
        return $this->app()->container()->get(Spamschutz::class)->zeitmarke(new \DateTimeImmutable($vor), $token !== '' ? $token : str_repeat('a', 64));
    }

    /** @return array<string,string> */
    private function anfrage(array $mehr = []): array
    {
        return $mehr + [
            '_csrf' => str_repeat('a', 64), 'zeitmarke' => $this->zeitmarke(), 'webseite' => '',
            'name' => 'Erika Beispiel', 'email' => 'erika@test.emc78.de', 'telefon' => '', 'nachricht' => 'Ist die Lok noch zu haben?', 'einwilligung' => '1',
        ];
    }

    private function kategorie(string $schluessel = 'sonstiges'): int
    {
        return (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = '$schluessel'")->fetchColumn();
    }

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function nummer(int $id): string
    {
        $z = $this->pdo->query("SELECT jahr, laufnummer FROM boerse_angebot WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        return 'B' . $z['jahr'] . '-' . $z['laufnummer'];
    }

    /** Angebot von Carla (mit interner Notiz), auf Wunsch vom Börsenwart freigegeben. */
    private function carlasAngebot(string $bezeichnung = 'Märklin BR 50 mit Tender', bool $freigeben = true, array $mehr = []): int
    {
        $id = $this->angebote->anlegen($mehr + [
            'bezeichnung' => $bezeichnung, 'beschreibung' => 'Gut erhalten, OVP', 'kategorie_id' => $this->kategorie('fahrzeuge'), 'spurweite_id' => null,
            'zustand' => 'neuwertig', 'wunschpreis' => '12.50', 'menge' => 1, 'herkunft' => 'mitglied', 'person_id' => $this->carla->personId,
            'erloes_spende' => 0, 'boerse_id' => null, 'notiz' => 'Kiste im Keller', 'oeffentlich' => 'vorgeschlagen',
        ], $this->carla, new \DateTimeImmutable());
        if ($freigeben) {
            $this->angebote->setzeOeffentlich($id, 'freigegeben', $this->wart);
        }
        return $id;
    }

    /** Vereinsangebot aus einem Magazinartikel mit Lagerort „Regal 7“; liefert [Angebot, Artikel]. */
    private function vereinsAngebot(): array
    {
        $artikel = $this->magazin->legeAnMitEingang(
            ['bezeichnung' => 'Fahrpult 6647', 'kategorie_id' => $this->kategorie(), 'lagerort' => 'Regal 7', 'notiz' => 'Kabel lose'],
            ['datum' => date('Y-m-d'), 'menge' => 2, 'herkunft' => 'nachlass', 'freitext' => 'Nachlass Gustav Geheim', 'betrag' => null],
            $this->wart->id
        );
        $id = $this->angebote->anlegen([
            'bezeichnung' => 'Fahrpult 6647 vom Verein', 'beschreibung' => 'funktioniert', 'kategorie_id' => $this->kategorie(), 'spurweite_id' => null,
            'zustand' => 'gebraucht', 'wunschpreis' => '5.00', 'menge' => 1, 'herkunft' => 'verein', 'person_id' => null, 'erloes_spende' => 0,
            'boerse_id' => null, 'notiz' => 'Vereinsnotiz intern', 'magazin_artikel_id' => $artikel, 'oeffentlich' => 'freigegeben',
        ], $this->wart, new \DateTimeImmutable());
        return [$id, $artikel];
    }

    /** Foto (aus dem Handy, mit GPS) intern am Angebot; liefert die Dokument-ID. */
    private function fotoAmAngebot(int $angebotId): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $this->temp[] = $tmp;
        file_put_contents($tmp, ExifJpeg::erzeuge());
        $upload = new UploadService($this->pdo, $this->speicher, 8 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b));
        $id = $upload->speichere(['name' => 'Carla Mustermann Keller.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], '', 'foto', $this->carla->id);
        $dok = new DokumentRepository($this->pdo, $this->speicher);
        $dok->zuordnen($id, 'boerse_angebot', $angebotId);
        $dok->setzeTitelbildWennLeer('boerse_angebot', $angebotId, $id);
        return $id;
    }

    private function assertOhneInterna(string $html, int $artikel): void
    {
        foreach (['Mustermann', 'Carla', 'Kiste im Keller', 'Regal 7', 'Mitglied', 'Herkunft', 'Greta', 'Wartmann', 'Kabel lose', 'Vereinsnotiz', 'Gustav Geheim', 'carla@', 'greta@', 'Keller.jpg', 'Abmelden', '/dateien/'] as $verboten) {
            $this->assertStringNotContainsString($verboten, $html, $verboten);
        }
        $this->assertDoesNotMatchRegularExpression('/\bM' . $artikel . '\b/', $html, 'Magazin-Code');
    }

    public function test_abgeschaltet(): void
    {
        $id = $this->carlasAngebot();
        $nr = $this->nummer($id);
        foreach ([[], ['boerse_oeffentlich' => true], ['boerse_oeffentlich' => 'true', 'datenschutz_url' => 'https://www.emc78.de/datenschutz'], ['boerse_oeffentlich' => true, 'datenschutz_url' => 'http://www.emc78.de/datenschutz'], ['datenschutz_url' => 'https://www.emc78.de/datenschutz']] as $config) {
            foreach (['/boerse', '/boerse/', "/boerse/angebot/$nr", '/boerse/sammlung-anbieten', '/boerse/danke', '/boerse/foto/' . $nr . '/1/anzeige', '/boerse/gibt-es-nicht'] as $pfad) {
                $this->assertSame(404, $this->get($pfad, [], $config)->status, $pfad . ' ' . json_encode($config));
            }
            // Auch ohne CSRF-Token: 404 statt 419, nichts gespeichert.
            $this->assertSame(404, $this->post('/boerse/sammlung-anbieten', ['name' => 'x'], [], $config)->status);
            $this->assertSame(404, $this->post("/boerse/angebot/$nr/anfrage", $this->anfrage(), [], $config)->status);
        }
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
        $an = $this->get('/boerse');
        $this->assertSame(200, $an->status);
        $this->assertStringContainsString('Märklin BR 50 mit Tender', $an->body);
        // Die interne Börse bleibt hinter der Anmeldung (Präfix-Falle /boerse vs. /boersen).
        foreach (['/boersen', '/boersen/1', '/boersen/1/kasse', '/angebote', "/angebote/$id"] as $pfad) {
            $r = $this->get($pfad);
            $this->assertSame(303, $r->status, $pfad);
            $this->assertStringStartsWith(url('/login'), $r->headers['Location'], $pfad);
        }
    }

    public function test_nur_freigegebene_ohne_personendaten(): void
    {
        $frei = $this->carlasAngebot();
        [$verein, $artikel] = $this->vereinsAngebot();
        $vorgeschlagen = $this->carlasAngebot('Vorgeschlagene Lok', false);
        $nein = $this->angebote->anlegen([
            'bezeichnung' => 'Nicht öffentliche Lok', 'beschreibung' => null, 'kategorie_id' => $this->kategorie(), 'spurweite_id' => null, 'zustand' => 'gebraucht',
            'wunschpreis' => '1.00', 'menge' => 1, 'herkunft' => 'mitglied', 'person_id' => $this->carla->personId, 'erloes_spende' => 0, 'boerse_id' => null, 'notiz' => null,
        ], $this->carla, new \DateTimeImmutable());
        $zurueck = $this->carlasAngebot('Zurückgezogene Lok');
        $this->angebote->setzeStatus($zurueck, 'zurueckziehen', $this->carla);
        $verkauft = $this->carlasAngebot('Verkaufte Lok');
        $this->pdo->exec("UPDATE boerse_angebot SET status = 'verkauft' WHERE id = $verkauft");
        $reserviert = $this->carlasAngebot('Reservierte Lok');
        $this->angebote->setzeStatus($reserviert, 'reservieren', $this->wart);
        // Angebot einer abgeschlossenen Börse (Status noch offen, z. B. aus alten Daten)
        $this->pdo->exec("INSERT INTO boerse (name, datum, status) VALUES ('Alte Börse', '2020-03-01', 'abgeschlossen')");
        $alteBoerse = (int) $this->pdo->lastInsertId();
        $alt = $this->carlasAngebot('Lok der alten Börse');
        $this->pdo->exec("UPDATE boerse_angebot SET boerse_id = $alteBoerse WHERE id = $alt");

        $liste = $this->get('/boerse');
        $this->assertSame(200, $liste->status);
        $html = $liste->body;
        $this->assertStringContainsString('Märklin BR 50 mit Tender', $html);
        $this->assertStringContainsString('12,50 €', $html);
        $this->assertStringContainsString($this->nummer($frei), $html);
        $this->assertStringContainsString('neuwertig', $html);
        $this->assertStringContainsString('Fahrzeuge', $html);
        $this->assertStringContainsString('Fahrpult 6647 vom Verein', $html);
        $this->assertStringContainsString('Reservierte Lok', $html);
        $this->assertStringContainsString('reserviert', $html);
        foreach (['Vorgeschlagene Lok', 'Nicht öffentliche Lok', 'Zurückgezogene Lok', 'Verkaufte Lok', 'Lok der alten Börse'] as $fehlt) {
            $this->assertStringNotContainsString($fehlt, $html, $fehlt);
        }
        $this->assertOhneInterna($html, $artikel);
        $this->assertStringContainsString('Sammlung anbieten', $html);
        $this->assertStringContainsString('href="https://www.emc78.de/datenschutz"', $html);

        foreach ([$frei, $verein, $reserviert] as $id) {
            $seite = $this->get('/boerse/angebot/' . $this->nummer($id));
            $this->assertSame(200, $seite->status);
            $this->assertOhneInterna($seite->body, $artikel);
            $this->assertStringContainsString('Kaufanfrage', $seite->body);
            $this->assertStringContainsString('name="webseite"', $seite->body);
            $this->assertStringContainsString('name="zeitmarke"', $seite->body);
            $this->assertStringContainsString('name="_csrf"', $seite->body);
        }
        $this->assertStringContainsString('Gut erhalten, OVP', $this->get('/boerse/angebot/' . $this->nummer($frei))->body);

        $unbekannt = $this->get('/boerse/angebot/B' . $this->jahr . '-999');
        $this->assertSame(404, $unbekannt->status);
        foreach ([$vorgeschlagen, $nein, $zurueck, $verkauft, $alt] as $id) {
            $r = $this->get('/boerse/angebot/' . $this->nummer($id));
            $this->assertSame(404, $r->status, $this->nummer($id));
            $this->assertSame($unbekannt->body, $r->body, 'wie eine unbekannte Nummer');
            $this->assertSame(404, $this->post('/boerse/angebot/' . $this->nummer($id) . '/anfrage', $this->anfrage())->status);
        }
        $this->assertSame(404, $this->get('/boerse/angebot/quatsch')->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));

        // Suche nur in öffentlichen Feldern: die interne Notiz findet nichts.
        $this->assertStringContainsString('Gerade gibt es keine Angebote', $this->get('/boerse', ['q' => 'Keller'])->body);
        $this->assertStringContainsString('Gerade gibt es keine Angebote', $this->get('/boerse', ['q' => 'Regal'])->body);
        $treffer = $this->get('/boerse', ['q' => 'Fahrpult'])->body;
        $this->assertStringContainsString('Fahrpult 6647 vom Verein', $treffer);
        $this->assertStringNotContainsString('Märklin BR 50', $treffer);
        $this->assertStringContainsString('Märklin BR 50', $this->get('/boerse', ['q' => $this->nummer($frei)])->body);
        $kat = $this->get('/boerse', ['kategorie' => (string) $this->kategorie('fahrzeuge')])->body;
        $this->assertStringContainsString('Märklin BR 50', $kat);
        $this->assertStringNotContainsString('Fahrpult 6647 vom Verein', $kat);
        $this->assertSame(200, $this->get('/boerse', ['kategorie' => ['x'], 'spurweite' => '-1', 'q' => ['y']])->status, 'kaputte Filter');
    }

    public function test_angemeldet_ohne_benutzerdaten(): void
    {
        $this->carlasAngebot();
        $this->sitzungAnmelden($this->sitzung, $this->wart->id);
        $html = $this->get('/boerse')->body;
        $this->assertStringContainsString('Märklin BR 50', $html);
        $this->assertStringNotContainsString('Greta', $html);
        $this->assertStringNotContainsString('Abmelden', $html);
        $this->assertStringNotContainsString('hauptmenue', $html);
        // Meldungen der internen Seiten bleiben für die nächste interne Seite liegen.
        $this->sitzung['_meldungen'] = [['typ' => 'erfolg', 'text' => 'Interne Meldung']];
        $this->assertStringNotContainsString('Interne Meldung', $this->get('/boerse')->body);
        $this->assertSame('Interne Meldung', $this->sitzung['_meldungen'][0]['text']);
    }

    /** Fehlerseiten unter /boerse (404, 405, 419, 500) im öffentlichen Layout – auch angemeldet ohne Name, Menü und Meldungen. */
    public function test_fehlerseiten_im_oeffentlichen_layout(): void
    {
        $nr = $this->nummer($this->carlasAngebot());
        $this->sitzungAnmelden($this->sitzung, $this->wart->id);
        $this->sitzung['_meldungen'] = [['typ' => 'erfolg', 'text' => 'Interne Meldung']];
        $kaputt = $this->app();
        $kaputt->container()->set(AngebotRepository::class, fn() => throw new \RuntimeException('Datenbank weg'));
        $antworten = [
            [404, $this->get('/boerse/gibt-es-nicht')],
            [404, $this->get("/boerse/foto/$nr/999/anzeige")],
            [405, $this->post('/boerse/danke', ['_csrf' => str_repeat('a', 64)])],
            [419, $this->post('/boerse/sammlung-anbieten', $this->anfrage(['_csrf' => 'falsch']))],
            [500, $kaputt->handle(new Request('GET', '/boerse', [], [], [], ['REMOTE_ADDR' => self::IP]))],
        ];
        foreach ($antworten as [$status, $r]) {
            $this->assertSame($status, $r->status);
            foreach (['Greta', 'Wartmann', 'Abmelden', 'hauptmenue', 'Interne Meldung', 'Datenbank weg'] as $verboten) {
                $this->assertStringNotContainsString($verboten, $r->body, $status . ' ' . $verboten);
            }
            $this->assertStringContainsString('<meta name="robots" content="noindex">', $r->body, (string) $status);
            $this->assertStringContainsString('Modellbahnbörse', $r->body);
            $this->assertSame('no-store', $r->headers['Cache-Control'] ?? null, (string) $status);
            $this->assertSame('same-origin', $r->headers['Referrer-Policy'] ?? null);
        }
        $this->assertSame('GET', $antworten[2][1]->headers['Allow'] ?? null);
        $this->assertStringContainsString('Bitte lade sie neu', $antworten[3][1]->body);
        $this->assertSame('Interne Meldung', $this->sitzung['_meldungen'][0]['text'] ?? null, 'Meldung bleibt liegen');
        // Abgeschaltet bleibt es die gewöhnliche 404 der App.
        $this->assertStringNotContainsString('Modellbahnbörse', $this->get('/boerse/gibt-es-nicht', [], [])->body);
    }

    /** Fotos und die Liste starten keine Sitzung (kein Set-Cookie, keine Sitzungsdatei je Abruf); Formulare schon (CSRF). */
    public function test_keine_sitzung_ohne_formular(): void
    {
        $a = $this->carlasAngebot();
        $dok = $this->fotoAmAngebot($a);
        $nr = $this->nummer($a);
        $gestartet = 0;
        $app = function () use (&$gestartet): App {
            $app = $this->app();
            $app->container()->set('session', function () use (&$gestartet) {
                $gestartet++;
                return new Session($this->sitzung);
            });
            return $app;
        };
        $r = $app()->handle(new Request('GET', "/boerse/foto/$nr/$dok/vorschau", [], [], [], ['REMOTE_ADDR' => self::IP]));
        $this->assertSame(200, $r->status);
        $this->assertSame('public, max-age=3600', $r->headers['Cache-Control']);
        $this->assertSame(200, $app()->handle(new Request('GET', '/boerse', [], [], [], ['REMOTE_ADDR' => self::IP]))->status);
        $this->assertSame(404, $app()->handle(new Request('GET', "/boerse/foto/$nr/999/anzeige", [], [], [], ['REMOTE_ADDR' => self::IP]))->status);
        $this->assertSame(0, $gestartet, 'keine Sitzung');
        $seite = $app()->handle(new Request('GET', "/boerse/angebot/$nr", [], [], [], ['REMOTE_ADDR' => self::IP]));
        $this->assertStringContainsString('name="_csrf"', $seite->body);
        $this->assertSame(1, $gestartet, 'Formular braucht die Sitzung');
    }

    public function test_zeitmarke_aus_anderer_sitzung(): void
    {
        $nr = $this->nummer($this->carlasAngebot());
        $r = $this->post("/boerse/angebot/$nr/anfrage", $this->anfrage(['zeitmarke' => $this->zeitmarke('-10 seconds', str_repeat('b', 64))]));
        $this->assertSame(303, $r->status);
        $this->assertSame(url('/boerse/danke'), $r->headers['Location']);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'), 'wiederverwendete Marke einer anderen Sitzung wird verworfen');
    }

    public function test_naechste_boerse(): void
    {
        $this->assertStringNotContainsString('Nächste Börse', $this->get('/boerse')->body);
        $datum = (new \DateTimeImmutable('+21 days'))->format('Y-m-d');
        $this->pdo->prepare("INSERT INTO boerse (name, datum, ort) VALUES ('Herbstbörse', ?, 'Vereinsheim')")->execute([$datum]);
        $this->assertStringContainsString('Nächste Börse: Herbstbörse am ' . (new \DateTimeImmutable($datum))->format('d.m.Y') . ' in Vereinsheim', $this->get('/boerse')->body);
    }

    public function test_foto_nur_variante(): void
    {
        $a = $this->carlasAngebot();
        $b = $this->carlasAngebot('Zweite Lok');
        $dok = $this->fotoAmAngebot($a);
        $fremd = $this->fotoAmAngebot($b);
        $nr = $this->nummer($a);

        $liste = $this->get('/boerse')->body;
        $this->assertStringContainsString(url("/boerse/foto/$nr/$dok/vorschau"), $liste);
        $this->assertStringContainsString(url("/boerse/foto/$nr/$dok/anzeige"), $this->get("/boerse/angebot/$nr")->body);

        foreach (['anzeige', 'vorschau'] as $variante) {
            $r = $this->get("/boerse/foto/$nr/$dok/$variante");
            $this->assertSame(200, $r->status);
            $this->assertSame('image/jpeg', $r->headers['Content-Type']);
            $this->assertSame('inline; filename="foto.jpg"', $r->headers['Content-Disposition']);
            $this->assertSame('public, max-age=3600', $r->headers['Cache-Control']);
            $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
            $this->assertStringContainsString("default-src 'none'", $r->headers['Content-Security-Policy']);
            $this->assertStringEndsWith('.' . $variante . '.jpg', (string) $r->datei);
            $this->assertStringNotContainsString(ExifJpeg::KAMERA, (string) file_get_contents((string) $r->datei), 'Variante ohne EXIF');
            $this->assertStringNotContainsString('Mustermann', implode(' ', $r->headers));
        }
        foreach (["/boerse/foto/$nr/$dok/original", "/boerse/foto/$nr/$dok", "/boerse/foto/$nr/$fremd/anzeige", "/boerse/foto/$nr/999/anzeige", "/boerse/foto/B{$this->jahr}-999/$dok/anzeige", "/boerse/foto/$nr/x/anzeige"] as $pfad) {
            $this->assertSame(404, $this->get($pfad)->status, $pfad);
        }
        // Nicht mehr öffentlich → Foto 404
        $this->angebote->setzeOeffentlich($a, 'nein', $this->wart);
        $this->assertSame(404, $this->get("/boerse/foto/$nr/$dok/anzeige")->status);
        // Die interne Auslieferung bleibt hinter der Anmeldung.
        $r = $this->get("/dateien/$dok");
        $this->assertSame(303, $r->status);
        $this->assertStringStartsWith(url('/login'), $r->headers['Location']);
        $this->assertSame(303, $this->get("/dateien/$dok/anzeige")->status);
    }

    public function test_kaufanfrage_gespeichert(): void
    {
        $id = $this->carlasAngebot();
        $nr = $this->nummer($id);
        $r = $this->post("/boerse/angebot/$nr/anfrage", $this->anfrage());
        $this->assertSame(303, $r->status, $r->body);
        $this->assertSame(url('/boerse/danke'), $r->headers['Location']);
        $z = $this->pdo->query('SELECT * FROM boerse_anfrage')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('kaufanfrage', $z['art']);
        $this->assertSame($id, (int) $z['angebot_id']);
        $this->assertSame('offen', $z['status']);
        $this->assertSame('Erika Beispiel', $z['name']);
        $this->assertSame('erika@test.emc78.de', $z['email']);
        $this->assertNull($z['telefon']);
        $this->assertNull($z['wunsch']);
        $hash = (string) $this->pdo->query('SELECT ip_hash FROM boerse_anfrage_sperre')->fetchColumn();
        $this->assertSame(64, strlen($hash));
        $this->assertNotSame(self::IP, $hash);
        $this->assertStringNotContainsString(self::IP, $hash);
        $this->assertSame('angeboten', $this->pdo->query("SELECT status FROM boerse_angebot WHERE id = $id")->fetchColumn(), 'reserviert nicht automatisch');

        $danke = $this->get('/boerse/danke');
        $this->assertSame(200, $danke->status);
        $this->assertStringContainsString('Danke! Deine Anfrage ist bei uns angekommen. Wir melden uns bei dir.', $danke->body);
        $this->assertStringContainsString('noindex', $danke->body);
    }

    public function test_validierung_422(): void
    {
        $nr = $this->nummer($this->carlasAngebot());
        $marke = $this->zeitmarke('-10 seconds');
        $r = $this->post("/boerse/angebot/$nr/anfrage", $this->anfrage(['zeitmarke' => $marke, 'name' => '<b>Erika</b>', 'email' => '', 'telefon' => '', 'einwilligung' => '']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte gib eine E-Mail-Adresse oder eine Telefonnummer an.', $r->body);
        $this->assertStringContainsString('Bitte stimme der Verarbeitung deiner Angaben zu.', $r->body);
        $this->assertStringContainsString('&lt;b&gt;Erika&lt;/b&gt;', $r->body, 'Eingabe bleibt, escaped');
        $this->assertStringNotContainsString('<b>Erika</b>', $r->body);
        $this->assertStringContainsString('value="' . $marke . '"', $r->body, 'Zeitmarke bleibt: sofortiges Korrigieren gilt nicht als Bot');
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage_sperre'), 'nicht gezählt');
        // Gleich danach korrigiert absenden
        $this->assertSame(303, $this->post("/boerse/angebot/$nr/anfrage", $this->anfrage(['zeitmarke' => $marke]))->status);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
    }

    public function test_fangfeld_und_zu_schnell(): void
    {
        $nr = $this->nummer($this->carlasAngebot());
        $echt = $this->post("/boerse/angebot/$nr/anfrage", $this->anfrage(['email' => 'zweite@test.emc78.de']));
        $this->pdo->exec('DELETE FROM boerse_anfrage');
        $this->pdo->exec('DELETE FROM boerse_anfrage_sperre');
        foreach ([
            ['webseite' => 'https://spam.example'],
            ['zeitmarke' => $this->zeitmarke('-1 second')],
            ['zeitmarke' => 'kaputt'],
            ['zeitmarke' => $this->zeitmarke('-2 days')],
        ] as $bot) {
            foreach (["/boerse/angebot/$nr/anfrage", '/boerse/sammlung-anbieten'] as $pfad) {
                $r = $this->post($pfad, $this->anfrage($bot + ['wunsch' => 'spenden']));
                $this->assertSame($echt->status, $r->status, $pfad . json_encode($bot));
                $this->assertSame($echt->headers, $r->headers, 'dieselbe Antwort wie bei Erfolg');
            }
        }
        // Auch ein Bot mit ungültigen Feldern bekommt die Dankeseite (keine Hinweise, welche Felder fehlen).
        $this->assertSame(url('/boerse/danke'), $this->post('/boerse/sammlung-anbieten', ['_csrf' => str_repeat('a', 64), 'webseite' => 'x'])->headers['Location'] ?? null);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage_sperre'));
    }

    public function test_begrenzung(): void
    {
        $nr = $this->nummer($this->carlasAngebot());
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame(303, $this->post("/boerse/angebot/$nr/anfrage", $this->anfrage())->status, "Anfrage $i");
        }
        $r = $this->post("/boerse/angebot/$nr/anfrage", $this->anfrage(['name' => 'Sechste Person']));
        $this->assertSame(429, $r->status);
        $this->assertStringContainsString('Bitte später erneut versuchen.', $r->body);
        $this->assertStringContainsString('Sechste Person', $r->body, 'Formular bleibt ausgefüllt');
        $this->assertSame(5, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
        // Eine andere IP darf weiter.
        $this->assertSame(303, $this->post('/boerse/sammlung-anbieten', $this->anfrage(['wunsch' => 'verkaufen']), [], self::AN, '198.51.100.4')->status);
    }

    public function test_ohne_csrf_419(): void
    {
        $nr = $this->nummer($this->carlasAngebot());
        $ohne = $this->anfrage();
        unset($ohne['_csrf']);
        $this->assertSame(419, $this->post("/boerse/angebot/$nr/anfrage", $ohne)->status);
        $this->assertSame(419, $this->post('/boerse/sammlung-anbieten', ['_csrf' => 'falsch'] + $ohne)->status);
        $this->sitzung = [];
        $this->assertSame(419, $this->post("/boerse/angebot/$nr/anfrage", $this->anfrage())->status, 'Sitzung ohne Token');
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
        // Das Formular bringt ein Token für anonyme Besucher mit.
        $seite = $this->get("/boerse/angebot/$nr")->body;
        $this->assertStringContainsString('value="' . $this->sitzung['_csrf'] . '"', $seite);
        $this->assertArrayNotHasKey('benutzer_id', $this->sitzung, 'keine Anmeldung');
    }

    public function test_sammlung_mit_fotos(): void
    {
        $seite = $this->get('/boerse/sammlung-anbieten');
        $this->assertSame(200, $seite->status);
        $this->assertStringContainsString('name="fotos[]"', $seite->body);
        $this->assertStringContainsString('enctype="multipart/form-data"', $seite->body);
        $this->assertStringContainsString('value="verkaufen"', $seite->body);
        $this->assertStringContainsString('value="spenden"', $seite->body);
        $this->assertStringContainsString('Datenschutzerklärung', $seite->body);

        $jpg = tempnam(sys_get_temp_dir(), 'up');
        $pdf = tempnam(sys_get_temp_dir(), 'up');
        $this->temp[] = $jpg;
        $this->temp[] = $pdf;
        file_put_contents($jpg, ExifJpeg::erzeuge());
        copy(dirname(__DIR__, 2) . '/fixtures/dateien/anleitung.pdf', $pdf);
        $dateien = ['fotos' => [
            'name' => ['IMG_0001 bei Familie Beispiel.jpg', 'liste.pdf'],
            'tmp_name' => [$jpg, $pdf],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [filesize($jpg), filesize($pdf)],
        ]];
        $r = $this->post('/boerse/sammlung-anbieten', $this->anfrage(['nachricht' => 'Drei Kisten Spur-N-Wagen', 'wunsch' => 'spenden', 'telefon' => '0345 123456', 'email' => '']), $dateien);
        $this->assertSame(303, $r->status, $r->body);
        $this->assertSame(url('/boerse/danke'), $r->headers['Location']);
        $z = $this->pdo->query('SELECT * FROM boerse_anfrage')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('sammlung', $z['art']);
        $this->assertNull($z['angebot_id']);
        $this->assertSame('spenden', $z['wunsch']);
        $this->assertSame('0345 123456', $z['telefon']);
        $doks = $this->pdo->query("SELECT d.* FROM dokument d JOIN dokument_zuordnung z ON z.dokument_id = d.id WHERE z.objekt_typ = 'boerse_anfrage'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $doks);
        $this->assertSame('foto-1.jpg', $doks[0]['original_name']);
        $datei = $this->speicher . '/' . $doks[0]['pfad'];
        $exif = @exif_read_data($datei);
        $this->assertFalse(is_array($exif) && isset($exif['GPSLatitude']));
        $this->assertStringNotContainsString(ExifJpeg::KAMERA, (string) file_get_contents($datei));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM onedrive_auftrag'));
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM dokument WHERE original_name LIKE '%Beispiel%'"));
        // Hinweis auf das abgelehnte Foto auf der Dankeseite (einmalig).
        $danke = $this->get('/boerse/danke')->body;
        $this->assertStringContainsString('Danke! Deine Anfrage ist bei uns angekommen.', $danke);
        $this->assertStringContainsString('1 Datei konnten wir nicht als Foto übernehmen', $danke);
        $this->assertStringNotContainsString('konnten wir nicht', $this->get('/boerse/danke')->body);

        // Die Fotos sieht intern nur der Börsenwart.
        $this->sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($this->sitzung, $this->carla->id);
        $this->assertSame(404, $this->get('/dateien/' . $doks[0]['id'] . '/anzeige')->status);
        $this->assertSame(404, $this->post('/dateien/' . $doks[0]['id'] . '/loeschen', ['_csrf' => str_repeat('a', 64)])->status, 'Löschversuch wie unbekannt');
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM dokument WHERE id = ' . (int) $doks[0]['id']));
        $this->sitzungAnmelden($this->sitzung, $this->wart->id);
        $this->assertSame(200, $this->get('/dateien/' . $doks[0]['id'] . '/anzeige')->status);
    }

    public function test_sammlung_zu_viele_fotos(): void
    {
        $dateien = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        for ($i = 0; $i < 6; $i++) {
            $jpg = tempnam(sys_get_temp_dir(), 'up');
            $this->temp[] = $jpg;
            file_put_contents($jpg, ExifJpeg::erzeuge());
            $dateien['name'][] = "f$i.jpg";
            $dateien['tmp_name'][] = $jpg;
            $dateien['error'][] = UPLOAD_ERR_OK;
            $dateien['size'][] = filesize($jpg);
        }
        $r = $this->post('/boerse/sammlung-anbieten', $this->anfrage(['wunsch' => 'verkaufen']), ['fotos' => $dateien]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte höchstens 5 Fotos auswählen.', $r->body);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));
    }

    public function test_zu_grosse_anfrage(): void
    {
        $app = $this->app(self::AN + ['post_max_bytes' => 1000]);
        $r = $app->handle(new Request('POST', '/boerse/sammlung-anbieten', [], [], [], ['REMOTE_ADDR' => self::IP, 'CONTENT_LENGTH' => '5000']));
        $this->assertSame(413, $r->status);
        $this->assertStringContainsString('zusammen zu groß', $r->body);
        $this->assertStringNotContainsString('hauptmenue', $r->body);
    }

    public function test_testinstanz_hinweis(): void
    {
        $this->assertStringNotContainsString('noindex', $this->get('/boerse')->body, 'Echtbetrieb: Liste darf gefunden werden');
        $this->assertStringNotContainsString('Testinstanz', $this->get('/boerse')->body);
        foreach (['/boerse', '/boerse/sammlung-anbieten', '/boerse/danke'] as $pfad) {
            $html = $this->get($pfad, [], self::AN + ['testumgebung' => true])->body;
            $this->assertStringContainsString('Testinstanz – hier gibt es nichts zu kaufen, Anfragen werden nicht bearbeitet.', $html, $pfad);
            $this->assertStringContainsString('<meta name="robots" content="noindex">', $html, $pfad);
        }
    }

    public function test_vor_update_wartung(): void
    {
        $this->pdo->exec("DELETE FROM schema_migration WHERE version = '019_boerse_anfrage'");
        try {
            foreach (['/boerse', '/boerse/sammlung-anbieten'] as $pfad) {
                $r = $this->get($pfad);
                $this->assertSame(503, $r->status, $pfad);
                $this->assertStringNotContainsString('Abmelden', $r->body);
            }
            $this->assertSame(503, $this->post('/boerse/sammlung-anbieten', $this->anfrage(['wunsch' => 'spenden']))->status);
            $this->assertSame(404, $this->get('/boerse', [], [])->status, 'abgeschaltet bleibt 404');
        } finally {
            $this->pdo->exec("INSERT INTO schema_migration (version) VALUES ('019_boerse_anfrage')");
        }
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
    }

    public function test_kurzlink_ohne_anmeldung(): void
    {
        $frei = $this->nummer($this->carlasAngebot());
        $intern = $this->nummer($this->carlasAngebot('Interne Lok', false));
        $r = $this->get("/c/$frei");
        $this->assertSame(303, $r->status);
        $this->assertSame(url("/boerse/angebot/$frei"), $r->headers['Location']);
        $this->assertSame(url("/boerse/angebot/$frei"), $this->get('/c/' . strtolower($frei))->headers['Location']);
        // Nicht öffentlich oder unbekannt: wie bisher zur Anmeldung.
        foreach (["/c/$intern", '/c/B' . $this->jahr . '-999', '/c/F12'] as $pfad) {
            $this->assertStringStartsWith(url('/login'), $this->get($pfad)->headers['Location'], $pfad);
        }
        // Abgeschaltet: zur Anmeldung.
        $this->assertStringStartsWith(url('/login'), $this->get("/c/$frei", [], [])->headers['Location']);
        // Angemeldet: interne Angebotsseite.
        $this->sitzungAnmelden($this->sitzung, $this->wart->id);
        $this->assertStringStartsWith(url('/angebote/'), $this->get("/c/$frei")->headers['Location']);
    }

    public function test_aufraeumen_beim_seitenaufruf(): void
    {
        $this->pdo->prepare("INSERT INTO boerse_anfrage (art, name, email, nachricht, einwilligung_am, eingegangen_am) VALUES ('sammlung', 'Alt', 'alt@test.emc78.de', 'x', ?, ?)")
            ->execute([(new \DateTimeImmutable('-13 months'))->format('Y-m-d H:i:s'), (new \DateTimeImmutable('-13 months'))->format('Y-m-d H:i:s')]);
        $this->get('/boerse');
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
    }
}
