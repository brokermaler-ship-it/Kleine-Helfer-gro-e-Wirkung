<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Digital;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Digital\FunktionCsvImport;
use Emc\Digital\FunktionRepository;
use Emc\Tests\Integration\DbTestCase;

final class FunktionHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private int $dec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->dec = FunktionRepositoryTest::neuerDecoder($this->pdo);
    }

    private function anmelden(string $rolle): void
    {
        $this->sitzung['benutzer_id'] = $this->legeBenutzerAn($rolle . '@verein.de', 'geheim-passwort-1', $rolle);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    private function app(): App
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung));
    }

    private function get(string $pfad, array $q = []): Response
    {
        return $this->app()->handle(new Request('GET', $pfad, $q));
    }

    private function post(string $pfad, array $d = [], array $dateien = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $d + ['_csrf' => str_repeat('a', 64)], $dateien));
    }

    private function upload(string $inhalt): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, $inhalt);
        return ['datei' => ['name' => 'f.csv', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($inhalt)]];
    }

    private function belegung(): array
    {
        return (new FunktionRepository($this->pdo))->fuerDecoder($this->dec);
    }

    public function test_csv_vorlage_download(): void
    {
        $this->anmelden('mitglied');
        $r = $this->get('/funktionen/vorlage.csv');
        $this->assertSame(200, $r->status);
        $this->assertSame('text/csv; charset=utf-8', $r->headers['Content-Type']);
        $this->assertStringContainsString('funktionen-vorlage.csv', $r->headers['Content-Disposition']);
        $this->assertSame(FunktionCsvImport::VORLAGE, $r->body);
    }

    public function test_mitglied_sieht_tabelle_darf_nicht_schreiben(): void
    {
        $this->anmelden('mitglied');
        $r = $this->get("/decoder/{$this->dec}/funktionen");
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Nur belegte Tasten', $r->body);
        $this->assertStringNotContainsString('Funktionen speichern', $r->body);
        $this->assertSame(403, $this->post("/decoder/{$this->dec}/funktionen", ['beschreibung' => [0 => 'Licht']])->status);
        $this->assertSame(200, $this->get("/decoder/{$this->dec}/cvs")->status);
        $this->assertSame(403, $this->post("/decoder/{$this->dec}/cvs", ['cv' => '1', 'wert' => '3'])->status);
    }

    public function test_formular_speichern_und_protokoll(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->post("/decoder/{$this->dec}/funktionen", ['beschreibung' => [0 => 'Licht', 1 => '', 2 => 'Pfeife'], 'moment' => [2 => '1']]);
        $this->assertSame(303, $r->status);
        $this->assertSame([0, 2], array_keys($this->belegung()));
        $this->assertSame('moment', $this->belegung()[2]['schaltart']);
        $eintrag = $this->pdo->query("SELECT aktion, aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'decoder'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('geaendert', $eintrag['aktion']);
        $this->assertSame(['funktionen' => ['alt' => 0, 'neu' => 2]], json_decode($eintrag['aenderungen'], true));
    }

    public function test_csv_upload_vorschau_und_uebernehmen(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->post("/decoder/{$this->dec}/funktionen/csv", [], $this->upload("Taste;Beschreibung;Schaltart\r\nF 1;Fahrger\xe4usch;Dauer\r\n"));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Fahrgeräusch', $r->body);
        $this->assertStringContainsString('Die bisherige Belegung wird ersetzt.', $r->body);
        $this->assertSame([], $this->belegung(), 'Vorschau speichert noch nichts');
        $this->assertArrayHasKey('csv_vorschau_' . $this->dec, $this->sitzung);

        $this->assertSame(303, $this->post("/decoder/{$this->dec}/funktionen/csv/uebernehmen")->status);
        $this->assertSame('Fahrgeräusch', $this->belegung()[1]['beschreibung']);
        $this->assertArrayNotHasKey('csv_vorschau_' . $this->dec, $this->sitzung);
    }

    public function test_csv_mit_fehlern_nicht_uebernehmbar(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->post("/decoder/{$this->dec}/funktionen/csv", [], $this->upload("F40;X;Dauer\nF1;Ok;Dauer\n"));
        $this->assertStringContainsString('Zeile 1: Taste „F40“ ist ungültig', $r->body);
        $this->assertStringNotContainsString('/csv/uebernehmen', $r->body);
        $this->post("/decoder/{$this->dec}/funktionen/csv/uebernehmen");
        $this->assertSame([], $this->belegung());
    }

    public function test_leere_und_fehlende_datei_abgelehnt(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->post("/decoder/{$this->dec}/funktionen/csv", [], $this->upload(''));
        $this->assertSame(303, $r->status);
        $r = $this->post("/decoder/{$this->dec}/funktionen/csv");
        $this->assertSame(303, $r->status);
        $texte = array_column($this->sitzung['_meldungen'], 'text');
        $this->assertContains('Die Datei ist leer.', $texte);
        $this->assertContains('Bitte eine CSV-Datei auswählen.', $texte);
        $this->assertArrayNotHasKey('csv_vorschau_' . $this->dec, $this->sitzung);
    }

    public function test_zu_grosse_datei_abgelehnt(): void
    {
        $this->anmelden('bearbeiter');
        $this->post("/decoder/{$this->dec}/funktionen/csv", [], $this->upload(str_repeat('a', 1048577)));
        $this->assertContains('Die Datei ist zu groß (höchstens 1 MB).', array_column($this->sitzung['_meldungen'], 'text'));
    }

    public function test_vorlage_speichern_und_anwenden(): void
    {
        $this->anmelden('bearbeiter');
        $this->post("/decoder/{$this->dec}/funktionen", ['beschreibung' => [0 => 'Licht']]);
        $this->post("/decoder/{$this->dec}/vorlage", ['name' => 'Standard']);
        $b = FunktionRepositoryTest::neuerDecoder($this->pdo);
        $vid = (int) $this->pdo->query('SELECT id FROM funktionsvorlage')->fetchColumn();
        $this->post("/decoder/$b/vorlage-anwenden", ['vorlage_id' => (string) $vid]);
        $this->assertSame([0], array_keys((new FunktionRepository($this->pdo))->fuerDecoder($b)));
        $this->post("/decoder/{$this->dec}/vorlage", ['name' => 'Standard']);
        $this->assertContains('Eine Vorlage mit diesem Namen gibt es bereits.', array_column($this->sitzung['_meldungen'], 'text'));
    }

    public function test_cv_speichern_validieren_loeschen(): void
    {
        $this->anmelden('bearbeiter');
        $this->assertSame(303, $this->post("/decoder/{$this->dec}/cvs", ['cv' => '29', 'wert' => '6'])->status);
        $r = $this->post("/decoder/{$this->dec}/cvs", ['cv' => '2000', 'wert' => '300']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('CV muss zwischen 1 und 1024 liegen.', $r->body);
        $this->assertStringContainsString('Wert muss zwischen 0 und 255 liegen.', $r->body);
        $this->post("/decoder/{$this->dec}/cvs", ['cv' => ['x'], 'wert' => '1']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM cv_wert')->fetchColumn());
        $this->post("/decoder/{$this->dec}/cvs/29/loeschen");
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cv_wert')->fetchColumn());
    }

    public function test_unbekannter_decoder_404_und_tab_zeigt_zaehler(): void
    {
        $this->anmelden('bearbeiter');
        $this->assertSame(404, $this->get('/decoder/99999/funktionen')->status);
        $this->post("/decoder/{$this->dec}/funktionen", ['beschreibung' => [0 => 'Licht', 1 => 'Sound']]);
        $this->post("/decoder/{$this->dec}/cvs", ['cv' => '1', 'wert' => '3']);
        $fz = (int) $this->pdo->query('SELECT fahrzeug_id FROM decoder')->fetchColumn();
        $this->assertStringContainsString('2 Tasten belegt, 1 CVs', $this->get("/fahrzeuge/$fz")->body);
    }
}
