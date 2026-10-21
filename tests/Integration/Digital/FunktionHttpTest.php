<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Digital;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Uploadgrenzen;
use Emc\Digital\FunktionCsvImport;
use Emc\Digital\PdfText;
use Emc\Digital\FunktionRepository;
use Emc\Tests\Integration\DbTestCase;
use Emc\Tests\Support\MiniPdf;

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
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn($rolle . '@verein.de', 'geheim-passwort-1', $rolle));
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

    private function uploadPdf(string $inhalt, string $name = 'anleitung.pdf'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($tmp, $inhalt);
        return ['datei' => ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($inhalt)]];
    }

    /** @return list<string> */
    private function meldungen(): array
    {
        return array_column($this->sitzung['_meldungen'] ?? [], 'text');
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

    public function test_formular_bis_f68(): void
    {
        $this->anmelden('bearbeiter');
        (new FunktionRepository($this->pdo))->ersetzen($this->dec, [['taste' => 40, 'beschreibung' => 'Ansage Bahnsteig', 'schaltart' => 'moment']]);
        $r = $this->get("/decoder/{$this->dec}/funktionen");
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('name="beschreibung[68]"', $r->body);
        $this->assertStringNotContainsString('beschreibung[69]', $r->body);
        $this->assertMatchesRegularExpression('/name="beschreibung\\[40\\]"\\s+value="Ansage Bahnsteig"/', $r->body);

        // Das Formular schickt alle angezeigten Felder zurück, auch F40.
        $r = $this->post("/decoder/{$this->dec}/funktionen", [
            'beschreibung' => [0 => 'Licht', 40 => 'Ansage Bahnsteig', 50 => 'Ansage', 69 => 'Zu hoch'],
            'moment' => [40 => '1'],
        ]);
        $this->assertSame(303, $r->status);
        $b = $this->belegung();
        $this->assertSame([0, 40, 50], array_keys($b));
        $this->assertSame('Ansage', $b[50]['beschreibung']);
        $this->assertSame('moment', $b[40]['schaltart']);
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
        $r = $this->post("/decoder/{$this->dec}/funktionen/csv", [], $this->upload("F69;X;Dauer\nF1;Ok;Dauer\n"));
        $this->assertStringContainsString('Zeile 1: Taste „F69“ ist ungültig', $r->body);
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

    public function test_pdf_upload_vorschau_und_uebernehmen(): void
    {
        $this->anmelden('bearbeiter');
        $pdf = MiniPdf::erzeuge([['Funktionsbelegung', 'F0 Licht', 'F2 Pfiff (M)', 'F5–F8 Ansagen']]);
        $r = $this->post("/decoder/{$this->dec}/funktionen/pdf", [], $this->uploadPdf($pdf));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('PDF-Vorschau', $r->body);
        $this->assertStringContainsString('2 Tasten erkannt.', $r->body);
        $this->assertStringContainsString('Hinweise', $r->body);
        $this->assertStringContainsString('Bereich F5–F8 („Ansagen“) nicht übernommen', $r->body);
        $this->assertStringContainsString('/pdf/uebernehmen', $r->body);
        $this->assertStringNotContainsString('/csv/uebernehmen', $r->body);
        $this->assertSame([], $this->belegung(), 'Vorschau speichert noch nichts');
        $this->assertArrayHasKey('pdf_vorschau_' . $this->dec, $this->sitzung);
        $this->assertSame(['zeilen', 'fehler'], array_keys($this->sitzung['pdf_vorschau_' . $this->dec]));

        $this->assertSame(303, $this->post("/decoder/{$this->dec}/funktionen/pdf/uebernehmen")->status);
        $b = $this->belegung();
        $this->assertSame([0, 2], array_keys($b));
        $this->assertSame('Licht', $b[0]['beschreibung']);
        $this->assertSame('dauer', $b[0]['schaltart']);
        $this->assertSame('Pfiff', $b[2]['beschreibung']);
        $this->assertSame('moment', $b[2]['schaltart']);
        $this->assertContains('Die Funktionen wurden aus der PDF-Datei übernommen.', $this->meldungen());
        $this->assertArrayNotHasKey('pdf_vorschau_' . $this->dec, $this->sitzung);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'decoder'")->fetchColumn());

        $this->assertSame(303, $this->post("/decoder/{$this->dec}/funktionen/pdf/uebernehmen")->status);
        $this->assertContains('Es liegt keine PDF-Vorschau vor. Bitte die Datei erneut hochladen.', $this->meldungen());
        $this->assertSame($b, $this->belegung());
    }

    public function test_pdf_seitengrenze_als_hinweis(): void
    {
        $this->anmelden('bearbeiter');
        $seiten = array_fill(0, 151, ['Weitere Angaben zur Lok']);
        $seiten[0] = ['F0 Licht vorne'];
        $seiten[150] = ['F1 Nicht gelesen'];
        $r = $this->post("/decoder/{$this->dec}/funktionen/pdf", [], $this->uploadPdf(MiniPdf::erzeuge($seiten)));
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Das PDF hat 151 Seiten; gelesen wurden die ersten 150.', $r->body);
        $this->assertStringContainsString('1 Taste erkannt.', $r->body);
        $this->assertStringNotContainsString('Nicht gelesen', $r->body);
    }

    public function test_pdf_ohne_text_und_ohne_tasten(): void
    {
        $this->anmelden('bearbeiter');
        (new FunktionRepository($this->pdo))->ersetzen($this->dec, [['taste' => 1, 'beschreibung' => 'Alt', 'schaltart' => 'dauer']]);
        $vorher = $this->belegung();
        $r = $this->post("/decoder/{$this->dec}/funktionen/pdf", [], $this->uploadPdf(MiniPdf::ohneText()));
        $this->assertSame(303, $r->status);
        $this->assertContains('Das PDF enthält keinen lesbaren Text (vermutlich ein Scan). Texterkennung gibt es nicht – bitte die Tasten von Hand eintragen.', $this->meldungen());
        $this->assertArrayNotHasKey('pdf_vorschau_' . $this->dec, $this->sitzung);

        $r = $this->post("/decoder/{$this->dec}/funktionen/pdf", [], $this->uploadPdf(MiniPdf::erzeuge([['Allgemeine Hinweise zur Wartung der Lok']])));
        $this->assertSame(303, $r->status);
        $this->assertContains('Im PDF wurden keine Funktionstasten gefunden (erwartet z. B. „F0 Licht“). Bitte die Tasten von Hand eintragen.', $this->meldungen());
        $this->assertArrayNotHasKey('pdf_vorschau_' . $this->dec, $this->sitzung);
        $this->assertSame($vorher, $this->belegung());
    }

    public function test_pdf_falsche_datei(): void
    {
        $this->anmelden('bearbeiter');
        $this->assertSame(303, $this->post("/decoder/{$this->dec}/funktionen/pdf")->status);
        $this->assertSame(303, $this->post("/decoder/{$this->dec}/funktionen/pdf", [], $this->uploadPdf(''))->status);
        $docx = "PK\x03\x04" . str_repeat("\0", 30) . '[Content_Types].xml word/document.xml';
        $this->assertSame(303, $this->post("/decoder/{$this->dec}/funktionen/pdf", [], $this->uploadPdf($docx, 'anleitung.pdf'))->status);
        $m = $this->meldungen();
        $this->assertContains('Bitte eine PDF-Datei auswählen.', $m);
        $this->assertContains('Die Datei ist leer.', $m);
        $this->assertContains('Die Datei ist kein PDF.', $m);
        $this->assertArrayNotHasKey('pdf_vorschau_' . $this->dec, $this->sitzung);
        $this->assertSame([], $this->belegung());
    }

    public function test_pdf_zu_gross_nach_uploadgrenze(): void
    {
        $this->anmelden('bearbeiter');
        $config = self::testConfig() + ['upload_max_mb' => 1];
        $app = App::erzeuge($config, new Session($this->sitzung));
        $d = $this->uploadPdf('%PDF-1.4' . str_repeat('x', 1048577));
        $r = $app->handle(new Request('POST', "/decoder/{$this->dec}/funktionen/pdf", [], ['_csrf' => str_repeat('a', 64)], $d));
        $this->assertSame(303, $r->status);
        $this->assertContains('Die Datei ist zu groß (höchstens 1 MB).', $this->meldungen());
        $seite = App::erzeuge($config, new Session($this->sitzung))->handle(new Request('GET', "/decoder/{$this->dec}/funktionen"))->body;
        $this->assertStringContainsString('als PDF (höchstens 1 MB).', $seite);
        $mb = max(1, intdiv(min(PdfText::MAX_BYTES, Uploadgrenzen::ausConfig(self::testConfig())->dateiBytes), 1048576));
        $this->assertStringContainsString('als PDF (höchstens ' . $mb . ' MB).', $this->get("/decoder/{$this->dec}/funktionen")->body);
    }

    public function test_pdf_wird_nicht_gespeichert(): void
    {
        $this->anmelden('bearbeiter');
        $speicher = dirname(__DIR__, 3) . '/storage';
        $zaehle = static function () use ($speicher): int {
            if (!is_dir($speicher)) {
                return 0;
            }
            $n = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($speicher, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $n++;
            }
            return $n;
        };
        $dokumente = (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn();
        $dateien = $zaehle();
        $this->assertSame(200, $this->post("/decoder/{$this->dec}/funktionen/pdf", [], $this->uploadPdf(MiniPdf::erzeuge([['F0 Licht vorne und hinten']])))->status);
        $this->assertSame(303, $this->post("/decoder/{$this->dec}/funktionen/pdf/uebernehmen")->status);
        $this->assertSame([0], array_keys($this->belegung()));
        $this->assertSame($dokumente, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
        $this->assertSame($dateien, $zaehle());
    }

    public function test_vorschau_nur_fuer_eigenen_decoder_und_quelle(): void
    {
        $this->anmelden('bearbeiter');
        $b = FunktionRepositoryTest::neuerDecoder($this->pdo, 'Zweiter');
        $this->assertSame(200, $this->post("/decoder/{$this->dec}/funktionen/pdf", [], $this->uploadPdf(MiniPdf::erzeuge([['F0 Licht vorne und hinten']])))->status);
        $this->assertSame(303, $this->post("/decoder/$b/funktionen/pdf/uebernehmen")->status);
        $this->assertContains('Es liegt keine PDF-Vorschau vor. Bitte die Datei erneut hochladen.', $this->meldungen());
        $this->assertSame([], (new FunktionRepository($this->pdo))->fuerDecoder($b));
        $this->assertArrayHasKey('pdf_vorschau_' . $this->dec, $this->sitzung);

        // CSV-Vorschau lässt sich nicht über die PDF-Route übernehmen und umgekehrt.
        unset($this->sitzung['pdf_vorschau_' . $this->dec]);
        $this->sitzung['_meldungen'] = [];
        $this->assertSame(200, $this->post("/decoder/{$this->dec}/funktionen/csv", [], $this->upload("F1;Sound;Dauer\n"))->status);
        $this->assertSame(303, $this->post("/decoder/{$this->dec}/funktionen/pdf/uebernehmen")->status);
        $this->assertContains('Es liegt keine PDF-Vorschau vor. Bitte die Datei erneut hochladen.', $this->meldungen());
        $this->assertSame([], $this->belegung());
        $this->assertArrayHasKey('csv_vorschau_' . $this->dec, $this->sitzung);

        unset($this->sitzung['csv_vorschau_' . $this->dec]);
        $this->assertSame(200, $this->post("/decoder/{$this->dec}/funktionen/pdf", [], $this->uploadPdf(MiniPdf::erzeuge([['F0 Licht vorne und hinten']])))->status);
        $this->assertSame(303, $this->post("/decoder/{$this->dec}/funktionen/csv/uebernehmen")->status);
        $this->assertContains('Es liegt keine CSV-Vorschau vor. Bitte die Datei erneut hochladen.', $this->meldungen());
        $this->assertSame([], $this->belegung());
    }

    public function test_pdf_rechte(): void
    {
        $this->anmelden('mitglied');
        $r = $this->post("/decoder/{$this->dec}/funktionen/pdf", [], $this->uploadPdf(MiniPdf::erzeuge([['F0 Licht vorne und hinten']])));
        $this->assertSame(403, $r->status);
        $this->assertArrayNotHasKey('pdf_vorschau_' . $this->dec, $this->sitzung);
        $this->sitzung['pdf_vorschau_' . $this->dec] = ['zeilen' => [['taste' => 0, 'beschreibung' => 'X', 'schaltart' => 'dauer']], 'fehler' => []];
        $this->assertSame(403, $this->post("/decoder/{$this->dec}/funktionen/pdf/uebernehmen")->status);
        $this->assertSame([], $this->belegung());
        $seite = $this->get("/decoder/{$this->dec}/funktionen")->body;
        $this->assertStringNotContainsString('PDF-Import', $seite);
        $this->assertStringNotContainsString('accept=".pdf,application/pdf"', $seite);

        $this->sitzung = [];
        $this->anmelden('bearbeiter');
        $seite = $this->get("/decoder/{$this->dec}/funktionen")->body;
        $this->assertStringContainsString('PDF-Import', $seite);
        $this->assertStringContainsString('accept=".pdf,application/pdf"', $seite);
        $this->assertStringContainsString('action="' . url("/decoder/{$this->dec}/funktionen/pdf") . '"', $seite);
    }

    public function test_pdf_ohne_csrf_abgelehnt(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->app()->handle(new Request('POST', "/decoder/{$this->dec}/funktionen/pdf", [], ['_csrf' => str_repeat('b', 64)], $this->uploadPdf(MiniPdf::erzeuge([['F0 Licht vorne und hinten']]))));
        $this->assertNotSame(200, $r->status);
        $this->assertArrayNotHasKey('pdf_vorschau_' . $this->dec, $this->sitzung);
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
