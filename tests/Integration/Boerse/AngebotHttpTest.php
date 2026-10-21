<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Boerse;

use Emc\Boerse\AngebotController;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\UploadService;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;

/** Börse über HTTP: eigene Angebote, Rechte, Freigabe, Vereinsangebot aus dem Magazin, Börsen, Preisschilder, Gesamtliste. */
final class AngebotHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private string $speicher;
    /** @var list<string> */
    private array $temp = [];
    private string $jahr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
        $this->jahr = date('Y');
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    private function app(array $config = []): App
    {
        $app = App::erzeuge($config + ['speicher_pfad' => $this->speicher] + self::testConfig(), new Session($this->sitzung));
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

    private function person(int $benutzerId): int
    {
        return $this->zahl("SELECT person_id FROM benutzer WHERE id = $benutzerId");
    }

    private function get(string $pfad, array $query = [], array $config = []): Response
    {
        return $this->app($config)->handle(new Request('GET', $pfad, $query, [], [], ['HTTP_HOST' => 'verein.example']));
    }

    private function post(string $pfad, array $daten = [], array $dateien = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)], $dateien));
    }

    private function kategorie(string $schluessel = 'sonstiges'): int
    {
        return (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = '$schluessel'")->fetchColumn();
    }

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
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

    /** @return array<string,string> */
    private function formular(array $mehr = []): array
    {
        return $mehr + [
            'bezeichnung' => 'Märklin BR 50 mit Tender', 'beschreibung' => 'Gut erhalten, OVP', 'kategorie_id' => (string) $this->kategorie(),
            'spurweite_id' => '', 'zustand' => 'gebraucht', 'wunschpreis' => '12,50', 'menge' => '1', 'boerse_id' => 'dauerhaft', 'notiz' => '',
        ];
    }

    /** Legt über die Oberfläche ein Angebot an und liefert seine ID. */
    private function neuesAngebot(array $mehr = []): int
    {
        $r = $this->post('/angebote', $this->formular($mehr));
        $this->assertSame(303, $r->status, $r->body);
        return $this->zahl('SELECT MAX(id) FROM boerse_angebot');
    }

    /** @return array<string,mixed> */
    private function zeile(int $id): array
    {
        return $this->pdo->query("SELECT * FROM boerse_angebot WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
    }

    private function bild(): string
    {
        $pfad = tempnam(sys_get_temp_dir(), 'emc') . '.jpg';
        $img = imagecreatetruecolor(40, 30);
        imagejpeg($img, $pfad);
        $this->temp[] = $pfad;
        return $pfad;
    }

    /** @return array<string,array<string,mixed>> */
    private function foto(): array
    {
        $jpg = $this->bild();
        return ['fotos' => ['name' => ['lok.jpg'], 'tmp_name' => [$jpg], 'error' => [UPLOAD_ERR_OK], 'size' => [filesize($jpg)]]];
    }

    public function test_mitglied_bietet_eigenes_an(): void
    {
        $andere = $this->anmelden('mitglied', false, 'eva@verein.de');
        $carla = $this->anmelden('mitglied', false, 'carla@verein.de');
        $id = $this->neuesAngebot(['herkunft' => 'verein', 'person_id' => (string) $this->person($andere), 'erloes_spende' => '1', 'veroeffentlichen' => '1']);
        $z = $this->zeile($id);
        $this->assertSame('mitglied', $z['herkunft'], 'Herkunft erzwungen');
        $this->assertSame($this->person($carla), (int) $z['person_id'], 'eigene Person');
        $this->assertSame('1', (string) $z['erloes_spende']);
        $this->assertSame('vorgeschlagen', $z['oeffentlich']);
        $this->assertSame('12.50', $z['wunschpreis']);
        $this->assertSame(['Das Angebot wurde als B' . $this->jahr . '-1 angelegt.'], $this->meldungen());

        $seite = $this->get("/angebote/$id");
        $this->assertSame(200, $seite->status);
        $this->assertStringContainsString('B' . $this->jahr . '-1', $seite->body);
        $this->assertStringContainsString('12,50 €', $seite->body);
        $this->assertStringContainsString('zur Freigabe vorgeschlagen', $seite->body);
        $this->assertStringNotContainsString('value="freigegeben"', $seite->body, 'Mitglied sieht keine Freigabe');
        $this->assertSame(403, $this->post("/angebote/$id/oeffentlich", ['nach' => 'freigegeben'])->status);
        $this->assertSame('vorgeschlagen', $this->zeile($id)['oeffentlich']);

        $meine = $this->get('/angebote', ['meine' => '1']);
        $this->assertStringContainsString('Märklin BR 50 mit Tender', $meine->body);
        $this->assertStringContainsString('Mitglied (du)', $meine->body);

        // Ein anderes Mitglied sieht das Angebot, aber nicht den Namen und kann es nicht ändern.
        $this->pdo->exec('UPDATE person SET vorname = \'Carla\', nachname = \'Mustermann\' WHERE id = ' . $this->person($carla));
        $this->anmelden('mitglied', false, 'eva@verein.de');
        $fremd = $this->get("/angebote/$id");
        $this->assertSame(200, $fremd->status);
        $this->assertStringNotContainsString('Mustermann', $fremd->body);
        $this->assertStringNotContainsString('Mustermann', $this->get('/angebote')->body);
        $this->assertSame(403, $this->get("/angebote/$id/bearbeiten")->status);
        $this->assertSame(403, $this->post("/angebote/$id", $this->formular(['stand' => (string) $this->zeile($id)['geaendert_am']]))->status);
        $this->assertSame(403, $this->post("/angebote/$id/oeffentlich", ['nach' => 'nein'])->status);
        $this->assertSame(403, $this->post("/angebote/$id/status", ['aktion' => 'zurueckziehen'])->status);
        $this->assertSame(403, $this->post("/angebote/$id/fotos", [], $this->foto())->status);
        $this->assertSame('angeboten', $this->zeile($id)['status']);

        // Der Börsenwart sieht den Namen.
        $this->anmelden('mitglied', true);
        $this->assertStringContainsString('Carla Mustermann', $this->get("/angebote/$id")->body);
    }

    public function test_freigabe_rechte_und_rueckfall(): void
    {
        $this->anmelden('mitglied', false, 'carla@verein.de');
        $id = $this->neuesAngebot();
        $this->assertSame(303, $this->post("/angebote/$id/oeffentlich", ['nach' => 'vorgeschlagen'])->status);
        $this->assertSame('vorgeschlagen', $this->zeile($id)['oeffentlich']);

        $this->anmelden('fahrzeugwart');
        $this->assertSame(403, $this->post("/angebote/$id/oeffentlich", ['nach' => 'freigegeben'])->status, 'Fahrzeugwart ohne Häkchen');
        $this->anmelden('bearbeiter');
        $this->assertSame(303, $this->post("/angebote/$id/oeffentlich", ['nach' => 'freigegeben'])->status);
        $this->assertSame('freigegeben', $this->zeile($id)['oeffentlich']);

        // Mitglied ändert den Preis: Rückfall mit Meldung.
        $this->anmelden('mitglied', false, 'carla@verein.de');
        $r = $this->post("/angebote/$id", $this->formular(['wunschpreis' => '15', 'stand' => (string) $this->zeile($id)['geaendert_am']]));
        $this->assertSame(303, $r->status, $r->body);
        $this->assertSame('vorgeschlagen', $this->zeile($id)['oeffentlich']);
        $this->assertSame([AngebotController::RUECKFALL], $this->meldungen('warnung'));
        $this->assertSame('15.00', $this->zeile($id)['wunschpreis']);

        // Börsenwart gibt frei, Mitglied lädt ein Foto hoch: wieder Rückfall.
        $this->anmelden('mitglied', true);
        $this->post("/angebote/$id/oeffentlich", ['nach' => 'freigegeben']);
        $this->anmelden('mitglied', false, 'carla@verein.de');
        $this->assertSame(303, $this->post("/angebote/$id/fotos", [], $this->foto())->status);
        $dok = $this->zahl("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'boerse_angebot' AND objekt_id = $id");
        $this->assertGreaterThan(0, $dok);
        $this->assertSame($dok, (int) $this->zeile($id)['titelbild_dokument_id']);
        $this->assertSame('vorgeschlagen', $this->zeile($id)['oeffentlich']);

        // Foto löschen durch das Mitglied (eigenes Angebot) nach erneuter Freigabe: Rückfall.
        $this->anmelden('mitglied', true);
        $this->post("/angebote/$id/oeffentlich", ['nach' => 'freigegeben']);
        $this->anmelden('mitglied', false, 'eva@verein.de');
        $this->assertSame(403, $this->post("/dateien/$dok/loeschen")->status, 'fremdes Angebot');
        $this->anmelden('mitglied', false, 'carla@verein.de');
        $r = $this->post("/dateien/$dok/loeschen", ['angebot_id' => (string) $id]);
        $this->assertSame(303, $r->status);
        $this->assertSame(url("/angebote/$id") . '#fotos', $r->headers['Location']);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertSame('vorgeschlagen', $this->zeile($id)['oeffentlich']);
        $this->assertNull($this->zeile($id)['titelbild_dokument_id']);

        // Zurückgezogenes Angebot ändert das Mitglied nicht mehr.
        $this->post("/angebote/$id/status", ['aktion' => 'zurueckziehen']);
        $this->assertSame('zurueck', $this->zeile($id)['status']);
        $this->assertSame(403, $this->get("/angebote/$id/bearbeiten")->status);
    }

    public function test_vereinsangebot_aus_dem_magazin(): void
    {
        $this->anmelden('mitglied', true);
        $r = $this->post('/magazin', [
            'bezeichnung' => 'Fahrpult 6647', 'kategorie_id' => (string) $this->kategorie(), 'zustand' => 'defekt', 'lagerort' => 'Regal 7',
            'menge' => '2', 'herkunft' => 'spende', 'freitext' => '', 'datum' => '', 'betrag' => '',
        ]);
        $this->assertSame(303, $r->status);
        $artikel = $this->zahl('SELECT MAX(id) FROM magazin_artikel');
        $this->post("/magazin/$artikel/fotos", [], $this->foto());
        $foto = $this->zahl("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'magazin_artikel'");

        $this->assertStringContainsString('/angebote/neu?magazin=' . $artikel, $this->get("/magazin/$artikel")->body);
        $neu = $this->get('/angebote/neu', ['magazin' => (string) $artikel]);
        $this->assertSame(200, $neu->status);
        $this->assertStringContainsString('value="Fahrpult 6647"', $neu->body);
        $this->assertStringContainsString('name="magazin_artikel_id" value="' . $artikel . '"', $neu->body);
        $this->assertStringContainsString('frei: 2 Stück', $neu->body);

        $r = $this->post('/angebote', $this->formular(['bezeichnung' => 'Fahrpult 6647', 'magazin_artikel_id' => (string) $artikel, 'menge' => '2', 'herkunft' => 'mitglied']));
        $this->assertSame(303, $r->status, $r->body);
        $id = $this->zahl('SELECT MAX(id) FROM boerse_angebot');
        $z = $this->zeile($id);
        $this->assertSame(['verein', null, $artikel, 2], [$z['herkunft'], $z['person_id'], (int) $z['magazin_artikel_id'], (int) $z['menge']]);
        $this->assertSame($foto, (int) $z['titelbild_dokument_id']);
        $seite = $this->get("/angebote/$id");
        $this->assertStringContainsString('M' . $artikel, $seite->body);
        $this->assertStringContainsString('Vom Angebot entfernen', $seite->body, 'geteiltes Foto');

        // Geteiltes Foto nur vom Angebot lösen: am Artikel bleibt es.
        $r = $this->post("/dateien/$foto/loeschen", ['angebot_id' => (string) $id]);
        $this->assertSame(303, $r->status);
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'boerse_angebot'"));
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'magazin_artikel'"));
        $this->assertNull($this->zeile($id)['titelbild_dokument_id']);

        // Kein zweites Angebot über den Bestand.
        $r = $this->post('/angebote', $this->formular(['magazin_artikel_id' => (string) $artikel]));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Im Magazin sind nur noch 0 Stück frei.', $r->body);

        // Ausgang von Hand über die angebotene Menge: abgelehnt.
        $r = $this->post("/magazin/$artikel/buchungen", ['art' => 'ausgang', 'menge' => '1', 'ausgang_art' => 'verschrottet', 'datum' => '', 'freitext' => '', 'betrag' => '']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('2 Stück sind in der Börse angeboten.', $r->body);

        // Mitglied (ohne Häkchen): kein Vereinsangebot.
        $this->anmelden('mitglied');
        $this->assertSame(403, $this->get('/angebote/neu', ['magazin' => (string) $artikel])->status);
        $this->assertSame(403, $this->post('/angebote', $this->formular(['magazin_artikel_id' => (string) $artikel]))->status);
        $this->assertStringNotContainsString('In die Börse übernehmen', $this->get("/magazin/$artikel")->body);
    }

    /** Löschregeln für Fotos: geteiltes Foto (Magazin + Angebot) löscht der Börsenwart ganz; verkaufte Angebote behalten ihre Fotos. */
    public function test_foto_loeschregeln(): void
    {
        $this->anmelden('mitglied', true);
        $this->post('/magazin', ['bezeichnung' => 'Fahrpult', 'kategorie_id' => (string) $this->kategorie(), 'zustand' => 'gebraucht', 'lagerort' => '', 'menge' => '1', 'herkunft' => 'spende', 'freitext' => '', 'datum' => '', 'betrag' => '']);
        $artikel = $this->zahl('SELECT MAX(id) FROM magazin_artikel');
        $this->post("/magazin/$artikel/fotos", [], $this->foto());
        $foto = $this->zahl('SELECT MAX(id) FROM dokument');
        $angebot = $this->neuesAngebot(['magazin_artikel_id' => (string) $artikel]);
        $this->assertSame(2, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE dokument_id = $foto"));

        $this->anmelden('mitglied');
        $this->assertSame(403, $this->post("/dateien/$foto/loeschen")->status);
        $this->anmelden('fahrzeugwart');
        $this->assertSame(403, $this->post("/dateien/$foto/loeschen")->status);
        $this->anmelden('mitglied', true);
        $r = $this->post("/dateien/$foto/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertNull($this->zeile($angebot)['titelbild_dokument_id']);

        // Verkauftes Angebot: Foto bleibt, auch für den Börsenwart.
        $this->post("/angebote/$angebot/fotos", [], $this->foto());
        $foto = $this->zahl('SELECT MAX(id) FROM dokument');
        $this->pdo->exec("UPDATE boerse_angebot SET status = 'verkauft' WHERE id = $angebot");
        $r = $this->post("/dateien/$foto/loeschen", ['angebot_id' => (string) $angebot]);
        $this->assertSame(303, $r->status);
        $this->assertContains('Verkaufte Angebote lassen sich nicht mehr ändern.', $this->meldungen('fehler'));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertSame(303, $this->post("/angebote/$angebot/fotos", [], $this->foto())->status);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM dokument'), 'kein Upload an verkaufte Angebote');
    }

    public function test_sammlung_in_die_boerse(): void
    {
        $this->anmelden('mitglied', true);
        foreach (['Gleis gerade', 'Gleis gebogen'] as $name) {
            $this->post('/magazin', ['bezeichnung' => $name, 'kategorie_id' => (string) $this->kategorie(), 'zustand' => 'gebraucht', 'lagerort' => '', 'menge' => '3', 'herkunft' => 'nachlass', 'freitext' => '', 'datum' => '', 'betrag' => '']);
        }
        $ids = array_map('intval', $this->pdo->query('SELECT id FROM magazin_artikel ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        $this->post('/magazin/sammlungen', ['name' => 'Nachlass Märklin-Gleise, 3 Kisten', 'notiz' => 'drei Kisten', 'artikel' => array_map('strval', $ids)]);
        $sammlung = $this->zahl('SELECT MAX(id) FROM magazin_sammlung');
        $this->assertStringContainsString('/angebote/neu?sammlung=' . $sammlung, $this->get("/magazin/sammlungen/$sammlung")->body);
        $neu = $this->get('/angebote/neu', ['sammlung' => (string) $sammlung]);
        $this->assertStringContainsString('value="Nachlass Märklin-Gleise, 3 Kisten"', $neu->body);
        $this->assertStringContainsString('wird als Ganzes verkauft', $neu->body);
        $r = $this->post('/angebote', $this->formular(['bezeichnung' => 'Nachlass Märklin-Gleise', 'magazin_sammlung_id' => (string) $sammlung, 'menge' => '7']));
        $this->assertSame(303, $r->status, $r->body);
        $z = $this->zeile($this->zahl('SELECT MAX(id) FROM boerse_angebot'));
        $this->assertSame([$sammlung, 1, 'verein'], [(int) $z['magazin_sammlung_id'], (int) $z['menge'], $z['herkunft']]);
        $r = $this->post('/angebote', $this->formular(['magazin_sammlung_id' => (string) $sammlung]));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Die Sammlung ist schon im Angebot.', $r->body);
    }

    public function test_status_von_hand(): void
    {
        $this->anmelden('mitglied', false, 'carla@verein.de');
        $id = $this->neuesAngebot();
        $this->assertSame(403, $this->post("/angebote/$id/status", ['aktion' => 'reservieren'])->status);
        $this->anmelden('mitglied', true);
        $r = $this->post("/angebote/$id/status", ['aktion' => 'verkauft']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Diese Aktion gibt es nicht.', $r->body);
        $this->assertSame('angeboten', $this->zeile($id)['status']);
        $this->post("/angebote/$id/status", ['aktion' => 'reservieren']);
        $this->assertSame('reserviert', $this->zeile($id)['status']);
        $this->assertSame(['Das Angebot ist reserviert.'], $this->meldungen());
        $this->post("/angebote/$id/status", ['aktion' => 'reservieren']);
        $this->assertSame(['Nur angebotene Stücke lassen sich reservieren.'], $this->meldungen('fehler'));
        $this->post("/angebote/$id/status", ['aktion' => 'zurueckziehen']);
        $this->post("/angebote/$id/status", ['aktion' => 'wieder_anbieten', 'boerse_id' => 'dauerhaft']);
        $this->assertSame('angeboten', $this->zeile($id)['status']);
        $this->assertSame(404, $this->post('/angebote/99999/status', ['aktion' => 'reservieren'])->status);
    }

    public function test_code_und_kurzlink(): void
    {
        $this->anmelden('mitglied', false, 'carla@verein.de');
        $id = $this->neuesAngebot();
        $r = $this->get('/code', ['q' => 'b' . $this->jahr . '-0001']);
        $this->assertSame(303, $r->status);
        $this->assertSame(url('/angebote/' . $id), $r->headers['Location']);
        $r = $this->get('/c/B' . $this->jahr . '-1');
        $this->assertSame(url('/angebote/' . $id), $r->headers['Location']);
        $r = $this->get('/code', ['q' => 'B' . $this->jahr . '-77']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Kein Angebot mit dieser Nummer.', $r->body);
    }

    public function test_boersen(): void
    {
        $this->anmelden('mitglied');
        $this->assertSame(403, $this->get('/boersen/neu')->status);
        $this->assertSame(403, $this->post('/boersen', ['name' => 'X', 'datum' => '2026-11-08'])->status);
        $this->assertSame(200, $this->get('/boersen')->status);

        $this->anmelden('mitglied', true);
        $r = $this->post('/boersen', ['name' => 'Herbstbörse', 'datum' => '2026-11-08', 'ort' => 'Vereinsheim', 'provision_prozent' => '100,5']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte eine Provision zwischen 0 und 100 % angeben.', $r->body);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse'));
        $r = $this->post('/boersen', ['name' => 'Herbstbörse', 'datum' => '2026-11-08', 'ort' => 'Vereinsheim', 'provision_prozent' => '7,5']);
        $this->assertSame(303, $r->status, $r->body);
        $b = $this->pdo->query('SELECT * FROM boerse')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['7.50', 'in_vorbereitung', '2026-11-08'], [$b['provision_prozent'], $b['status'], $b['datum']]);
        $id = (int) $b['id'];
        $this->post('/boersen', ['name' => 'Frühjahrsbörse', 'datum' => '2026-03-01', 'ort' => '', 'provision_prozent' => '']);
        $this->assertSame('10.00', $this->pdo->query("SELECT provision_prozent FROM boerse WHERE name = 'Frühjahrsbörse'")->fetchColumn());

        $seite = $this->get("/boersen/$id");
        $this->assertStringContainsString('Herbstbörse', $seite->body);
        $this->assertStringContainsString('in Vorbereitung', $seite->body);
        $this->assertStringContainsString('7,5 %', $seite->body);
        $bearbeiten = $this->get("/boersen/$id/bearbeiten");
        $this->assertStringContainsString('value="7,5"', $bearbeiten->body);

        // Stand-Schutz.
        $stand = (string) $b['geaendert_am'];
        $this->assertSame(303, $this->post("/boersen/$id", ['name' => 'Herbstbörse 2026', 'datum' => '2026-11-08', 'ort' => 'Vereinsheim', 'provision_prozent' => '10', 'stand' => $stand])->status);
        $this->assertSame(409, $this->post("/boersen/$id", ['name' => 'Herbstbörse X', 'datum' => '2026-11-08', 'ort' => '', 'provision_prozent' => '10', 'stand' => $stand])->status);

        // Nach dem ersten Verkauf bleibt die Provision.
        $this->pdo->exec("INSERT INTO boerse_verkauf (boerse_id, summe, zahlart, verkauft_am) VALUES ($id, '1.00', 'bar', NOW())");
        $stand = (string) $this->pdo->query("SELECT geaendert_am FROM boerse WHERE id = $id")->fetchColumn();
        $r = $this->post("/boersen/$id", ['name' => 'Herbstbörse 2026', 'datum' => '2026-11-08', 'ort' => 'Vereinsheim', 'provision_prozent' => '12', 'stand' => $stand]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Die Provision lässt sich nach dem ersten Verkauf nicht mehr ändern.', $r->body);
        $this->assertSame(303, $this->post("/boersen/$id", ['name' => 'Herbstbörse 2026', 'datum' => '2026-11-09', 'ort' => 'Vereinsheim', 'provision_prozent' => '10,00', 'stand' => $stand])->status);

        // Starten.
        $this->assertSame(303, $this->post("/boersen/$id/starten")->status);
        $this->assertSame('laeuft', $this->pdo->query("SELECT status FROM boerse WHERE id = $id")->fetchColumn());
        $this->post("/boersen/$id/starten");
        $this->assertSame(['Nur eine Börse in Vorbereitung lässt sich starten.'], $this->meldungen('fehler'));

        // Abgeschlossene Börse: kein Bearbeiten, keine Angebote mehr dorthin.
        $this->pdo->exec("UPDATE boerse SET status = 'abgeschlossen' WHERE id = $id");
        $this->get("/boersen/$id/bearbeiten");
        $this->assertContains('Die Börse ist abgeschlossen und kann nicht mehr geändert werden.', $this->meldungen('fehler'));
        $r = $this->post('/angebote', $this->formular(['herkunft' => 'verein', 'boerse_id' => (string) $id]));
        $this->assertSame(422, $r->status);
    }

    public function test_preisschilder_und_gesamtliste(): void
    {
        $this->anmelden('mitglied', true);
        $this->post('/boersen', ['name' => 'Herbstbörse', 'datum' => '2026-11-08', 'ort' => 'Vereinsheim', 'provision_prozent' => '10']);
        $boerse = $this->zahl('SELECT MAX(id) FROM boerse');
        $verein = $this->neuesAngebot(['herkunft' => 'verein', 'boerse_id' => (string) $boerse]);
        $carla = $this->anmelden('mitglied', false, 'carla@verein.de');
        $this->pdo->exec('UPDATE person SET vorname = \'Carla\', nachname = \'Mustermann\' WHERE id = ' . $this->person($carla));
        $mitglied = $this->neuesAngebot(['bezeichnung' => 'Roco Güterwagen', 'wunschpreis' => '3', 'boerse_id' => (string) $boerse]);
        $this->assertSame(403, $this->get("/boersen/$boerse/preisschilder")->status);
        $this->assertSame(403, $this->get("/boersen/$boerse/liste")->status);

        $this->anmelden('mitglied', true);
        $r = $this->get("/boersen/$boerse/preisschilder", ['auswahl' => 'boerse', 'start' => '2']);
        $this->assertSame(200, $r->status);
        $nr = 'B' . $this->jahr . '-1';
        $this->assertStringContainsString($nr, $r->body);
        $this->assertStringContainsString('12,50 €', $r->body);
        $this->assertStringContainsString('3,00 €', $r->body);
        $this->assertStringContainsString('<svg', $r->body);
        $this->assertStringContainsString('verein.example/c/' . $nr . '<', $r->body, 'Kurzlink-Text');
        $this->assertStringContainsString('Beim Drucken „Tatsächliche Größe“ bzw. 100&nbsp;% und Ränder „Keine“ wählen.', $r->body);
        $this->assertStringContainsString('oeffentliche_url', $r->body, 'Warnung ohne oeffentliche_url');
        $this->assertStringNotContainsString('class="navbar', $r->body, 'Druckseite ohne Menü');
        $this->assertSame(2, substr_count($r->body, 'class="etikett etikett-preisschild"'));
        $this->assertSame(6, substr_count($r->body, 'class="feld-leer"'), 'Start auf Feld 2: 1 leer davor, 5 danach');
        $this->assertStringNotContainsString('Mustermann', $r->body);

        $mitUrl = $this->get("/boersen/$boerse/preisschilder", ['auswahl' => 'einzeln', 'ids' => [(string) $mitglied]], ['oeffentliche_url' => 'https://lokschuppen.example']);
        $this->assertStringContainsString('lokschuppen.example/c/B' . $this->jahr . '-2', $mitUrl->body);
        $this->assertStringNotContainsString($nr . '<', $mitUrl->body);
        $this->assertStringNotContainsString('Bitte oeffentliche_url', $mitUrl->body);
        $this->assertSame(1, substr_count($this->get("/boersen/$boerse/preisschilder", ['auswahl' => 'dauerhaft'])->body, 'Keine offenen Angebote'));

        $liste = $this->get("/boersen/$boerse/liste");
        $this->assertSame(200, $liste->status);
        $this->assertStringContainsString($nr, $liste->body);
        $this->assertStringContainsString('Roco Güterwagen', $liste->body);
        $this->assertStringContainsString('<td>Mitglied</td>', $liste->body);
        $this->assertStringContainsString('<td>Verein</td>', $liste->body);
        $this->assertStringNotContainsString('Mustermann', $liste->body);
        $this->assertStringNotContainsString('Carla', $liste->body);
        $this->assertSame($verein, $this->zahl("SELECT id FROM boerse_angebot WHERE herkunft = 'verein'"));
    }

    public function test_menue_und_anmeldung(): void
    {
        $this->assertSame(url('/login', ['ziel' => '/boersen']), $this->get('/boersen')->headers['Location'] ?? null);
        $this->assertSame(303, $this->get('/angebote')->status);
        $this->anmelden('mitglied');
        $start = $this->get('/')->body;
        $this->assertStringContainsString('>Börse</a>', $start);
        $this->assertStringContainsString('href="' . url('/angebote', ['meine' => '1']) . '"', $start);
        $this->assertStringContainsString('href="' . url('/boersen') . '"', $start);
    }
}
