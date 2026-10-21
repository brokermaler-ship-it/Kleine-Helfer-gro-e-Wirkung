<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Magazin;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\UploadService;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;

/** Magazin über HTTP: Rechte (Börsenwart-Häkchen), Neuanlage mit Eingang, Buchungen, Liste, Code, Sammlungen, Kategorien. */
final class MagazinHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private string $speicher;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
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

    private function app(): App
    {
        $app = App::erzeuge(['speicher_pfad' => $this->speicher] + self::testConfig(), new Session($this->sitzung));
        $app->container()->set(UploadService::class, fn() => new UploadService(
            $this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b)
        ));
        return $app;
    }

    /** @return int Benutzer-ID */
    private function anmelden(string $rolle, bool $boersenwart = false, string $email = ''): int
    {
        $email = $email !== '' ? $email : $rolle . ($boersenwart ? '-bw' : '') . '@verein.de';
        $st = $this->pdo->prepare('SELECT id FROM benutzer WHERE email = ?');
        $st->execute([$email]);
        $id = (int) ($st->fetchColumn() ?: $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle));
        $this->pdo->prepare('UPDATE benutzer SET ist_boersenwart = ? WHERE id = ?')->execute([$boersenwart ? 1 : 0, $id]);
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

    private function kategorie(string $schluessel): int
    {
        return (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = '$schluessel'")->fetchColumn();
    }

    /** @return list<string> */
    private function meldungen(string $typ = 'erfolg'): array
    {
        $texte = [];
        foreach ($this->sitzung['_meldungen'] ?? [] as $m) {
            if ($m['typ'] === $typ) {
                $texte[] = $m['text'];
            }
        }
        return $texte;
    }

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /** Legt über die Oberfläche einen Artikel an (als Börsenwart) und liefert seine ID. */
    private function neuerArtikel(array $mehr = []): int
    {
        $r = $this->post('/magazin', $mehr + [
            'bezeichnung' => 'Märklin C-Gleis 24188 gerade',
            'kategorie_id' => (string) $this->kategorie('sonstiges'),
            'zustand' => 'gebraucht',
            'lagerort' => 'Regal 1',
            'menge' => '5',
            'herkunft' => 'spende',
            'freitext' => 'Spende von Familie Beispiel',
            'datum' => '',
            'betrag' => '',
        ]);
        $this->assertSame(303, $r->status, $r->body);
        return $this->zahl('SELECT MAX(id) FROM magazin_artikel');
    }

    private function ausgang(int $id, string $menge = '1'): Response
    {
        return $this->post("/magazin/$id/buchungen", ['art' => 'ausgang', 'menge' => $menge, 'ausgang_art' => 'verschrottet', 'datum' => '', 'freitext' => '', 'betrag' => '']);
    }

    public function test_rechte(): void
    {
        $this->anmelden('mitglied', true);
        $id = $this->neuerArtikel();

        $this->anmelden('mitglied');
        $liste = $this->get('/magazin');
        $this->assertSame(200, $liste->status);
        $this->assertStringContainsString('Märklin C-Gleis 24188 gerade', $liste->body);
        $this->assertStringNotContainsString('Artikel anlegen', $liste->body);
        $detail = $this->get("/magazin/$id");
        $this->assertSame(200, $detail->status);
        $this->assertStringContainsString('M' . $id, $detail->body);
        $this->assertStringNotContainsString("/magazin/$id/buchungen", $detail->body);
        $this->assertStringNotContainsString('Gegenbuchen', $detail->body);
        $this->assertSame(403, $this->ausgang($id)->status);
        $this->assertSame(403, $this->get('/magazin/neu')->status);
        $this->assertSame(403, $this->get("/magazin/$id/bearbeiten")->status);
        $this->assertSame(403, $this->post('/magazin', ['bezeichnung' => 'x'])->status);
        $this->assertSame(403, $this->post("/magazin/$id/fotos")->status);
        $this->assertSame(403, $this->post('/magazin/sammlungen', ['name' => 'x'])->status);
        $this->assertSame(403, $this->get('/admin/magazin-kategorien')->status);

        // Fahrzeugwart ohne Häkchen ist kein Börsenwart.
        $this->anmelden('fahrzeugwart');
        $this->assertSame(403, $this->ausgang($id)->status);
        $this->assertSame(5, $this->zahl("SELECT SUM(menge) FROM magazin_buchung WHERE artikel_id = $id"));

        // Mitglied mit Häkchen bucht.
        $this->anmelden('mitglied', true);
        $this->assertStringContainsString("/magazin/$id/buchungen", $this->get("/magazin/$id")->body);
        $r = $this->ausgang($id, '2');
        $this->assertSame(303, $r->status);
        $this->assertSame(url("/magazin/$id"), $r->headers['Location']);
        $this->assertContains('Die Buchung wurde gespeichert.', $this->meldungen());
        $this->assertSame(3, $this->zahl("SELECT SUM(menge) FROM magazin_buchung WHERE artikel_id = $id"));

        // Bearbeiter dürfen ohnehin alles, was ein Börsenwart darf.
        $this->anmelden('bearbeiter');
        $this->assertSame(303, $this->ausgang($id)->status);
        $this->assertSame(2, $this->zahl("SELECT SUM(menge) FROM magazin_buchung WHERE artikel_id = $id"));
    }

    public function test_neuanlage_literatur_und_verlauf(): void
    {
        $benutzer = $this->anmelden('mitglied', true);
        $this->pdo->exec("UPDATE person SET vorname = 'Greta', nachname = 'Gleisbau' WHERE id = (SELECT person_id FROM benutzer WHERE id = $benutzer)");
        $this->assertStringContainsString('nur bei Literatur', $this->get('/magazin/neu')->body);
        $r = $this->post('/magazin', [
            'bezeichnung' => 'Dampflokomotiven der Deutschen Bundesbahn',
            'kategorie_id' => (string) $this->kategorie('literatur'),
            'zustand' => 'neuwertig',
            'hersteller' => '',
            'artikelnummer' => '',
            'spurweite_id' => '',
            'lagerort' => 'Bücherregal',
            'notiz' => '',
            'autor_verlag' => 'Beispielverlag',
            'jahr' => '1987',
            'isbn' => '978-3-00-000000-0',
            'menge' => '3',
            'herkunft' => 'spende',
            'freitext' => 'Spende von Familie Beispiel',
            'datum' => '',
            'betrag' => '',
        ]);
        $id = $this->zahl('SELECT MAX(id) FROM magazin_artikel');
        $this->assertSame(303, $r->status, $r->body);
        $this->assertSame(url("/magazin/$id"), $r->headers['Location']);
        $this->assertContains('Der Artikel wurde als M' . $id . ' angelegt.', $this->meldungen());
        $a = $this->pdo->query("SELECT * FROM magazin_artikel WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('978-3-00-000000-0', $a['isbn']);
        $this->assertSame(1987, (int) $a['jahr']);
        $this->assertSame($benutzer, (int) $a['angelegt_von_benutzer_id']);

        $d = $this->get("/magazin/$id")->body;
        $this->assertStringContainsString('M' . $id, $d);
        $this->assertStringContainsString('data-bestand>3<', $d);
        $this->assertStringContainsString('Spende von Familie Beispiel', $d);
        $this->assertStringContainsString('Spende', $d);
        $this->assertStringContainsString('Greta Gleisbau', $d);
        $this->assertStringContainsString('Beispielverlag', $d);
        $this->assertStringContainsString('978-3-00-000000-0', $d);
        $this->assertStringContainsString('+3', $d);

        // Konto gelöscht: Buchungen bleiben, der Name nicht.
        $this->pdo->exec("DELETE FROM benutzer WHERE id = $benutzer");
        $this->anmelden('bearbeiter');
        $d = $this->get("/magazin/$id")->body;
        $this->assertStringNotContainsString('Greta Gleisbau', $d);
        $this->assertStringContainsString('ehemaliges Mitglied', $d);
    }

    public function test_literaturfelder_nur_bei_literatur(): void
    {
        $this->anmelden('mitglied', true);
        $id = $this->neuerArtikel(['isbn' => '978-3-00-000000-0', 'autor_verlag' => 'Verlag', 'jahr' => '1990']);
        $a = $this->pdo->query("SELECT isbn, autor_verlag, jahr FROM magazin_artikel WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['isbn' => null, 'autor_verlag' => null, 'jahr' => null], $a);
    }

    public function test_neuanlage_fehler(): void
    {
        $this->anmelden('mitglied', true);
        $r = $this->post('/magazin', ['bezeichnung' => '  ', 'kategorie_id' => '999', 'zustand' => 'kaputt', 'menge' => '0', 'herkunft' => '', 'betrag' => '1.234,50', 'lagerort' => 'Regal 9']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte eine Bezeichnung eingeben.', $r->body);
        $this->assertStringContainsString('Bitte eine Kategorie wählen.', $r->body);
        $this->assertStringContainsString('Bitte einen Zustand wählen.', $r->body);
        $this->assertStringContainsString('Bitte eine Menge ab 1 angeben.', $r->body);
        $this->assertStringContainsString('Bitte die Herkunft wählen.', $r->body);
        $this->assertStringContainsString('Bitte den Betrag angeben, z. B. 12,50.', $r->body);
        $this->assertStringContainsString('value="Regal 9"', $r->body);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
    }

    public function test_buchungen_ueber_die_oberflaeche(): void
    {
        $this->anmelden('mitglied', true);
        $id = $this->neuerArtikel();

        $r = $this->ausgang($id, '6');
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Der Bestand reicht nicht: verfügbar sind nur 5.', $r->body);
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM magazin_buchung WHERE artikel_id = $id"));

        $r = $this->post("/magazin/$id/buchungen", ['art' => 'ausgang', 'menge' => '1', 'ausgang_art' => 'verkauft_boerse']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte die Art des Ausgangs wählen.', $r->body);

        $r = $this->post("/magazin/$id/buchungen", ['art' => 'umlagerung', 'lagerort_neu' => 'Keller']);
        $this->assertSame(303, $r->status);
        $this->assertSame('Keller', $this->pdo->query("SELECT lagerort FROM magazin_artikel WHERE id = $id")->fetchColumn());

        $this->assertSame(303, $this->ausgang($id, '2')->status);
        $ausgang = $this->zahl("SELECT id FROM magazin_buchung WHERE artikel_id = $id AND art = 'ausgang'");
        $this->assertStringContainsString("/magazin/buchungen/$ausgang/gegenbuchung", $this->get("/magazin/$id")->body);
        $r = $this->post("/magazin/buchungen/$ausgang/gegenbuchung");
        $this->assertSame(303, $r->status);
        $this->assertSame(url("/magazin/$id"), $r->headers['Location']);
        $this->assertContains('Die Gegenbuchung wurde gespeichert.', $this->meldungen());
        $this->assertSame(5, $this->zahl("SELECT SUM(menge) FROM magazin_buchung WHERE artikel_id = $id"));
        $this->assertStringNotContainsString("/magazin/buchungen/$ausgang/gegenbuchung", $this->get("/magazin/$id")->body);

        $this->sitzung['_meldungen'] = [];
        $this->post("/magazin/buchungen/$ausgang/gegenbuchung");
        $this->assertContains('Diese Buchung lässt sich nicht gegenbuchen.', $this->meldungen('fehler'));
        $this->assertSame(404, $this->post('/magazin/buchungen/999999/gegenbuchung')->status);

        $this->anmelden('mitglied');
        $this->assertSame(403, $this->post("/magazin/buchungen/$ausgang/gegenbuchung")->status);
        $this->assertSame(403, $this->post("/magazin/999999/buchungen", ["art" => "eingang"])->status);
    }

    public function test_bearbeiten_mit_stand(): void
    {
        $this->anmelden('mitglied', true);
        $id = $this->neuerArtikel();
        $form = $this->get("/magazin/$id/bearbeiten");
        $this->assertSame(200, $form->status);
        $this->assertSame(1, preg_match('/name="stand" value="([^"]+)"/', $form->body, $m));
        $daten = [
            'stand' => html_entity_decode($m[1]),
            'bezeichnung' => 'Märklin C-Gleis 24188 gerade 180 mm',
            'kategorie_id' => (string) $this->kategorie('sonstiges'),
            'zustand' => 'neuwertig',
            'hersteller' => 'Märklin',
            'artikelnummer' => '24188',
            'spurweite_id' => '',
            'notiz' => '',
            'lagerort' => 'wird ignoriert',
        ];
        $r = $this->post("/magazin/$id", $daten);
        $this->assertSame(303, $r->status);
        $a = $this->pdo->query("SELECT * FROM magazin_artikel WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Märklin C-Gleis 24188 gerade 180 mm', $a['bezeichnung']);
        $this->assertSame('Märklin', $a['hersteller']);
        $this->assertSame('Regal 1', $a['lagerort']); // nur über eine Umlagerung
        $this->assertContains('Der Artikel wurde gespeichert.', $this->meldungen());

        $r = $this->post("/magazin/$id", ['bezeichnung' => 'Zweiter Stand'] + $daten);
        $this->assertSame(409, $r->status);
        $this->assertStringContainsString('inzwischen von jemand anderem geändert', $r->body);
        $this->assertStringContainsString('value="Zweiter Stand"', $r->body);
        $this->assertSame('Märklin C-Gleis 24188 gerade 180 mm', $this->pdo->query("SELECT bezeichnung FROM magazin_artikel WHERE id = $id")->fetchColumn());
        $this->assertStringContainsString('Märklin C-Gleis 24188 gerade 180 mm', $this->get("/magazin/$id")->body);
    }

    public function test_liste_mit_bestand_und_filter(): void
    {
        $this->anmelden('mitglied', true);
        $voll = $this->neuerArtikel(['bezeichnung' => 'Weiche links']);
        $leer = $this->neuerArtikel(['bezeichnung' => 'Trafo 6647', 'menge' => '1', 'lagerort' => 'Schrank']);
        $this->ausgang($leer, '1');

        $liste = $this->get('/magazin')->body;
        $this->assertStringContainsString('Weiche links', $liste);
        $this->assertStringNotContainsString('Trafo 6647', $liste);
        $alle = $this->get('/magazin', ['alle' => '1'])->body;
        $this->assertStringContainsString('Trafo 6647', $alle);
        $this->assertStringContainsString('M' . $leer, $alle);
        $this->assertStringNotContainsString('Weiche links', $this->get('/magazin', ['q' => 'Trafo', 'alle' => '1'])->body);
        $this->assertStringContainsString('Weiche links', $this->get('/magazin', ['q' => 'M' . $voll])->body);
        $this->assertStringNotContainsString('Weiche links', $this->get('/magazin', ['lagerort' => 'Schrank', 'alle' => '1'])->body);
        $this->assertStringNotContainsString('Weiche links', $this->get('/magazin', ['kategorie' => (string) $this->kategorie('literatur')])->body);
        // Merkwürdige Filterwerte führen nicht zu Fehlern.
        $this->assertSame(200, $this->get('/magazin', ['kategorie' => ['x'], 'spurweite' => 'abc', 'q' => ['y']])->status);
    }

    public function test_code_feld(): void
    {
        $this->anmelden('mitglied', true);
        $id = $this->neuerArtikel();
        $this->anmelden('mitglied');
        $r = $this->get('/code', ['q' => 'M' . $id]);
        $this->assertSame(303, $r->status);
        $this->assertSame(url("/magazin/$id"), $r->headers['Location']);
        $r = $this->get('/c/M' . $id);
        $this->assertSame(url("/magazin/$id"), $r->headers['Location']);
        $r = $this->get('/code', ['q' => 'M999999']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Kein Magazinartikel mit diesem Code.', $r->body);
    }

    public function test_keine_loeschroute(): void
    {
        $this->anmelden('admin');
        $this->anmelden('mitglied', true);
        $id = $this->neuerArtikel();
        $this->anmelden('admin');
        $this->assertSame(404, $this->post("/magazin/$id/loeschen")->status);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
        $this->assertSame(404, $this->get('/magazin/abc')->status);
        $this->assertSame(404, $this->get('/magazin/999999')->status);
    }

    public function test_kategorien_pflegen(): void
    {
        $this->anmelden('mitglied', true);
        $gleise = $this->zahl("SELECT id FROM magazin_kategorie WHERE name = 'Gleise und Weichen'");
        $this->neuerArtikel(['kategorie_id' => (string) $gleise]); // „Gleise und Weichen“ ist jetzt benutzt
        $this->anmelden('admin');
        $seite = $this->get('/admin/magazin-kategorien');
        $this->assertSame(200, $seite->status);
        $this->assertStringContainsString('Gleise und Weichen', $seite->body);

        $r = $this->post('/admin/magazin-kategorien', ['name' => 'Möbel', 'sortierung' => '95']);
        $this->assertSame(303, $r->status);
        $neu = $this->zahl("SELECT id FROM magazin_kategorie WHERE name = 'Möbel'");
        $this->assertGreaterThan(0, $neu);
        $r = $this->post('/admin/magazin-kategorien', ['id' => (string) $neu, 'name' => 'Möbel und Regale', 'sortierung' => '96']);
        $this->assertSame(303, $r->status);
        $this->assertSame('Möbel und Regale', $this->pdo->query("SELECT name FROM magazin_kategorie WHERE id = $neu")->fetchColumn());
        $r = $this->post('/admin/magazin-kategorien', ['name' => 'Literatur', 'sortierung' => '1']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Diese Kategorie gibt es bereits.', $r->body);
        $r = $this->post('/admin/magazin-kategorien', ['name' => '', 'sortierung' => 'x']);
        $this->assertSame(422, $r->status);

        // Benutzte Kategorie: nicht löschbar.
        $this->post("/admin/magazin-kategorien/$gleise/loeschen");
        $this->assertContains('Die Kategorie wird noch verwendet.', $this->meldungen('fehler'));
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM magazin_kategorie WHERE id = $gleise"));
        // Kategorie mit festem Schlüssel: nur umbenennen.
        $literatur = $this->kategorie('literatur');
        $this->sitzung['_meldungen'] = [];
        $this->post("/admin/magazin-kategorien/$literatur/loeschen");
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM magazin_kategorie WHERE id = $literatur"));
        $this->assertNotSame([], $this->meldungen('fehler'));
        $this->sitzung['_meldungen'] = [];
        $this->post("/admin/magazin-kategorien/$neu/loeschen");
        $this->assertContains('Die Kategorie wurde gelöscht.', $this->meldungen());
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM magazin_kategorie WHERE id = $neu"));
        $this->assertSame(404, $this->post('/admin/magazin-kategorien/999999/loeschen')->status);

        $this->anmelden('bearbeiter');
        $this->assertSame(403, $this->get('/admin/magazin-kategorien')->status);
    }

    public function test_sammlungen(): void
    {
        $this->anmelden('mitglied', true);
        $a = $this->neuerArtikel(['bezeichnung' => 'Gleise Kiste 1']);
        $b = $this->neuerArtikel(['bezeichnung' => 'Gleise Kiste 2']);
        $this->assertSame(200, $this->get('/magazin/sammlungen/neu')->status);
        $r = $this->post('/magazin/sammlungen', ['name' => 'Nachlass Märklin-Gleise, 3 Kisten', 'notiz' => 'drei Kisten', 'artikel' => [(string) $a, (string) $b]]);
        $this->assertSame(303, $r->status, $r->body);
        $s = $this->zahl('SELECT MAX(id) FROM magazin_sammlung');
        $this->assertSame(url("/magazin/sammlungen/$s"), $r->headers['Location']);
        $this->assertSame(2, $this->zahl("SELECT COUNT(*) FROM magazin_sammlung_artikel WHERE sammlung_id = $s"));

        $seite = $this->get("/magazin/sammlungen/$s")->body;
        $this->assertStringContainsString('Nachlass Märklin-Gleise, 3 Kisten', $seite);
        $this->assertStringContainsString('M' . $a, $seite);
        $this->assertStringContainsString('Gleise Kiste 2', $seite);
        $this->assertStringContainsString('Nachlass Märklin-Gleise, 3 Kisten', $this->get('/magazin/sammlungen')->body);
        $this->assertStringContainsString('Nachlass Märklin-Gleise, 3 Kisten', $this->get("/magazin/$a")->body);

        $r = $this->post("/magazin/sammlungen/$s/umlagern", ['lagerort' => 'Dachboden']);
        $this->assertSame(303, $r->status);
        $this->assertSame(2, $this->zahl("SELECT COUNT(*) FROM magazin_artikel WHERE lagerort = 'Dachboden'"));
        $r = $this->post("/magazin/sammlungen/$s/umlagern", ['lagerort' => ' ']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte den neuen Lagerort angeben.', $r->body);

        $form = $this->get("/magazin/sammlungen/$s", ['bearbeiten' => '1'])->body;
        $this->assertSame(1, preg_match('/name="stand" value="([^"]+)"/', $form, $m));
        $stand = html_entity_decode($m[1]);
        $r = $this->post("/magazin/sammlungen/$s", ['stand' => $stand, 'name' => 'Nachlass (Rest)', 'notiz' => '', 'artikel' => [(string) $a]]);
        $this->assertSame(303, $r->status);
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM magazin_sammlung_artikel WHERE sammlung_id = $s"));
        $r = $this->post("/magazin/sammlungen/$s", ['stand' => $stand, 'name' => 'Veraltet', 'artikel' => []]);
        $this->assertSame(409, $r->status);
        $r = $this->post('/magazin/sammlungen', ['name' => 'Zweite', 'artikel' => [(string) $a]]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('M' . $a . ' gehört schon zur Sammlung „Nachlass (Rest)“.', $r->body);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM magazin_sammlung'));

        $this->anmelden('mitglied');
        $this->assertSame(200, $this->get("/magazin/sammlungen/$s")->status);
        $this->assertSame(403, $this->post("/magazin/sammlungen/$s/umlagern", ['lagerort' => 'x'])->status);
        $this->assertSame(403, $this->post("/magazin/sammlungen/$s", ['name' => 'x'])->status);
        $this->assertSame(404, $this->get('/magazin/sammlungen/999999')->status);
    }

    public function test_fotos(): void
    {
        $this->anmelden('mitglied', true);
        $id = $this->neuerArtikel();
        $jpg = $this->bild();
        $txt = tempnam(sys_get_temp_dir(), 'emc');
        file_put_contents($txt, 'kein Bild');
        $this->temp[] = $txt;
        $r = $this->post("/magazin/$id/fotos", [], ['fotos' => [
            'name' => ['gleis.jpg', 'liste.txt'],
            'tmp_name' => [$jpg, $txt],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [filesize($jpg), filesize($txt)],
        ]]);
        $this->assertSame(303, $r->status);
        $dok = $this->zahl("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'magazin_artikel' AND objekt_id = $id");
        $this->assertGreaterThan(0, $dok);
        $this->assertSame($dok, $this->zahl("SELECT titelbild_dokument_id FROM magazin_artikel WHERE id = $id"));
        $this->assertContains('liste.txt: Nur Fotos (JPEG, PNG oder WebP) können an einen Magazinartikel angehängt werden.', $this->meldungen('warnung'));
        $this->assertStringContainsString("/dateien/$dok/vorschau", $this->get('/magazin')->body);

        // Mitglied ohne Häkchen darf das Foto nicht löschen; Börsenwart schon (Titelbild wird gelöst).
        $this->anmelden('mitglied');
        $this->assertSame(403, $this->post("/dateien/$dok/loeschen")->status);
        $this->anmelden('mitglied', true);
        $r = $this->post("/dateien/$dok/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame(url("/magazin/$id") . '#fotos', $r->headers['Location']);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertNull($this->pdo->query("SELECT titelbild_dokument_id FROM magazin_artikel WHERE id = $id")->fetchColumn() ?: null);
    }

    private function bild(): string
    {
        $pfad = tempnam(sys_get_temp_dir(), 'emc') . '.jpg';
        $img = imagecreatetruecolor(40, 30);
        imagejpeg($img, $pfad);
        $this->temp[] = $pfad;
        return $pfad;
    }

    public function test_menue_und_vor_dem_update(): void
    {
        $this->anmelden('mitglied');
        $start = $this->get('/')->body;
        $this->assertStringContainsString('href="' . url('/magazin') . '"', $start);
        $this->assertStringNotContainsString('Magazin-Kategorien', $start);
        $this->anmelden('admin');
        $this->assertStringContainsString('Magazin-Kategorien', $this->get('/')->body);

        // Steht ein Datenbank-Update aus, führt das Magazin zum Update statt zu einem Fehler.
        $this->pdo->exec("DELETE FROM schema_migration WHERE version = '016_magazin'");
        try {
            $r = $this->get('/magazin');
            $this->assertSame(303, $r->status);
            $this->assertSame(url('/admin/update'), $r->headers['Location']);
            $r = $this->get('/admin/magazin-kategorien');
            $this->assertSame(url('/admin/update'), $r->headers['Location']);
        } finally {
            $this->pdo->exec("INSERT INTO schema_migration (version) VALUES ('016_magazin')");
        }
    }
}
