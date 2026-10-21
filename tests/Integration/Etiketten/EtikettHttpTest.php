<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Etiketten;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Etiketten\EtikettAuswahl;
use Emc\Tests\Integration\DbTestCase;

/** Etiketten: Auswahl, Druckseite (Klebchen, Verpackung, Anlagen-QR) und „Als gedruckt vermerken“. */
final class EtikettHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private int $h0;
    private int $n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->n = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'N'")->fetchColumn();
    }

    private function anmelden(string $rolle = 'fahrzeugwart'): void
    {
        $id = $this->legeBenutzerAn($rolle . '@verein.de', 'geheim-passwort-1', $rolle);
        $this->sitzungAnmelden($this->sitzung, $id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    private function app(array $config = []): App
    {
        return App::erzeuge($config + self::testConfig(), new Session($this->sitzung));
    }

    private function get(string $pfad, array $query = [], array $config = [], array $server = []): Response
    {
        return $this->app($config)->handle(new Request('GET', $pfad, $query, [], [], $server));
    }

    private function post(string $pfad, array $daten): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)]));
    }

    private function fahrzeug(string $nummer, string $bezeichnung, string $gattung = 'lok', string $status = 'einsatzbereit', ?int $spur = null): int
    {
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, gattung, spurweite_id, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$nummer, $bezeichnung, $gattung, $spur ?? $this->h0, $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function anlage(int $nummer, string $name): int
    {
        $this->pdo->prepare('INSERT INTO anlage (nummer, name, spurweite_id) VALUES (?, ?, ?)')->execute([$nummer, $name, $this->h0]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<int> IDs der Felder mit Inhalt auf der Druckseite */
    private static function etikettenAnzahl(string $html): int
    {
        return substr_count($html, 'class="etikett ');
    }

    public function test_nur_ab_fahrzeugwart(): void
    {
        $this->anmelden('mitglied');
        $this->assertSame(403, $this->get('/etiketten')->status);
        $this->assertSame(403, $this->get('/etiketten/druck', ['art' => 'klebchen', 'auswahl' => 'ungedruckt'])->status);
        $this->assertSame(403, $this->post('/etiketten/gedruckt', ['typ' => 'fahrzeug', 'ids' => ['1']])->status);
        $seite = $this->get('/');
        $this->assertStringNotContainsString('href="/etiketten"', $seite->body);
    }

    public function test_fahrzeugwart_sieht_auswahl(): void
    {
        $this->fahrzeug('EMC-0012', 'V 200');
        $this->anmelden();
        $r = $this->get('/etiketten');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Klebchen', $r->body);
        $this->assertStringContainsString('Verpackung', $r->body);
        $this->assertStringContainsString('name="start"', $r->body);
        $this->assertStringContainsString('max="189"', $r->body);
        $this->assertStringContainsString('max="24"', $r->body);
        $this->assertStringContainsString('href="/etiketten"', $r->body); // Menü „Verwaltung › Etiketten“
    }

    public function test_klebchen_nur_code(): void
    {
        $fz = $this->fahrzeug('EMC-0012', 'V 200 Diesellok');
        $this->anmelden();
        $r = $this->get('/etiketten/druck', ['art' => 'klebchen', 'auswahl' => 'einzeln', 'ids' => [(string) $fz]]);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('>F12<', $r->body);
        $this->assertStringNotContainsString('V 200 Diesellok', $r->body);
        $this->assertStringContainsString('@page', $r->body . file_get_contents(dirname(__DIR__, 3) . '/public/assets/app.css'));
        $this->assertStringContainsString('Tatsächliche Größe', $r->body);
        $this->assertStringNotContainsString('id="hauptmenue"', $r->body); // eigenes Layout ohne Menü
        $this->assertSame(1, self::etikettenAnzahl($r->body));
        // Feld 1 an der richtigen Stelle (mm).
        $this->assertStringContainsString('left:8.48mm;top:13.5mm;width:25.4mm;height:10mm', $r->body);

        $r = $this->get('/etiketten/druck', ['art' => 'verpackung', 'auswahl' => 'einzeln', 'ids' => [(string) $fz]]);
        $this->assertStringContainsString('>F12<', $r->body);
        $this->assertStringContainsString('V 200 Diesellok', $r->body);
    }

    public function test_startposition_und_zweiter_bogen(): void
    {
        $ids = [];
        for ($i = 1; $i <= 10; $i++) {
            $ids[] = (string) $this->fahrzeug(sprintf('EMC-%04d', $i), 'Wagen ' . $i, 'wagen');
        }
        $this->anmelden();
        $r = $this->get('/etiketten/druck', ['art' => 'klebchen', 'auswahl' => 'einzeln', 'ids' => $ids, 'start' => '185']);
        $this->assertSame(2, substr_count($r->body, 'class="bogen"'));
        $this->assertSame(10, self::etikettenAnzahl($r->body));
        // F1 sitzt auf Feld 185 (Zeile 27, Spalte 3), F6 auf Feld 1 des zweiten Bogens.
        $this->assertMatchesRegularExpression('#left:64\.36mm;top:273\.5mm;[^"]*"[^>]*>\s*<span class="code">F1<#', $r->body);
        $r = $this->get('/etiketten/druck', ['art' => 'klebchen', 'auswahl' => 'einzeln', 'ids' => $ids, 'start' => '500']);
        $this->assertSame(1, substr_count($r->body, 'class="bogen"'));
    }

    public function test_ungedruckt_ohne_ausgemusterte_und_nur_post_vermerkt(): void
    {
        $aktiv = $this->fahrzeug('EMC-0001', 'Aktive Lok');
        $alt = $this->fahrzeug('EMC-0002', 'Alte Lok', 'lok', 'ausgemustert');
        $vorher = $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = $aktiv")->fetchColumn();
        $this->anmelden();
        $r = $this->get('/etiketten/druck', ['art' => 'klebchen', 'auswahl' => 'ungedruckt']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('>F1<', $r->body);
        $this->assertStringNotContainsString('>F2<', $r->body);
        $this->assertStringContainsString('action="/etiketten/gedruckt"', $r->body);
        $this->assertStringContainsString('name="ids[]" value="' . $aktiv . '"', $r->body);
        $this->assertStringContainsString('Als gedruckt vermerken', $r->body);
        $this->assertNull($this->pdo->query("SELECT etikett_gedruckt_am FROM fahrzeug WHERE id = $aktiv")->fetchColumn() ?: null);

        $r = $this->post('/etiketten/gedruckt', ['typ' => 'fahrzeug', 'ids' => [(string) $aktiv]]);
        $this->assertSame(303, $r->status);
        $this->assertSame('/etiketten', $r->headers['Location']);
        $this->assertNotNull($this->pdo->query("SELECT etikett_gedruckt_am FROM fahrzeug WHERE id = $aktiv")->fetchColumn() ?: null);
        $this->assertNull($this->pdo->query("SELECT etikett_gedruckt_am FROM fahrzeug WHERE id = $alt")->fetchColumn() ?: null);
        // Der Vermerk ist keine Bearbeitung: geaendert_am (Konfliktprüfung im Formular) bleibt.
        $this->assertSame($vorher, $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = $aktiv")->fetchColumn());
        $this->assertStringContainsString('1 Etikett als gedruckt vermerkt.', $this->get('/etiketten')->body);
        $this->assertSame([], (new EtikettAuswahl($this->pdo))->fahrzeuge('ungedruckt', [], null));

        $r = $this->get('/etiketten/druck', ['art' => 'klebchen', 'auswahl' => 'ungedruckt']);
        $this->assertSame(0, self::etikettenAnzahl($r->body));
        $this->assertStringContainsString('Keine Etiketten für diese Auswahl.', $r->body);
    }

    public function test_vermerk_mehrere_und_ungueltige_eingaben(): void
    {
        $a = $this->fahrzeug('EMC-0001', 'Lok A');
        $b = $this->fahrzeug('EMC-0002', 'Lok B');
        $anl = $this->anlage(3, 'Clubanlage');
        $this->anmelden();
        $r = $this->post('/etiketten/gedruckt', ['typ' => 'fahrzeug', 'ids' => [(string) $a, (string) $b, (string) $b, 'x', '99999']]);
        $this->assertSame(303, $r->status);
        $this->assertStringContainsString('2 Etiketten als gedruckt vermerkt.', $this->get('/etiketten')->body);
        $r = $this->post('/etiketten/gedruckt', ['typ' => 'anlage', 'ids' => [(string) $anl]]);
        $this->assertNotNull($this->pdo->query("SELECT etikett_gedruckt_am FROM anlage WHERE id = $anl")->fetchColumn() ?: null);
        $this->assertSame(404, $this->post('/etiketten/gedruckt', ['typ' => 'person', 'ids' => ['1']])->status);
    }

    public function test_loks_und_spurweite(): void
    {
        $this->fahrzeug('EMC-0001', 'Lok', 'lok');
        $this->fahrzeug('EMC-0002', 'Triebwagen', 'triebwagen');
        $this->fahrzeug('EMC-0003', 'Wagen', 'wagen');
        $this->fahrzeug('EMC-0004', 'N-Wagen', 'wagen', 'einsatzbereit', $this->n);
        $this->fahrzeug('EMC-0005', 'Alte Lok', 'lok', 'ausgemustert');
        $auswahl = new EtikettAuswahl($this->pdo);
        $this->assertSame(['EMC-0001', 'EMC-0002'], array_column($auswahl->fahrzeuge('loks', [], null), 'inventarnummer'));
        $this->assertSame(['EMC-0004'], array_column($auswahl->fahrzeuge('spurweite', [], $this->n), 'inventarnummer'));
        $this->assertSame([], $auswahl->fahrzeuge('spurweite', [], null));
        $this->assertSame(['EMC-0001', 'EMC-0002', 'EMC-0003', 'EMC-0004'], array_column($auswahl->fahrzeuge('ungedruckt', [], null), 'inventarnummer'));
        $einzeln = $auswahl->fahrzeuge('einzeln', [5, 1, 99], null);
        $this->assertSame(['EMC-0001', 'EMC-0005'], array_column($einzeln, 'inventarnummer'));
        $this->assertSame([], $auswahl->fahrzeuge('unbekannt', [1], null));

        $this->anmelden();
        $r = $this->get('/etiketten/druck', ['art' => 'klebchen', 'auswahl' => 'spurweite', 'spurweite_id' => (string) $this->n]);
        $this->assertStringContainsString('>F4<', $r->body);
        $this->assertSame(1, self::etikettenAnzahl($r->body));
    }

    public function test_anlagen_auswahl(): void
    {
        $a = $this->anlage(1, 'Clubanlage H0');
        $b = $this->anlage(2, 'Testkreis');
        $auswahl = new EtikettAuswahl($this->pdo);
        $this->assertSame([1, 2], array_map('intval', array_column($auswahl->anlagen('alle', []), 'nummer')));
        $this->assertSame([2], array_map('intval', array_column($auswahl->anlagen('einzeln', [$b]), 'nummer')));
        $this->assertSame(1, $auswahl->vermerkeGedruckt('anlage', [$a]));
        $this->assertSame([2], array_map('intval', array_column($auswahl->anlagen('ungedruckt', []), 'nummer')));
    }

    public function test_anlage_qr_mit_kurzlink(): void
    {
        $anl = $this->anlage(1, 'Clubanlage H0');
        $this->anmelden();
        $config = ['oeffentliche_url' => 'https://verein.example/'];
        $r = $this->get('/etiketten/druck', ['art' => 'anlage', 'auswahl' => 'einzeln', 'ids' => [(string) $anl]], $config);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('<svg', $r->body);
        $this->assertStringNotContainsString('<?xml', $r->body);
        $this->assertStringContainsString('Clubanlage H0', $r->body);
        $this->assertStringContainsString('>A1<', $r->body);
        $this->assertStringContainsString('aria-label="https://verein.example/c/A1"', $r->body);
        $this->assertStringContainsString('left:0mm;top:0.5mm;width:70mm;height:37mm', $r->body);
        $this->assertStringNotContainsString('oeffentliche_url', $this->get('/etiketten', [], $config)->body);

        // Mit Basis-URL (Unterverzeichnis).
        $this->sitzung[\Emc\Auth\Auth::INSTANZ_KEY] = \Emc\Core\Instanz::fingerabdruck(['basis_url' => '/verwaltung'] + self::testConfig());
        $r = $this->get('/etiketten/druck', ['art' => 'anlage', 'auswahl' => 'alle'], $config + ['basis_url' => '/verwaltung']);
        $this->assertStringContainsString('aria-label="https://verein.example/verwaltung/c/A1"', $r->body);
    }

    public function test_ohne_oeffentliche_url_host_der_anfrage_und_warnung(): void
    {
        $this->anlage(1, 'Clubanlage H0');
        $this->anmelden();
        $r = $this->get('/etiketten/druck', ['art' => 'anlage', 'auswahl' => 'alle'], [], ['HTTP_HOST' => 'lokal.test:8080']);
        $this->assertStringContainsString('aria-label="http://lokal.test:8080/c/A1"', $r->body);
        $this->assertStringContainsString('Bitte oeffentliche_url in der config.php eintragen, damit die QR-Codes auch später stimmen.', $r->body);
        $r = $this->get('/etiketten/druck', ['art' => 'anlage', 'auswahl' => 'alle'], [], ['HTTP_HOST' => 'lokal.test', 'HTTPS' => 'on']);
        $this->assertStringContainsString('aria-label="https://lokal.test/c/A1"', $r->body);
        // Unbrauchbarer Host-Header wird nicht übernommen.
        $r = $this->get('/etiketten/druck', ['art' => 'anlage', 'auswahl' => 'alle'], [], ['HTTP_HOST' => 'böse"><script>']);
        $this->assertStringContainsString('aria-label="http://localhost/c/A1"', $r->body);
        $this->assertStringContainsString(
            'Bitte oeffentliche_url in der config.php eintragen, damit die QR-Codes auch später stimmen.',
            $this->get('/etiketten')->body
        );
    }

    public function test_fahrzeug_ohne_code_wird_uebersprungen(): void
    {
        $this->fahrzeug('ALT-7', 'Museumslok');
        $this->fahrzeug('EMC-0003', 'Neue Lok');
        $this->anmelden();
        $r = $this->get('/etiketten/druck', ['art' => 'klebchen', 'auswahl' => 'loks']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('ALT-7', $r->body);
        $this->assertStringContainsString('ohne Code', $r->body);
        $this->assertSame(1, self::etikettenAnzahl($r->body));
        $this->assertStringContainsString('>F3<', $r->body);
    }

    public function test_link_etikett_drucken_auf_detailseiten(): void
    {
        $fz = $this->fahrzeug('EMC-0012', 'V 200');
        $anl = $this->anlage(3, 'Clubanlage');
        $this->anmelden();
        $this->assertStringContainsString('href="/etiketten?art=klebchen&amp;auswahl=einzeln&amp;ids%5B0%5D=' . $fz . '"', $this->get('/fahrzeuge/' . $fz)->body);
        $this->assertStringContainsString('href="/etiketten?art=anlage&amp;auswahl=einzeln&amp;ids%5B0%5D=' . $anl . '"', $this->get('/anlagen/' . $anl)->body);
        $r = $this->get('/etiketten', ['art' => 'klebchen', 'auswahl' => 'einzeln', 'ids' => [(string) $fz]]);
        $this->assertStringContainsString('V 200', $r->body);
        $this->assertStringContainsString('name="ids[]" value="' . $fz . '"', $r->body);

        $this->sitzung = [];
        $this->anmelden('mitglied');
        $this->assertStringNotContainsString('/etiketten?', $this->get('/fahrzeuge/' . $fz)->body);
    }
}
