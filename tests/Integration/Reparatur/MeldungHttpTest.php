<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Reparatur;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\UploadService;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;

/** „Defekt melden“ am Handy für alle Mitglieder und „Meine Meldungen“. */
final class MeldungHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private int $spurweite;
    private string $speicher;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->spurweite = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    private function app(array $extra = []): App
    {
        $app = App::erzeuge(['speicher_pfad' => $this->speicher] + $extra + self::testConfig(), new Session($this->sitzung));
        // Testnaht: keine echten HTTP-Uploads.
        $app->container()->set(UploadService::class, fn() => new UploadService(
            $this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b)
        ));
        return $app;
    }

    /** @return int Benutzer-ID */
    private function anmelden(string $rolle, string $email = ''): int
    {
        $email = $email !== '' ? $email : $rolle . '@verein.de';
        $st = $this->pdo->prepare('SELECT id FROM benutzer WHERE email = ?');
        $st->execute([$email]);
        $id = (int) ($st->fetchColumn() ?: $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle));
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        return $id;
    }

    private function get(string $pfad, array $query = []): Response
    {
        return $this->app()->handle(new Request('GET', $pfad, $query));
    }

    private function post(string $pfad, array $daten = [], array $dateien = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)], $dateien));
    }

    private function fahrzeug(string $nummer = 'EMC-0012', string $bezeichnung = 'V 200', string $status = 'einsatzbereit'): int
    {
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, gattung, spurweite_id, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$nummer, $bezeichnung, 'lok', $this->spurweite, $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function anlage(int $nummer = 3, string $name = 'Clubanlage H0'): int
    {
        $this->pdo->prepare('INSERT INTO anlage (nummer, name, spurweite_id) VALUES (?, ?, ?)')->execute([$nummer, $name, $this->spurweite]);
        return (int) $this->pdo->lastInsertId();
    }

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function wert(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }

    /** @return ?array{typ:string,text:string,link_url?:string,link_text?:string} */
    private function meldung(string $typ): ?array
    {
        foreach ($this->sitzung['_meldungen'] ?? [] as $m) {
            if (($m['typ'] ?? null) === $typ) {
                return $m;
            }
        }
        return null;
    }

    /** @return array{name:array<int,string>,tmp_name:array<int,string>,error:array<int,int>,size:array<int,int>} */
    private function upload(string ...$fixtures): array
    {
        $f = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($fixtures as $fixture) {
            $tmp = tempnam(sys_get_temp_dir(), 'up');
            $this->temp[] = $tmp;
            copy(dirname(__DIR__, 2) . '/fixtures/dateien/' . $fixture, $tmp);
            $f['name'][] = $fixture;
            $f['tmp_name'][] = $tmp;
            $f['error'][] = UPLOAD_ERR_OK;
            $f['size'][] = (int) filesize($tmp);
        }
        return $f;
    }

    /** @return array<string,mixed> */
    private function letzteReparatur(): array
    {
        return $this->pdo->query('SELECT * FROM reparatur ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    public function test_formular_fuer_das_handy(): void
    {
        $fz = $this->fahrzeug();
        $this->anmelden('mitglied');
        $r = $this->get("/fahrzeuge/$fz/defekt");
        $this->assertSame(200, $r->status);
        $b = $r->body;
        $this->assertStringContainsString('F12', $b);
        $this->assertStringContainsString('V 200', $b);
        $this->assertStringContainsString('action="/fahrzeuge/' . $fz . '/defekt"', $b);
        $this->assertStringContainsString('enctype="multipart/form-data"', $b);
        $this->assertStringContainsString('Was ist kaputt?', $b);
        $this->assertStringContainsString('name="beschreibung"', $b);
        $this->assertStringContainsString('fährt nicht mehr', $b);
        $this->assertStringContainsString('fährt, aber …', $b);
        $this->assertMatchesRegularExpression('/<input[^>]*type="radio"[^>]*name="einstufung"[^>]*value="dringend"/', $b);
        $this->assertMatchesRegularExpression('/<input[^>]*type="radio"[^>]*name="einstufung"[^>]*value="normal"[^>]*checked/', $b);
        $this->assertMatchesRegularExpression('/<input[^>]*type="file"[^>]*name="fotos\[\]"[^>]*accept="image\/jpeg,image\/png,image\/webp,image\/heic,image\/heif"[^>]*multiple/', $b);
        // Daten für das Verkleinern im Browser und die Größenprüfung vor dem Absenden.
        $this->assertMatchesRegularExpression('/<input[^>]*type="file"[^>]*data-foto-feld[^>]*data-max-datei="\d+"[^>]*data-max-gesamt="\d+"/', $b);
        $this->assertStringContainsString('JPEG, PNG, WebP oder HEIC', $b);
        $this->assertMatchesRegularExpression('/höchstens \d+ MB je Foto/', $b);
        $this->assertStringContainsString('src="/assets/app.js"', $b);
        // Ohne capture bietet das Handy Kamera und Galerie an.
        $this->assertStringNotContainsString('capture', $b);
        $this->assertStringNotContainsString('name="wo_bauteil"', $b);
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*>\s*Defekt melden\s*</', $b);
    }

    public function test_mitglied_meldet_an_fremdem_fahrzeug(): void
    {
        $fz = $this->fahrzeug();
        $mitglied = $this->anmelden('mitglied');
        $r = $this->post("/fahrzeuge/$fz/defekt", ['beschreibung' => 'Licht vorne geht nicht', 'einstufung' => 'normal']);
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/$fz", $r->headers['Location']);
        $rep = $this->letzteReparatur();
        $this->assertSame('fahrzeug', $rep['objekt_typ']);
        $this->assertSame($fz, (int) $rep['objekt_id']);
        $this->assertSame('gemeldet', $rep['status']);
        $this->assertSame('normal', $rep['prioritaet']);
        $this->assertSame('Licht vorne geht nicht', $rep['beschreibung']);
        $this->assertNull($rep['wo_bauteil']);
        $this->assertSame($mitglied, (int) $rep['gemeldet_von_benutzer_id']);
        $erfolg = $this->meldung('erfolg');
        $this->assertSame('Danke! Dein Defekt wurde gemeldet.', $erfolg['text']);
        $this->assertSame('/meine-meldungen', $erfolg['link_url']);
        $this->assertSame('Meine Meldungen', $erfolg['link_text']);

        // Protokoll an der Reparatur und am Fahrzeug.
        $rid = (int) $rep['id'];
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'reparatur' AND objekt_id = $rid AND aktion = 'angelegt' AND benutzer_id = $mitglied"));
        $eintrag = json_decode((string) $this->wert("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(['alt' => null, 'neu' => 'Defekt gemeldet: Licht vorne geht nicht'], $eintrag['reparatur']);
    }

    public function test_protokoll_kuerzt_lange_beschreibung(): void
    {
        $fz = $this->fahrzeug();
        $this->anmelden('mitglied');
        $text = str_repeat('Ä', 100);
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/defekt", ['beschreibung' => $text])->status);
        $eintrag = json_decode((string) $this->wert("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame('Defekt gemeldet: ' . str_repeat('Ä', 79) . '…', $eintrag['reparatur']['neu']);
        $this->assertSame($text, $this->letzteReparatur()['beschreibung']);
    }

    public function test_dringend_setzt_defekt(): void
    {
        $dringend = $this->fahrzeug('EMC-0001', 'Lok A');
        $normal = $this->fahrzeug('EMC-0002', 'Lok B');
        $this->anmelden('mitglied');
        $this->assertSame(303, $this->post("/fahrzeuge/$dringend/defekt", ['beschreibung' => 'Bleibt stehen', 'einstufung' => 'dringend'])->status);
        $this->assertSame('dringend', $this->letzteReparatur()['prioritaet']);
        $this->assertSame('defekt', $this->wert("SELECT status FROM fahrzeug WHERE id = $dringend"));
        $this->assertSame(1, (int) $this->wert("SELECT status_automatisch FROM fahrzeug WHERE id = $dringend"));
        $this->assertSame(303, $this->post("/fahrzeuge/$normal/defekt", ['beschreibung' => 'Ruckelt etwas', 'einstufung' => 'normal'])->status);
        $this->assertSame('einsatzbereit', $this->wert("SELECT status FROM fahrzeug WHERE id = $normal"));
    }

    public function test_ausgeliehenes_fahrzeug_bleibt_ausgeliehen(): void
    {
        $fz = $this->fahrzeug('EMC-0001', 'Lok A', 'ausgeliehen');
        $this->anmelden('mitglied');
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/defekt", ['beschreibung' => 'Bleibt stehen', 'einstufung' => 'dringend'])->status);
        $this->assertSame('ausgeliehen', $this->wert("SELECT status FROM fahrzeug WHERE id = $fz"));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM reparatur'));
    }

    public function test_anlage_mit_wo_bauteil(): void
    {
        $anl = $this->anlage();
        $this->anmelden('mitglied');
        $form = $this->get("/anlagen/$anl/defekt");
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('A3', $form->body);
        $this->assertStringContainsString('Clubanlage H0', $form->body);
        $this->assertStringContainsString('name="wo_bauteil"', $form->body);
        $this->assertStringContainsString('placeholder="z. B. W12 Antrieb brummt"', $form->body);

        $r = $this->post("/anlagen/$anl/defekt", ['beschreibung' => 'Weiche schaltet nicht', 'wo_bauteil' => 'W12 Antrieb brummt', 'einstufung' => 'dringend']);
        $this->assertSame(303, $r->status);
        $this->assertSame("/anlagen/$anl", $r->headers['Location']);
        $rep = $this->letzteReparatur();
        $this->assertSame('anlage', $rep['objekt_typ']);
        $this->assertSame('W12 Antrieb brummt', $rep['wo_bauteil']);
        $this->assertSame('defekt', $this->wert("SELECT status FROM anlage WHERE id = $anl"));
    }

    public function test_foto_an_meldung(): void
    {
        $fz = $this->fahrzeug();
        $this->anmelden('mitglied');
        $r = $this->post("/fahrzeuge/$fz/defekt", ['beschreibung' => 'Puffer abgebrochen'], ['fotos' => $this->upload('foto_gedreht.jpg', 'anleitung.pdf')]);
        $this->assertSame(303, $r->status);
        $rid = (int) $this->letzteReparatur()['id'];
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'reparatur' AND objekt_id = $rid"));
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ <> 'reparatur'"));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertSame('anleitung.pdf: Nur Fotos (JPEG, PNG oder WebP) können an eine Meldung angehängt werden.', $this->meldung('warnung')['text']);
        $this->assertSame('Danke! Dein Defekt wurde gemeldet.', $this->meldung('erfolg')['text']);
        $dok = $this->zahl("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'reparatur'");

        // Das meldende Mitglied darf das Foto am fremden Fahrzeug nicht löschen.
        $this->assertSame(403, $this->post("/dateien/$dok/loeschen")->status);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM dokument'));

        // Der Fahrzeugwart schon; Protokoll am Fahrzeug, zurück zur Seite der Reparatur.
        $this->anmelden('fahrzeugwart');
        $r = $this->post("/dateien/$dok/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame("/reparaturen/$rid", $r->headers['Location']);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM reparatur'));
        $eintrag = json_decode((string) $this->wert("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(['alt' => 'foto_gedreht.jpg (Reparatur vom ' . date('d.m.Y') . ')', 'neu' => null], $eintrag['datei']);
    }

    public function test_foto_einer_anlagenmeldung_loescht_der_bearbeiter(): void
    {
        $anl = $this->anlage();
        $this->anmelden('fahrzeugwart');
        $this->assertSame(303, $this->post("/anlagen/$anl/defekt", ['beschreibung' => 'Gleis locker'], ['fotos' => $this->upload('foto_gedreht.jpg')])->status);
        $dok = $this->zahl("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'reparatur'");
        $this->assertSame(403, $this->post("/dateien/$dok/loeschen")->status);
        $this->anmelden('bearbeiter');
        $r = $this->post("/dateien/$dok/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame('/reparaturen/' . $this->letzteReparatur()['id'], $r->headers['Location']);
    }

    public function test_leere_beschreibung_422(): void
    {
        $anl = $this->anlage();
        $this->anmelden('mitglied');
        $r = $this->post("/anlagen/$anl/defekt", ['beschreibung' => '   ', 'wo_bauteil' => 'W12', 'einstufung' => 'dringend'], ['fotos' => $this->upload('foto_gedreht.jpg')]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte beschreibe den Defekt.', $r->body);
        $this->assertStringContainsString('value="W12"', $r->body);
        $this->assertMatchesRegularExpression('/value="dringend"[^>]*checked/', $r->body);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM reparatur'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertSame('in_ordnung', $this->wert("SELECT status FROM anlage WHERE id = $anl"));
    }

    public function test_unbekanntes_objekt_404(): void
    {
        $this->anmelden('mitglied');
        $this->assertSame(404, $this->get('/fahrzeuge/999/defekt')->status);
        $this->assertSame(404, $this->post('/fahrzeuge/999/defekt', ['beschreibung' => 'x'])->status);
        $this->assertSame(404, $this->get('/anlagen/999/defekt')->status);
        $this->assertSame(404, $this->get('/fahrzeuge/abc/defekt')->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM reparatur'));
    }

    public function test_ohne_anmeldung_zum_login(): void
    {
        $fz = $this->fahrzeug();
        $r = $this->get("/fahrzeuge/$fz/defekt");
        $this->assertSame(303, $r->status);
        $this->assertSame('/login?ziel=' . rawurlencode("/fahrzeuge/$fz/defekt"), $r->headers['Location']);
    }

    public function test_objekt_waehlen(): void
    {
        $this->anmelden('mitglied');
        $r = $this->get('/defekt');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Code, Digitaladresse oder Name', $r->body);
        $this->assertMatchesRegularExpression('/<form[^>]*action="\/code"/', $r->body);
        $this->assertStringContainsString('<input type="hidden" name="fuer" value="defekt">', $r->body);
        $this->assertStringContainsString('href="/meine-meldungen"', $r->body);
    }

    public function test_defekt_ueber_code(): void
    {
        $fz = $this->fahrzeug();
        $anl = $this->anlage();
        $this->anmelden('mitglied');
        $r = $this->get('/code', ['q' => 'F12', 'fuer' => 'defekt']);
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/$fz/defekt", $r->headers['Location']);
        $r = $this->get('/code', ['q' => 'a3', 'fuer' => 'defekt']);
        $this->assertSame("/anlagen/$anl/defekt", $r->headers['Location']);
        $this->assertSame('/defekt', $this->get('/code', ['q' => ' ', 'fuer' => 'defekt'])->headers['Location']);
        // Andere Werte von „fuer“ werden ignoriert.
        $r = $this->get('/code', ['q' => 'F12', 'fuer' => '/../loeschen']);
        $this->assertSame("/fahrzeuge/$fz", $r->headers['Location']);

        // Suchergebnis: Links führen zur Meldung, eine neue Suche behält den Zusammenhang.
        $r = $this->get('/code', ['q' => 'Club', 'fuer' => 'defekt']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('href="/anlagen/' . $anl . '/defekt"', $r->body);
        $this->assertStringContainsString('<input type="hidden" name="fuer" value="defekt">', $r->body);
        $r = $this->get('/code', ['q' => 'Club']);
        $this->assertStringContainsString('href="/anlagen/' . $anl . '"', $r->body);
        $this->assertStringNotContainsString('name="fuer"', $r->body);
    }

    public function test_meine_meldungen(): void
    {
        $fz = $this->fahrzeug();
        $anl = $this->anlage();
        $this->anmelden('mitglied', 'andere@verein.de');
        $this->post("/fahrzeuge/$fz/defekt", ['beschreibung' => 'Fremde Meldung']);

        $this->anmelden('mitglied');
        $leer = $this->get('/meine-meldungen');
        $this->assertSame(200, $leer->status);
        $this->assertStringContainsString('Du hast noch keine Defekte gemeldet.', $leer->body);

        $this->post("/fahrzeuge/$fz/defekt", ['beschreibung' => 'Licht vorne geht nicht']);
        $this->post("/anlagen/$anl/defekt", ['beschreibung' => 'Weiche schaltet nicht', 'wo_bauteil' => 'W12']);
        $this->pdo->exec("UPDATE reparatur SET status = 'in_arbeit' WHERE beschreibung = 'Weiche schaltet nicht'");
        $b = $this->get('/meine-meldungen')->body;
        $this->assertStringContainsString('Licht vorne geht nicht', $b);
        $this->assertStringContainsString('Weiche schaltet nicht', $b);
        $this->assertStringNotContainsString('Fremde Meldung', $b);
        $this->assertStringContainsString('F12', $b);
        $this->assertStringContainsString('V 200', $b);
        $this->assertStringContainsString('A3', $b);
        $this->assertStringContainsString('Clubanlage H0', $b);
        $this->assertStringContainsString('>gemeldet</span>', $b);
        $this->assertStringContainsString('>in Arbeit</span>', $b);
        // Jede Meldung führt zur Seite der Reparatur.
        foreach ($this->pdo->query('SELECT id FROM reparatur WHERE beschreibung <> \'Fremde Meldung\'')->fetchAll(\PDO::FETCH_COLUMN) as $rid) {
            $this->assertStringContainsString('href="/reparaturen/' . $rid . '"', $b);
        }
        // Neueste zuerst.
        $this->assertLessThan(strpos($b, 'Licht vorne geht nicht'), strpos($b, 'Weiche schaltet nicht'));
    }

    public function test_meine_meldungen_ohne_objekt(): void
    {
        $fz = $this->fahrzeug();
        $this->anmelden('mitglied');
        $this->post("/fahrzeuge/$fz/defekt", ['beschreibung' => 'Licht vorne geht nicht']);
        // Objekt inzwischen gelöscht (ohne Aufräumen): die Liste bricht nicht ab.
        $this->pdo->exec("DELETE FROM fahrzeug WHERE id = $fz");
        $r = $this->get('/meine-meldungen');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Licht vorne geht nicht', $r->body);
        $this->assertStringContainsString('gelöscht', $r->body);
    }

    public function test_schaltflaechen_defekt_melden(): void
    {
        $fz = $this->fahrzeug();
        $anl = $this->anlage();
        $this->anmelden('mitglied');
        foreach (["/fahrzeuge/$fz" => "/fahrzeuge/$fz/defekt", "/anlagen/$anl" => "/anlagen/$anl/defekt", '/fahrzeuge' => "/fahrzeuge/$fz/defekt", '/anlagen' => "/anlagen/$anl/defekt"] as $seite => $ziel) {
            $b = $this->get($seite)->body;
            $this->assertStringContainsString('href="' . $ziel . '"', $b, $seite);
            $this->assertStringContainsString('Defekt melden', $b, $seite);
        }
        // Menü: Hauptpunkt „Defekt melden“.
        $this->assertMatchesRegularExpression('/<a class="nav-link[^"]*" href="\/defekt">Defekt melden<\/a>/', $this->get('/')->body);
    }

    public function test_zu_grosse_anfrage_zeigt_das_formular_mit_hinweis(): void
    {
        $fz = $this->fahrzeug();
        $this->anmelden('mitglied');
        $r = $this->app(['post_max_bytes' => 8 * 1048576])->handle(new Request('POST', "/fahrzeuge/$fz/defekt", [], [], [], [
            'CONTENT_LENGTH' => (string) (9 * 1048576), 'HTTP_HOST' => 'verein.example',
        ]));
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/$fz/defekt", $r->headers['Location']);
        $fehler = $this->meldung('fehler')['text'];
        $this->assertStringContainsString('zusammen zu groß (höchstens 8 MB)', $fehler);
        $this->assertStringContainsString('Bitte weniger oder kleinere Fotos auswählen', $fehler);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM reparatur'));
        // Das Formular zeigt den Hinweis.
        $this->assertStringContainsString('Bitte weniger oder kleinere Fotos auswählen', $this->get("/fahrzeuge/$fz/defekt")->body);

        $anl = $this->anlage();
        $r = $this->app(['post_max_bytes' => 1048576])->handle(new Request('POST', "/anlagen/$anl/defekt", [], [], [], ['CONTENT_LENGTH' => '5000000']));
        $this->assertSame("/anlagen/$anl/defekt", $r->headers['Location']);
    }

    public function test_heic_ohne_umwandlung_ergibt_hinweis_auf_formate(): void
    {
        $fz = $this->fahrzeug();
        $this->anmelden('mitglied');
        $f = $this->upload('foto_gedreht.jpg');
        $f['name'][0] = 'IMG_0001.HEIC';
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/defekt", ['beschreibung' => 'Puffer ab'], ['fotos' => $f])->status);
        $this->assertSame('IMG_0001.HEIC: Nur Fotos (JPEG, PNG oder WebP) können an eine Meldung angehängt werden.', $this->meldung('warnung')['text']);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM reparatur'));
    }
}
