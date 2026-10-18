<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Fahrzeuge;

use Emc\Betrieb\Migrator;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadService;
use Emc\Tests\Integration\DbTestCase;
use Emc\Tests\Integration\Dateien\UploadServiceTest;

/** Mitglieder dürfen Fahrzeuge anlegen und die selbst angelegten bearbeiten; alles serverseitig je Objekt. */
final class MitgliederRechteTest extends DbTestCase
{
    private array $sitzung = [];
    private string $speicher;
    private int $h0;
    private int $mitgliedA;
    private int $mitgliedB;
    private int $bearbeiter;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->mitgliedA = $this->legeBenutzerAn('a@verein.de', 'geheim-passwort-1', 'mitglied');
        $this->mitgliedB = $this->legeBenutzerAn('b@verein.de', 'geheim-passwort-1', 'mitglied');
        $this->bearbeiter = $this->legeBenutzerAn('c@verein.de', 'geheim-passwort-1', 'bearbeiter');
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

    private function personVon(int $benutzerId): int
    {
        return (int) $this->pdo->query('SELECT person_id FROM benutzer WHERE id = ' . $benutzerId)->fetchColumn();
    }

    private function app(): App
    {
        $app = App::erzeuge(['speicher_pfad' => $this->speicher, 'upload_max_mb' => 64] + self::testConfig(), new Session($this->sitzung));
        $app->container()->set(UploadService::class, fn() => new UploadService(
            $this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b)
        ));
        return $app;
    }

    private function get(string $pfad): Response
    {
        return $this->app()->handle(new Request('GET', $pfad));
    }

    private function post(string $pfad, array $d = [], array $dateien = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $d + ['_csrf' => str_repeat('a', 64)], $dateien));
    }

    /** Legt direkt ein Fahrzeug an; $von = Benutzer-ID des Anlegers (null = Altbestand). */
    private function fahrzeug(?int $von, ?int $eigentuemerPerson = null, string $bez = 'Lok'): int
    {
        $n = (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn() + 1;
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, eigentuemer_person_id, angelegt_von_benutzer_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([sprintf('EMC-%04d', $n), $bez, $this->h0, $eigentuemerPerson, $von]);
        return (int) $this->pdo->lastInsertId();
    }

    private function decoder(int $fz): int
    {
        $this->pdo->prepare("INSERT INTO decoder (fahrzeug_id, bezeichnung, protokoll, adresse) VALUES (?, 'Motor', 'dcc', 3)")->execute([$fz]);
        return (int) $this->pdo->lastInsertId();
    }

    private function fahrzeugDaten(array $u = []): array
    {
        return $u + ['bezeichnung' => 'Meine Lok', 'gattung' => 'lok', 'spurweite_id' => (string) $this->h0, 'stromsystem' => '2L', 'status' => 'einsatzbereit', 'betriebsstunden' => '0'];
    }

    private function feld(int $fz, string $spalte): mixed
    {
        return $this->pdo->query("SELECT $spalte FROM fahrzeug WHERE id = $fz")->fetchColumn();
    }

    private function datei(string $fixture): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $this->temp[] = $tmp;
        copy(dirname(__DIR__, 2) . '/fixtures/dateien/' . $fixture, $tmp);
        return ['name' => $fixture, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    }

    private function csv(string $inhalt): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        $this->temp[] = $tmp;
        file_put_contents($tmp, $inhalt);
        return ['datei' => ['name' => 'f.csv', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($inhalt)]];
    }

    private function dokument(int $fz, string $fixture = 'anleitung.pdf', string $kat = 'anleitung'): int
    {
        $service = new UploadService($this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b));
        $id = $service->speichere($this->datei($fixture), '', $kat, null);
        (new DokumentRepository($this->pdo, $this->speicher))->zuordnen($id, 'fahrzeug', $fz);
        return $id;
    }

    // ---- Anlegen ----

    public function test_mitglied_legt_fahrzeug_an_eigentuemer_erzwungen(): void
    {
        $this->als($this->mitgliedA);
        $fremd = $this->personVon($this->mitgliedB);
        $this->assertSame(200, $this->get('/fahrzeuge/neu')->status);
        $r = $this->post('/fahrzeuge', $this->fahrzeugDaten(['eigentuemer_person_id' => (string) $fremd]));
        $this->assertSame(303, $r->status);
        $id = (int) $this->pdo->query('SELECT id FROM fahrzeug')->fetchColumn();
        $this->assertSame($this->personVon($this->mitgliedA), (int) $this->feld($id, 'eigentuemer_person_id'));
        $this->assertSame($this->mitgliedA, (int) $this->feld($id, 'angelegt_von_benutzer_id'));
        $this->assertSame($this->mitgliedA, (int) $this->pdo->query("SELECT benutzer_id FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND aktion = 'angelegt'")->fetchColumn());
    }

    public function test_mitglied_formular_zeigt_eigentum_als_festen_text(): void
    {
        $this->als($this->mitgliedA);
        $body = $this->get('/fahrzeuge/neu')->body;
        $this->assertStringContainsString('Eigentum: Max Muster (selbst angelegte Fahrzeuge gehören dir)', $body);
        $this->assertStringNotContainsString('name="eigentuemer_person_id"', $body);
    }

    public function test_mitglied_ohne_eigentuemer_feld_im_post_wird_ebenfalls_eigentuemer(): void
    {
        $this->als($this->mitgliedA);
        $this->post('/fahrzeuge', $this->fahrzeugDaten(['eigentuemer_person_id' => '']));
        $id = (int) $this->pdo->query('SELECT id FROM fahrzeug')->fetchColumn();
        $this->assertSame($this->personVon($this->mitgliedA), (int) $this->feld($id, 'eigentuemer_person_id'));
    }

    public function test_bearbeiter_waehlt_eigentuemer_frei_und_wird_als_anleger_gespeichert(): void
    {
        $this->als($this->bearbeiter);
        $this->assertStringContainsString('name="eigentuemer_person_id"', $this->get('/fahrzeuge/neu')->body);
        $fremd = $this->personVon($this->mitgliedB);
        $this->post('/fahrzeuge', $this->fahrzeugDaten(['eigentuemer_person_id' => (string) $fremd]));
        $id = (int) $this->pdo->query('SELECT id FROM fahrzeug')->fetchColumn();
        $this->assertSame($fremd, (int) $this->feld($id, 'eigentuemer_person_id'));
        $this->assertSame($this->bearbeiter, (int) $this->feld($id, 'angelegt_von_benutzer_id'));
    }

    // ---- Bearbeiten / Löschen ----

    public function test_mitglied_bearbeitet_selbst_angelegtes_fahrzeug_inkl_ausmustern(): void
    {
        $eigen = $this->personVon($this->mitgliedA);
        $fz = $this->fahrzeug($this->mitgliedA, $eigen);
        $this->als($this->mitgliedA);
        $form = $this->get("/fahrzeuge/$fz/bearbeiten");
        $this->assertSame(200, $form->status);
        $stand = (string) $this->feld($fz, 'geaendert_am');
        $fremd = $this->personVon($this->mitgliedB);
        $r = $this->post("/fahrzeuge/$fz", $this->fahrzeugDaten(['bezeichnung' => 'Neu', 'status' => 'ausgemustert', 'eigentuemer_person_id' => (string) $fremd, '_stand' => $stand]));
        $this->assertSame(303, $r->status);
        $this->assertSame('Neu', $this->feld($fz, 'bezeichnung'));
        $this->assertSame('ausgemustert', $this->feld($fz, 'status'));
        $this->assertSame($eigen, (int) $this->feld($fz, 'eigentuemer_person_id'), 'Eigentümer bleibt unverändert');
        $this->assertSame($this->mitgliedA, (int) $this->feld($fz, 'angelegt_von_benutzer_id'));
    }

    public function test_mitglied_darf_fremd_angelegtes_fahrzeug_nicht_bearbeiten_auch_nicht_als_eigentuemer(): void
    {
        $fz = $this->fahrzeug($this->bearbeiter, $this->personVon($this->mitgliedA));
        $alt = $this->fahrzeug(null, $this->personVon($this->mitgliedA), 'Altbestand');
        $this->als($this->mitgliedA);
        foreach ([$fz, $alt] as $id) {
            $this->assertSame(403, $this->get("/fahrzeuge/$id/bearbeiten")->status);
            $stand = (string) $this->feld($id, 'geaendert_am');
            $this->assertSame(403, $this->post("/fahrzeuge/$id", $this->fahrzeugDaten(['bezeichnung' => 'Hack', '_stand' => $stand]))->status);
            $this->assertNotSame('Hack', $this->feld($id, 'bezeichnung'));
        }
    }

    public function test_mitglied_kann_eigenes_fahrzeug_nicht_loeschen(): void
    {
        $fz = $this->fahrzeug($this->mitgliedA, $this->personVon($this->mitgliedA));
        $this->als($this->mitgliedA);
        $this->assertSame(403, $this->post("/fahrzeuge/$fz/loeschen")->status);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    public function test_bearbeiter_bearbeitet_und_loescht_alles(): void
    {
        $fz = $this->fahrzeug($this->mitgliedA, $this->personVon($this->mitgliedA));
        $this->als($this->bearbeiter);
        $stand = (string) $this->feld($fz, 'geaendert_am');
        $this->assertSame(303, $this->post("/fahrzeuge/$fz", $this->fahrzeugDaten(['bezeichnung' => 'Vom Bearbeiter', '_stand' => $stand]))->status);
        $this->assertSame('Vom Bearbeiter', $this->feld($fz, 'bezeichnung'));
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/loeschen")->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    // ---- Decoder ----

    public function test_mitglied_decoder_anlegen_aendern_loeschen_am_eigenen_fahrzeug(): void
    {
        $fz = $this->fahrzeug($this->mitgliedA);
        $this->als($this->mitgliedA);
        $d = ['bezeichnung' => 'Motor', 'protokoll' => 'dcc', 'adresse' => '5'];
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/decoder", $d)->status);
        $id = (int) $this->pdo->query('SELECT id FROM decoder')->fetchColumn();
        $this->assertSame(200, $this->get("/decoder/$id/bearbeiten")->status);
        $this->assertSame(303, $this->post("/decoder/$id", ['adresse' => '6'] + $d)->status);
        $this->assertSame('6', (string) $this->pdo->query('SELECT adresse FROM decoder')->fetchColumn());
        $this->assertSame(303, $this->post("/decoder/$id/loeschen")->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM decoder')->fetchColumn());
    }

    public function test_mitglied_decoder_an_fremdem_fahrzeug_403(): void
    {
        $fz = $this->fahrzeug($this->bearbeiter, $this->personVon($this->mitgliedA));
        $dec = $this->decoder($fz);
        $this->als($this->mitgliedA);
        $d = ['bezeichnung' => 'Neu', 'protokoll' => 'dcc', 'adresse' => '5'];
        $this->assertSame(403, $this->post("/fahrzeuge/$fz/decoder", $d)->status);
        $this->assertSame(403, $this->get("/decoder/$dec/bearbeiten")->status);
        $this->assertSame(403, $this->post("/decoder/$dec", $d)->status);
        $this->assertSame(403, $this->post("/decoder/$dec/loeschen")->status);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM decoder')->fetchColumn());
        $this->assertSame('3', (string) $this->pdo->query('SELECT adresse FROM decoder')->fetchColumn());
    }

    // ---- Funktionen ----

    public function test_mitglied_funktionen_csv_vorlage_am_eigenen_fahrzeug(): void
    {
        $fz = $this->fahrzeug($this->mitgliedA);
        $dec = $this->decoder($fz);
        $this->als($this->mitgliedA);
        $this->assertSame(303, $this->post("/decoder/$dec/funktionen", ['beschreibung' => [0 => 'Licht', 1 => 'Sound']])->status);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM funktion')->fetchColumn());

        $r = $this->post("/decoder/$dec/funktionen/csv", [], $this->csv("Taste;Beschreibung;Schaltart\n3;Horn;Moment\n"));
        $this->assertSame(200, $r->status);
        $this->assertSame(303, $this->post("/decoder/$dec/funktionen/csv/uebernehmen")->status);
        $this->assertSame(['Horn'], $this->pdo->query('SELECT beschreibung FROM funktion')->fetchAll(\PDO::FETCH_COLUMN));

        $this->assertSame(303, $this->post("/decoder/$dec/vorlage", ['name' => 'Meine Vorlage'])->status);
        $vorlage = (int) $this->pdo->query('SELECT id FROM funktionsvorlage')->fetchColumn();
        $this->assertSame(303, $this->post("/decoder/$dec/vorlage-anwenden", ['vorlage_id' => (string) $vorlage])->status);
        $this->assertSame(200, $this->get("/decoder/$dec/funktionen")->status);
    }

    public function test_mitglied_funktionen_an_fremdem_fahrzeug_403(): void
    {
        $fz = $this->fahrzeug($this->bearbeiter);
        $dec = $this->decoder($fz);
        $this->als($this->mitgliedA);
        $this->assertSame(403, $this->post("/decoder/$dec/funktionen", ['beschreibung' => [0 => 'Licht']])->status);
        $this->assertSame(403, $this->post("/decoder/$dec/funktionen/csv", [], $this->csv("Taste;Beschreibung;Schaltart\n3;Horn;Moment\n"))->status);
        $this->assertSame(403, $this->post("/decoder/$dec/funktionen/csv/uebernehmen")->status);
        $this->assertSame(403, $this->post("/decoder/$dec/vorlage", ['name' => 'X'])->status);
        $this->assertSame(403, $this->post("/decoder/$dec/vorlage-anwenden", ['vorlage_id' => '1'])->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM funktion')->fetchColumn());
        // Lesen bleibt erlaubt
        $this->assertSame(200, $this->get("/decoder/$dec/funktionen")->status);
    }

    // ---- CVs ----

    public function test_mitglied_cv_speichern_und_loeschen_am_eigenen_fahrzeug(): void
    {
        $fz = $this->fahrzeug($this->mitgliedA);
        $dec = $this->decoder($fz);
        $this->als($this->mitgliedA);
        $this->assertSame(303, $this->post("/decoder/$dec/cvs", ['cv' => '3', 'wert' => '10', 'kommentar' => ''])->status);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM cv_wert')->fetchColumn());
        $this->assertSame(303, $this->post("/decoder/$dec/cvs/3/loeschen")->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cv_wert')->fetchColumn());
    }

    public function test_mitglied_cv_an_fremdem_fahrzeug_403(): void
    {
        $fz = $this->fahrzeug($this->bearbeiter);
        $dec = $this->decoder($fz);
        $this->pdo->exec("INSERT INTO cv_wert (decoder_id, cv, wert) VALUES ($dec, 3, 10)");
        $this->als($this->mitgliedA);
        $this->assertSame(403, $this->post("/decoder/$dec/cvs", ['cv' => '4', 'wert' => '10'])->status);
        $this->assertSame(403, $this->post("/decoder/$dec/cvs/3/loeschen")->status);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM cv_wert')->fetchColumn());
    }

    // ---- Dateien ----

    public function test_mitglied_upload_titelbild_und_loeschen_am_eigenen_fahrzeug(): void
    {
        $fz = $this->fahrzeug($this->mitgliedA);
        $this->als($this->mitgliedA);
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/dateien", ['kategorie' => 'foto'], ['datei' => $this->datei('foto_gedreht.jpg')])->status);
        $dok = (int) $this->pdo->query('SELECT id FROM dokument')->fetchColumn();
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/titelbild", ['dokument_id' => (string) $dok])->status);
        $this->assertSame($dok, (int) $this->feld($fz, 'titelbild_dokument_id'));
        $this->assertSame(303, $this->post("/dateien/$dok/loeschen")->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
    }

    public function test_mitglied_upload_an_fremdem_fahrzeug_403_und_fremde_datei_nicht_loeschbar(): void
    {
        $fz = $this->fahrzeug($this->bearbeiter);
        $dok = $this->dokument($fz);
        $this->als($this->mitgliedA);
        $this->assertSame(403, $this->post("/fahrzeuge/$fz/dateien", [], ['datei' => $this->datei('anleitung.pdf')])->status);
        $this->assertSame(403, $this->post("/fahrzeuge/$fz/titelbild", ['dokument_id' => (string) $dok])->status);
        $this->assertSame(403, $this->post("/dateien/$dok/loeschen")->status);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
    }

    public function test_geteiltes_dokument_nur_durch_bearbeiter_loeschbar(): void
    {
        $eigen = $this->fahrzeug($this->mitgliedA);
        $fremd = $this->fahrzeug($this->bearbeiter);
        $dok = $this->dokument($eigen);
        (new DokumentRepository($this->pdo, $this->speicher))->zuordnen($dok, 'fahrzeug', $fremd);
        $this->als($this->mitgliedA);
        $this->assertSame(403, $this->post("/dateien/$dok/loeschen")->status);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
        $this->als($this->bearbeiter);
        $this->assertSame(303, $this->post("/dateien/$dok/loeschen")->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
    }

    public function test_titelbild_mit_dokument_eines_anderen_fahrzeugs_wird_abgelehnt(): void
    {
        $eigen = $this->fahrzeug($this->mitgliedA);
        $fremd = $this->fahrzeug($this->bearbeiter);
        $dok = $this->dokument($fremd, 'foto_gedreht.jpg', 'foto');
        $this->als($this->mitgliedA);
        $r = $this->post("/fahrzeuge/$eigen/titelbild", ['dokument_id' => (string) $dok]);
        $this->assertSame(303, $r->status);
        $this->assertNull($this->feld($eigen, 'titelbild_dokument_id'));
        $this->assertNull($this->feld($fremd, 'titelbild_dokument_id'));
    }

    public function test_mitglied_aenderungskonflikt_ergibt_409_und_fremde_aenderung_bleibt(): void
    {
        $fz = $this->fahrzeug($this->mitgliedA);
        $this->als($this->mitgliedA);
        $alterStand = (string) $this->feld($fz, 'geaendert_am');
        $this->pdo->exec("UPDATE fahrzeug SET bezeichnung = 'Von anderem', geaendert_am = DATE_ADD(geaendert_am, INTERVAL 1 SECOND) WHERE id = $fz");
        $r = $this->post("/fahrzeuge/$fz", $this->fahrzeugDaten(['bezeichnung' => 'Veraltet', '_stand' => $alterStand]));
        $this->assertSame(409, $r->status);
        $this->assertSame('Von anderem', $this->feld($fz, 'bezeichnung'));
    }

    // ---- Bearbeiter behalten alle Rechte ----

    public function test_bearbeiter_darf_cv_wert_und_datei_an_jedem_fahrzeug(): void
    {
        $fz = $this->fahrzeug($this->mitgliedA);
        $dec = $this->decoder($fz);
        $this->als($this->bearbeiter);
        $this->assertSame(303, $this->post("/decoder/$dec/cvs", ['cv' => '4', 'wert' => '1'])->status);
        $this->assertSame(303, $this->post("/decoder/$dec/funktionen", ['beschreibung' => [0 => 'Licht']])->status);
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/dateien", [], ['datei' => $this->datei('anleitung.pdf')])->status);
        $dok = (int) $this->pdo->query('SELECT id FROM dokument')->fetchColumn();
        $this->assertSame(303, $this->post("/dateien/$dok/loeschen")->status);
    }

    // ---- Templates ----

    public function test_templates_zeigen_steuerelemente_nur_wo_erlaubt(): void
    {
        $eigen = $this->fahrzeug($this->mitgliedA, null, 'Eigene');
        $fremd = $this->fahrzeug($this->bearbeiter, null, 'Fremde');
        $this->decoder($eigen);
        $decFremd = $this->decoder($fremd);
        $decEigen = (int) $this->pdo->query("SELECT id FROM decoder WHERE fahrzeug_id = $eigen")->fetchColumn();
        $this->als($this->mitgliedA);

        $liste = $this->get('/fahrzeuge')->body;
        $this->assertStringContainsString('+ Eigenes Fahrzeug anlegen', $liste);
        $this->assertStringNotContainsString('+ Fahrzeug anlegen', $liste);

        $eigenSeite = $this->get("/fahrzeuge/$eigen")->body;
        $this->assertStringContainsString("/fahrzeuge/$eigen/bearbeiten", $eigenSeite);
        $this->assertStringContainsString("/decoder/$decEigen/bearbeiten", $eigenSeite);
        $this->assertStringContainsString("/fahrzeuge/$eigen/decoder", $eigenSeite);
        $this->assertStringContainsString("/fahrzeuge/$eigen/dateien", $eigenSeite);
        $this->assertStringNotContainsString("/fahrzeuge/$eigen/loeschen", $eigenSeite);
        $this->assertStringContainsString("/decoder/$decEigen/funktionen\" novalidate", $this->get("/decoder/$decEigen/funktionen")->body);
        $this->assertStringContainsString('CV speichern', $this->get("/decoder/$decEigen/cvs")->body);

        $fremdSeite = $this->get("/fahrzeuge/$fremd")->body;
        $this->assertStringNotContainsString("/fahrzeuge/$fremd/bearbeiten", $fremdSeite);
        $this->assertStringNotContainsString("/decoder/$decFremd/bearbeiten", $fremdSeite);
        $this->assertStringNotContainsString("/fahrzeuge/$fremd/decoder", $fremdSeite);
        $this->assertStringNotContainsString("/fahrzeuge/$fremd/dateien", $fremdSeite);
        $this->assertStringNotContainsString('CV speichern', $this->get("/decoder/$decFremd/cvs")->body);
        $this->assertStringNotContainsString('Funktionen speichern', $this->get("/decoder/$decFremd/funktionen")->body);

        $this->als($this->bearbeiter);
        $liste = $this->get('/fahrzeuge')->body;
        $this->assertStringContainsString('+ Fahrzeug anlegen', $liste);
        $this->assertStringNotContainsString('Eigenes Fahrzeug', $liste);
        $this->assertStringContainsString("/fahrzeuge/$eigen/loeschen", $this->get("/fahrzeuge/$eigen")->body);
    }

    // ---- Migration ----

    public function test_migration_003_auf_002_bestehende_fahrzeuge_haben_null(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        self::alleTabellenLoeschen($this->pdo);
        $verz = sys_get_temp_dir() . '/emc_mig_' . bin2hex(random_bytes(4));
        mkdir($verz);
        $migrationen = dirname(__DIR__, 3) . '/migrations';
        foreach (['001_grundgeruest.sql', '002_spurweiten_start.sql'] as $f) {
            copy($migrationen . '/' . $f, $verz . '/' . $f);
        }
        $migrator = new Migrator($this->pdo, $verz);
        $migrator->ausfuehren();
        $this->pdo->exec("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) SELECT 'EMC-0001', 'Alt', id FROM spurweite LIMIT 1");

        copy($migrationen . '/003_fahrzeug_angelegt_von.sql', $verz . '/003_fahrzeug_angelegt_von.sql');
        $migrator->ausfuehren();
        $this->assertNull($this->pdo->query('SELECT angelegt_von_benutzer_id FROM fahrzeug')->fetchColumn());

        // ON DELETE SET NULL
        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('A', 'B')");
        $this->pdo->exec("INSERT INTO benutzer (person_id, email, passwort_hash) VALUES (" . $this->pdo->lastInsertId() . ", 'x@y.de', 'h')");
        $bid = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("UPDATE fahrzeug SET angelegt_von_benutzer_id = $bid");
        $this->pdo->exec("DELETE FROM benutzer WHERE id = $bid");
        $this->assertNull($this->pdo->query('SELECT angelegt_von_benutzer_id FROM fahrzeug')->fetchColumn());

        foreach (glob($verz . '/*') as $f) {
            unlink($f);
        }
        rmdir($verz);
        // Datenbank für Folgetests wieder vollständig herstellen.
        self::alleTabellenLoeschen($this->pdo);
        (new Migrator($this->pdo, $migrationen))->ausfuehren();
    }
}
