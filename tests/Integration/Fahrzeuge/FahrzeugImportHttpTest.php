<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Fahrzeuge;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Fahrzeuge\FahrzeugCsvImport;
use Emc\Personen\DsgvoLoeschung;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;

/** Fahrzeug-Import aus CSV (Vorschau, Bestätigen, Schutz vor doppeltem Import) und das Kennzeichen „ungeprüft“. */
final class FahrzeugImportHttpTest extends DbTestCase
{
    private const KOPF = "\xEF\xBB\xBFAlte Nummer;Bezeichnung;Gattung;Baureihe;Bahn;Epoche;Spur;Strom;Hersteller;Artikel;Besitzer;Quelle;Notiz\r\n";

    private array $sitzung = [];
    private string $speicher;
    private int $h0;
    private int $wart;
    private int $mitglied;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->wart = $this->legeBenutzerAn('wart@verein.de', 'geheim-passwort-1', 'fahrzeugwart');
        $this->mitglied = $this->legeBenutzerAn('mitglied@verein.de', 'geheim-passwort-1', 'mitglied');
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    private function als(int $benutzerId): void
    {
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $benutzerId);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    private function app(): App
    {
        return App::erzeuge(['speicher_pfad' => $this->speicher] + self::testConfig(), new Session($this->sitzung));
    }

    private function get(string $pfad, array $q = []): Response
    {
        return $this->app()->handle(new Request('GET', $pfad, $q));
    }

    private function post(string $pfad, array $d = [], array $dateien = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $d + ['_csrf' => str_repeat('a', 64)], $dateien));
    }

    private function upload(string $inhalt, string $name = 'Bestand.csv'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        $this->temp[] = $tmp;
        file_put_contents($tmp, $inhalt);
        return ['datei' => ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($inhalt)]];
    }

    private function liste(): string
    {
        return self::KOPF
            . "A-1;V 100 rot;Lok;V 100;DR;IV;H0;;Beispielwerk;12345;Erika Beispiel;Liste 2020;Lack ab\r\n"
            . "A-2;Kesselwagen;wagen;;DB;;h0;3L;;;;;\r\n"
            . "A-3;Sonderzug;Zugverband;;;;H0;;;;;;\r\n";
    }

    /** Bestätigen wie das Formular der Vorschau: mit der Prüfsumme der angezeigten Datei. */
    private function bestaetigen(?string $hash = null): Response
    {
        return $this->post('/fahrzeuge/import/bestaetigen', ['hash' => $hash ?? (string) ($this->sitzung['fahrzeug_import']['hash'] ?? '')]);
    }

    /** @return list<string> */
    private function meldungen(): array
    {
        return array_column($this->sitzung['_meldungen'] ?? [], 'text');
    }

    private function bestehendesFahrzeug(string $bezeichnung = 'V 60', int $ungeprueft = 0, ?int $angelegtVon = null): int
    {
        $nr = 'EMC-' . sprintf('%04d', 1 + (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, ungeprueft, angelegt_von_benutzer_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$nr, $bezeichnung, $this->h0, $ungeprueft, $angelegtVon]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<string> */
    private function importDateien(): array
    {
        return glob($this->speicher . '/import/*') ?: [];
    }

    public function test_mitglied_darf_nicht_importieren(): void
    {
        $this->als($this->mitglied);
        $this->assertSame(403, $this->get('/fahrzeuge/import')->status);
        $this->assertSame(403, $this->post('/fahrzeuge/import', [], $this->upload($this->liste()))->status);
        $this->assertSame(403, $this->post('/fahrzeuge/import/bestaetigen')->status);
        $this->assertSame(403, $this->get('/fahrzeuge/import/vorlage.csv')->status);
        $this->assertStringNotContainsString('/fahrzeuge/import', $this->get('/fahrzeuge')->body);
        $this->assertSame([], $this->importDateien());
    }

    public function test_fahrzeugwart_sieht_formular_und_vorlage(): void
    {
        $this->als($this->wart);
        $this->assertStringContainsString('href="/fahrzeuge/import"', $this->get('/fahrzeuge')->body);
        $r = $this->get('/fahrzeuge/import');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('enctype="multipart/form-data"', $r->body);
        $this->assertStringContainsString('/fahrzeuge/import/vorlage.csv', $r->body);
        $v = $this->get('/fahrzeuge/import/vorlage.csv');
        $this->assertSame(200, $v->status);
        $this->assertSame('text/csv; charset=utf-8', $v->headers['Content-Type']);
        $this->assertStringContainsString('fahrzeuge-vorlage.csv', $v->headers['Content-Disposition']);
        $this->assertSame(FahrzeugCsvImport::VORLAGE, $v->body);
    }

    public function test_vorschau_und_bestaetigen_legt_fahrzeuge_an(): void
    {
        $vorhanden = $this->bestehendesFahrzeug('Kesselwagen');
        $this->als($this->wart);
        $r = $this->post('/fahrzeuge/import', [], $this->upload($this->liste(), 'C:\\fakepath\\Bestand "2026".csv'));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('V 100 rot', $r->body);
        $this->assertStringContainsString('wird angelegt', $r->body);
        $this->assertStringContainsString('Zugverband – wird nicht importiert', $r->body);
        $this->assertStringContainsString('Bezeichnung doppelt (in Datei oder schon vorhanden) – wird trotzdem angelegt', $r->body);
        $this->assertMatchesRegularExpression('/data-anzahl="anlegen">2</', $r->body);
        $this->assertMatchesRegularExpression('/data-anzahl="zugverband">1</', $r->body);
        $this->assertMatchesRegularExpression('/data-anzahl="doppelt">1</', $r->body);
        $this->assertMatchesRegularExpression('/data-anzahl="fehler">0</', $r->body);
        $this->assertStringContainsString('action="/fahrzeuge/import/bestaetigen"', $r->body);
        $this->assertStringNotContainsString('Erika Beispiel;', $r->body, 'Rohdatei steht nicht im Formular');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn(), 'Vorschau speichert nichts');
        $this->assertCount(1, $this->importDateien(), 'Datei liegt serverseitig');
        $this->assertArrayHasKey('fahrzeug_import', $this->sitzung);
        $this->assertArrayNotHasKey('inhalt', $this->sitzung['fahrzeug_import']);

        $b = $this->bestaetigen();
        $this->assertSame(303, $b->status);
        $this->assertSame('/fahrzeuge?ungeprueft=1', $b->headers['Location']);
        $this->assertSame(['2 Fahrzeuge importiert.'], $this->meldungen());
        $this->assertSame([], $this->importDateien(), 'Datei nach dem Import gelöscht');
        $this->assertArrayNotHasKey('fahrzeug_import', $this->sitzung);

        $neu = $this->pdo->query("SELECT * FROM fahrzeug WHERE id <> $vorhanden ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $neu);
        $this->assertSame(['EMC-0002', 'EMC-0003'], array_column($neu, 'inventarnummer'), 'neue Nummern, nicht die alten');
        $this->assertSame(['1', '1'], array_map('strval', array_column($neu, 'ungeprueft')));
        $this->assertSame([(string) $this->wart, (string) $this->wart], array_map('strval', array_column($neu, 'angelegt_von_benutzer_id')));
        $this->assertSame([null, null], array_column($neu, 'eigentuemer_person_id'));
        $this->assertSame(['einsatzbereit', 'einsatzbereit'], array_column($neu, 'status'));
        $v100 = $neu[0];
        $this->assertSame('V 100 rot', $v100['bezeichnung']);
        $this->assertSame('V 100', $v100['baureihe']);
        $this->assertSame('DR', $v100['bahnverwaltung']);
        $this->assertSame('IV', $v100['epoche']);
        $this->assertSame('lok', $v100['gattung']);
        $this->assertSame('Beispielwerk', $v100['hersteller']);
        $this->assertSame('12345', $v100['artikelnummer']);
        $this->assertSame($this->h0, (int) $v100['spurweite_id']);
        $this->assertSame('2L', $v100['stromsystem']);
        $this->assertSame(
            "Lack ab\n\nImport vom " . date('d.m.Y') . ' aus „Bestand _2026_.csv“, alte Nummer A-1. Besitzer laut Liste: Erika Beispiel. Quelle: Liste 2020.',
            $v100['notiz']
        );
        $this->assertSame('3L', $neu[1]['stromsystem']);
        $this->assertSame('wagen', $neu[1]['gattung']);
        $this->assertStringContainsString('alte Nummer A-2.', $neu[1]['notiz']);

        $imp = $this->pdo->query('SELECT * FROM fahrzeug_import')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $imp);
        $this->assertSame(hash('sha256', $this->liste()), $imp[0]['datei_sha256']);
        $this->assertSame('Bestand _2026_.csv', $imp[0]['dateiname']);
        $this->assertSame(2, (int) $imp[0]['anzahl']);
        $this->assertSame($this->wart, (int) $imp[0]['benutzer_id']);

        $prot = $this->pdo->query("SELECT benutzer_id, aktion, aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = " . (int) $v100['id'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('angelegt', $prot['aktion']);
        $this->assertSame($this->wart, (int) $prot['benutzer_id']);
        $diff = json_decode($prot['aenderungen'], true);
        $this->assertSame(['alt' => null, 'neu' => 'V 100 rot'], $diff['bezeichnung']);
        $this->assertSame(['alt' => null, 'neu' => 'Import aus „Bestand _2026_.csv“'], $diff['import']);

        // Die Liste zeigt die neuen Fahrzeuge als ungeprüft.
        $liste = $this->get('/fahrzeuge', ['ungeprueft' => '1']);
        $this->assertSame(200, $liste->status);
        $this->assertStringContainsString('V 100 rot', $liste->body);
        $this->assertStringNotContainsString('>EMC-0001<', $liste->body);
        $this->assertStringContainsString('ungeprüft', $liste->body);
    }

    public function test_fehlerzeile_blockiert_import(): void
    {
        $this->als($this->wart);
        $r = $this->post('/fahrzeuge/import', [], $this->upload(self::KOPF . ";Lok A;Lok;;;;H0;;;;;;\r\n;Lok B;Dampfwalze;;;;H0;;;;;;\r\n"));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Fehler: Gattung „Dampfwalze“ ist unbekannt', $r->body);
        $this->assertStringContainsString('Bitte Datei korrigieren und neu hochladen', $r->body);
        $this->assertStringNotContainsString('/fahrzeuge/import/bestaetigen', $r->body);
        $this->assertArrayNotHasKey('fahrzeug_import', $this->sitzung);
        $this->assertSame([], $this->importDateien());

        $b = $this->post('/fahrzeuge/import/bestaetigen');
        $this->assertSame(303, $b->status);
        $this->assertSame('/fahrzeuge/import', $b->headers['Location']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    public function test_zweiter_import_derselben_datei_wird_blockiert(): void
    {
        $this->als($this->wart);
        $this->post('/fahrzeuge/import', [], $this->upload($this->liste()));
        $this->bestaetigen();
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());

        $r = $this->post('/fahrzeuge/import', [], $this->upload($this->liste(), 'anderer-name.csv'));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Diese Datei wurde am ' . date('d.m.Y') . ' bereits importiert', $r->body);
        $this->assertStringNotContainsString('Bitte Datei korrigieren', $r->body);
        $this->assertStringNotContainsString('/fahrzeuge/import/bestaetigen', $r->body);
        $this->assertArrayNotHasKey('fahrzeug_import', $this->sitzung);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    /** Doppelklick bzw. zwei Vorschauen nebeneinander: der eindeutige Hash fängt den zweiten Import ab, kein Fehler 500. */
    public function test_gleichzeitiger_zweiter_import_wird_sauber_abgewiesen(): void
    {
        $this->als($this->wart);
        $this->post('/fahrzeuge/import', [], $this->upload($this->liste()));
        $this->pdo->prepare("INSERT INTO fahrzeug_import (datei_sha256, dateiname, anzahl) VALUES (?, 'x.csv', 2)")->execute([hash('sha256', $this->liste())]);
        $b = $this->bestaetigen();
        $this->assertSame(303, $b->status);
        $this->assertSame('/fahrzeuge/import', $b->headers['Location']);
        $this->assertStringContainsString('bereits importiert', implode(' ', $this->meldungen()));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn(), 'alles zurückgerollt');
        $this->assertSame([], $this->importDateien());
    }

    public function test_bestaetigen_ohne_vorschau(): void
    {
        $this->als($this->wart);
        $b = $this->post('/fahrzeuge/import/bestaetigen');
        $this->assertSame(303, $b->status);
        $this->assertSame('/fahrzeuge/import', $b->headers['Location']);
        $this->assertSame(['Es liegt keine Vorschau vor. Bitte die Datei erneut hochladen.'], $this->meldungen());
        // Manipulierte Sitzung: kein Pfad außerhalb des Importordners.
        $this->sitzung['fahrzeug_import'] = ['datei' => '../../etc/passwd', 'name' => 'x.csv', 'hash' => str_repeat('0', 64)];
        $this->assertSame('/fahrzeuge/import', $this->post('/fahrzeuge/import/bestaetigen')->headers['Location']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    public function test_abbrechen_loescht_abgelegte_datei(): void
    {
        $this->als($this->wart);
        $this->post('/fahrzeuge/import', [], $this->upload($this->liste()));
        $this->assertCount(1, $this->importDateien());
        $r = $this->post('/fahrzeuge/import/abbrechen');
        $this->assertSame(303, $r->status);
        $this->assertSame([], $this->importDateien());
        $this->assertArrayNotHasKey('fahrzeug_import', $this->sitzung);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    /** Zwei Tabs: Bestätigen aus dem alten Tab darf nicht die inzwischen hochgeladene neuere Datei importieren. */
    public function test_neue_vorschau_ersetzt_alte_datei(): void
    {
        $this->als($this->wart);
        $alt = $this->post('/fahrzeuge/import', [], $this->upload($this->liste()));
        $altHash = hash('sha256', $this->liste());
        $this->assertStringContainsString('name="hash" value="' . $altHash . '"', $alt->body);
        $neuInhalt = self::KOPF . ";Lok C;Lok;;;;H0;;;;;;\r\n";
        $this->post('/fahrzeuge/import', [], $this->upload($neuInhalt));
        $this->assertCount(1, $this->importDateien());

        $r = $this->bestaetigen($altHash);
        $this->assertSame(303, $r->status);
        $this->assertSame('/fahrzeuge/import', $r->headers['Location']);
        $this->assertSame(['Die Vorschau ist nicht mehr aktuell (inzwischen wurde eine andere Datei hochgeladen). Bitte erneut hochladen.'], $this->meldungen());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
        $this->assertCount(1, $this->importDateien(), 'die neuere Vorschau bleibt erhalten');
        $this->assertArrayHasKey('fahrzeug_import', $this->sitzung);
        $this->assertSame(303, $this->bestaetigen('')->status, 'ohne Prüfsumme ebenso');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());

        $this->sitzung['_meldungen'] = [];
        $this->bestaetigen(hash('sha256', $neuInhalt));
        $this->assertSame(['Lok C'], $this->pdo->query('SELECT bezeichnung FROM fahrzeug')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(['1 Fahrzeug importiert.'], $this->meldungen());
        $this->assertSame([], $this->importDateien());
    }

    public function test_vorschau_nennt_neue_inventarnummern(): void
    {
        $this->als($this->wart);
        $r = $this->post('/fahrzeuge/import', [], $this->upload($this->liste()));
        $this->assertStringContainsString('Die Fahrzeuge bekommen neue Inventarnummern; die alte Nummer aus der Liste steht in der Notiz.', $r->body);
    }

    public function test_formular_raeumt_alte_vorschau_dateien_weg(): void
    {
        mkdir($this->speicher . '/import', 0750, true);
        $alt = $this->speicher . '/import/' . str_repeat('a', 32) . '.csv';
        $frisch = $this->speicher . '/import/' . str_repeat('b', 32) . '.csv';
        file_put_contents($alt, 'x');
        file_put_contents($frisch, 'x');
        touch($alt, time() - 86400 - 60);
        $this->als($this->wart);
        $this->assertSame(200, $this->get('/fahrzeuge/import')->status);
        $this->assertFileDoesNotExist($alt);
        $this->assertFileExists($frisch);
    }

    /** Hält eine andere Verbindung die Sperre, gibt es eine verständliche Meldung samt Hinweis auf erneutes Hochladen. */
    public function test_sperrkonflikt_meldung(): void
    {
        $andere = self::verbindung();
        $andere->beginTransaction();
        $andere->prepare("INSERT INTO fahrzeug_import (datei_sha256, dateiname, anzahl) VALUES (?, 'x.csv', 1)")->execute([hash('sha256', $this->liste())]);
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $dienst = new \Emc\Fahrzeuge\FahrzeugImport($this->pdo, new \Emc\Fahrzeuge\FahrzeugRepository($this->pdo), new \Emc\Stammdaten\SpurweiteRepository($this->pdo), new \Emc\Protokoll\Aenderungsprotokoll($this->pdo));
        try {
            $dienst->importiere($this->liste(), 'a.csv', $this->wart, new \DateTimeImmutable());
            $this->fail('Sperrkonflikt erwartet');
        } catch (\Emc\Core\DomainFehler $e) {
            $this->assertSame(\Emc\Core\Transaktion::SPERRKONFLIKT . ' Bitte die Datei erneut hochladen.', $e->getMessage());
        } finally {
            $andere->rollBack();
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    public function test_csrf_und_dateifehler(): void
    {
        $this->als($this->wart);
        $ohne = $this->app()->handle(new Request('POST', '/fahrzeuge/import', [], [], $this->upload($this->liste())));
        $this->assertSame(419, $ohne->status);
        $this->assertSame(419, $this->app()->handle(new Request('POST', '/fahrzeuge/import/bestaetigen', [], ['_csrf' => 'falsch']))->status);

        $r = $this->post('/fahrzeuge/import');
        $this->assertSame(303, $r->status);
        $this->assertSame(['Bitte eine CSV-Datei auswählen.'], $this->meldungen());
        $this->als($this->wart);
        $this->post('/fahrzeuge/import', [], $this->upload(self::KOPF . str_repeat('x', FahrzeugCsvImport::MAX_BYTES)));
        $this->assertSame(['Die Datei ist zu groß (höchstens 2 MB).'], $this->meldungen());
        $this->als($this->wart);
        $this->post('/fahrzeuge/import', [], ['datei' => ['name' => 'a.csv', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]]);
        $this->assertSame(['Die Datei ist zu groß (höchstens 2 MB).'], $this->meldungen());
    }

    public function test_als_geprueft_markieren(): void
    {
        $fz = $this->bestehendesFahrzeug('V 60', 1);
        $stand = $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = $fz")->fetchColumn();
        $this->als($this->mitglied);
        $detail = $this->get("/fahrzeuge/$fz")->body;
        $this->assertStringContainsString('ungeprüft', $detail);
        $this->assertStringNotContainsString("/fahrzeuge/$fz/geprueft", $detail);
        $this->assertSame(403, $this->post("/fahrzeuge/$fz/geprueft")->status);

        $this->als($this->wart);
        $this->assertStringContainsString("action=\"/fahrzeuge/$fz/geprueft\"", $this->get("/fahrzeuge/$fz")->body);
        $this->assertSame(419, $this->app()->handle(new Request('POST', "/fahrzeuge/$fz/geprueft"))->status);
        $r = $this->post("/fahrzeuge/$fz/geprueft");
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/$fz", $r->headers['Location']);
        $this->assertSame(['Das Fahrzeug ist jetzt als geprüft markiert.'], $this->meldungen());
        $this->assertSame(0, (int) $this->pdo->query("SELECT ungeprueft FROM fahrzeug WHERE id = $fz")->fetchColumn());
        $this->assertSame($stand, $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = $fz")->fetchColumn(), 'kein Bearbeitungskonflikt für offene Formulare');
        $prot = $this->pdo->query("SELECT aktion, aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $prot);
        $this->assertSame('geaendert', $prot[0]['aktion']);
        $this->assertSame(['geprüft' => ['alt' => 'nein', 'neu' => 'ja']], json_decode($prot[0]['aenderungen'], true));
        $this->assertStringNotContainsString("/fahrzeuge/$fz/geprueft", $this->get("/fahrzeuge/$fz")->body);

        // Zweites Markieren: nichts mehr zu tun, kein weiterer Protokolleintrag.
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/geprueft")->status);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_id = $fz")->fetchColumn());
        $this->assertSame(404, $this->post('/fahrzeuge/999999/geprueft')->status);
    }

    public function test_mitglied_markiert_eigenes_fahrzeug(): void
    {
        $fz = $this->bestehendesFahrzeug('Eigene Lok', 1, $this->mitglied);
        $this->als($this->mitglied);
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/geprueft")->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT ungeprueft FROM fahrzeug WHERE id = $fz")->fetchColumn());
    }

    public function test_filter_nur_ungepruefte(): void
    {
        $this->bestehendesFahrzeug('Geprüfte Lok', 0);
        $this->bestehendesFahrzeug('Offene Lok', 1);
        $this->als($this->mitglied);
        $alle = $this->get('/fahrzeuge')->body;
        $this->assertStringContainsString('Geprüfte Lok', $alle);
        $this->assertStringContainsString('Offene Lok', $alle);
        $this->assertStringContainsString('name="ungeprueft"', $alle);
        $nur = $this->get('/fahrzeuge', ['ungeprueft' => '1'])->body;
        $this->assertStringNotContainsString('Geprüfte Lok', $nur);
        $this->assertStringContainsString('Offene Lok', $nur);
        $this->assertMatchesRegularExpression('/name="ungeprueft" value="1"[^>]*checked/', $nur);
    }

    public function test_normales_speichern_laesst_ungeprueft_unveraendert(): void
    {
        $fz = $this->bestehendesFahrzeug('V 60', 1);
        $stand = (string) $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = $fz")->fetchColumn();
        $this->als($this->wart);
        $r = $this->post("/fahrzeuge/$fz", [
            'bezeichnung' => 'V 60 neu', 'gattung' => 'lok', 'spurweite_id' => (string) $this->h0, 'stromsystem' => '2L',
            'status' => 'einsatzbereit', 'betriebsstunden' => '0', '_stand' => $stand, 'ungeprueft' => '0',
        ]);
        $this->assertSame(303, $r->status);
        $this->assertSame('V 60 neu', $this->pdo->query("SELECT bezeichnung FROM fahrzeug WHERE id = $fz")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT ungeprueft FROM fahrzeug WHERE id = $fz")->fetchColumn());
        // Neu angelegte Fahrzeuge über das Formular sind nicht ungeprüft.
        $this->post('/fahrzeuge', ['bezeichnung' => 'Neue Lok', 'gattung' => 'lok', 'spurweite_id' => (string) $this->h0, 'stromsystem' => '2L', 'status' => 'einsatzbereit', 'betriebsstunden' => '0', 'ungeprueft' => '1']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT ungeprueft FROM fahrzeug WHERE bezeichnung = 'Neue Lok'")->fetchColumn());
    }

    public function test_dsgvo_loeschung_des_importierenden_kontos(): void
    {
        $this->als($this->wart);
        $this->post('/fahrzeuge/import', [], $this->upload($this->liste()));
        $this->bestaetigen();
        $admin = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        $person = (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = {$this->wart}")->fetchColumn();
        $this->app()->container()->get(DsgvoLoeschung::class)->loesche($person, [], $admin);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM benutzer WHERE id = {$this->wart}")->fetchColumn());
        $imp = $this->pdo->query('SELECT benutzer_id, anzahl FROM fahrzeug_import')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($imp['benutzer_id']);
        $this->assertSame(2, (int) $imp['anzahl']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug WHERE angelegt_von_benutzer_id IS NULL')->fetchColumn(), 'Vereinsfahrzeuge bleiben');
    }

    /** „Besitzer laut Liste“ in der Notiz: die DSGVO-Löschung der Person ersetzt den Namen wie in anderen Freitexten. */
    public function test_dsgvo_loeschung_bereinigt_besitzer_in_fahrzeugnotiz(): void
    {
        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('Erika', 'Beispiel')");
        $person = (int) $this->pdo->lastInsertId();
        $this->als($this->wart);
        $this->post('/fahrzeuge/import', [], $this->upload($this->liste()));
        $this->bestaetigen();
        $fz = (int) $this->pdo->query("SELECT id FROM fahrzeug WHERE bezeichnung = 'V 100 rot'")->fetchColumn();
        $admin = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        $dienst = $this->app()->container()->get(DsgvoLoeschung::class);
        $this->assertSame(1, $dienst->vorschau($person, $admin)['freitexte']);
        $dienst->loesche($person, [], $admin);
        $notiz = (string) $this->pdo->query("SELECT notiz FROM fahrzeug WHERE id = $fz")->fetchColumn();
        $this->assertStringNotContainsString('Erika Beispiel', $notiz);
        $this->assertStringContainsString('Besitzer laut Liste: ehemaliges Mitglied.', $notiz);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE aenderungen LIKE '%Erika Beispiel%'")->fetchColumn(), 'auch nicht im Protokoll der Anlage');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz AND aenderungen LIKE '%Freitexte nach DSGVO bereinigt%'")->fetchColumn());
    }
}
