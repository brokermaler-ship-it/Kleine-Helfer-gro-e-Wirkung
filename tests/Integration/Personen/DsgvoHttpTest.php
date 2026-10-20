<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Personen;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Container;
use Emc\Core\Session;
use Emc\Dateien\DokumentRepository;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\OneDrive\Auftraege;
use Emc\Personen\DsgvoLoeschung;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;

/** DSGVO-Löschung über die Oberfläche: nur für Admins, Vorschau mit Hinweisen, Bestätigung per Nachname. */
final class DsgvoHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private string $speicher;
    private int $admin;
    private int $person;
    private int $fz1;
    private int $fz2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
        $this->admin = $this->legeBenutzerAn('admin@verein.de', rolle: 'admin');
        $this->pdo->prepare("INSERT INTO person (vorname, nachname, email, telefon) VALUES ('Carla', 'Beispiel', 'carla@privat.de', '0345 987654')")->execute();
        $this->person = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO benutzer (person_id, email, passwort_hash, rolle) VALUES (?, 'carla@verein.de', 'x', 'mitglied')")->execute([$this->person]);
        $this->fz1 = $this->fahrzeug('EMC-0101');
        $this->fz2 = $this->fahrzeug('EMC-0102');
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
        $this->pdo->exec('DROP TRIGGER IF EXISTS test_dsgvo_http_sperre');
    }

    private function fahrzeug(string $nummer): int
    {
        $this->pdo->prepare(
            "INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, eigentuemer_person_id) SELECT ?, ?, id, ? FROM spurweite ORDER BY id LIMIT 1"
        )->execute([$nummer, 'Lok ' . $nummer, $this->person]);
        return (int) $this->pdo->lastInsertId();
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

    private function get(string $pfad): Response
    {
        return $this->app()->handle(new Request('GET', $pfad));
    }

    /** @param array<string,mixed> $daten */
    private function post(string $pfad, array $daten = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)]));
    }

    private function personDa(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM person WHERE id = ' . $this->person)->fetchColumn() === 1;
    }

    public function test_bearbeiter_und_fahrzeugwart_erhalten_403(): void
    {
        foreach (['bearbeiter', 'fahrzeugwart'] as $rolle) {
            $this->als($this->legeBenutzerAn($rolle . '@verein.de', rolle: $rolle));
            $this->assertSame(403, $this->get("/personen/{$this->person}/dsgvo")->status, $rolle);
            $this->assertSame(403, $this->post("/personen/{$this->person}/dsgvo", ['nachname' => 'Beispiel'])->status, $rolle);
        }
        $this->assertTrue($this->personDa());
    }

    public function test_admin_sieht_vorschau_mit_fahrzeugen_und_hinweisen(): void
    {
        $this->als($this->admin);
        $a = $this->get("/personen/{$this->person}/dsgvo");

        $this->assertSame(200, $a->status);
        $this->assertStringContainsString('EMC-0101', $a->body);
        $this->assertStringContainsString('EMC-0102', $a->body);
        $this->assertStringContainsString('carla@verein.de', $a->body);
        $this->assertSame(2, substr_count($a->body, 'name="uebergeben[]"'));
        $this->assertStringContainsString('an den Verein übergeben', $a->body);
        $this->assertStringContainsString('Ältere Datensicherungen', $a->body);
        $this->assertStringContainsString('12 Monaten', $a->body);
        $this->assertStringContainsString('Freitexte', $a->body);
        $this->assertStringContainsString('Rechnungen', $a->body);
        $this->assertStringContainsString('Schreibweise', $a->body);
        $this->assertStringContainsString('Microsoft-Konto', $a->body);
        $this->assertStringContainsString('ehemaliges Mitglied', $a->body);
        $this->assertStringContainsString('name="nachname"', $a->body);
        $this->assertSame(404, $this->get('/personen/999999/dsgvo')->status);
    }

    public function test_warnung_wenn_onedrive_ueber_konto_der_person_laeuft(): void
    {
        $this->pdo->prepare("INSERT INTO einstellung (schluessel, wert) VALUES ('onedrive_konto', 'carla@privat.de')")->execute();
        $this->als($this->admin);

        $this->assertStringContainsString('Die OneDrive-Verbindung läuft über ein Microsoft-Konto dieser Person', $this->get("/personen/{$this->person}/dsgvo")->body);
    }

    public function test_falscher_nachname_loescht_nichts(): void
    {
        $this->als($this->admin);
        $a = $this->post("/personen/{$this->person}/dsgvo", ['nachname' => 'Beispie', 'uebergeben' => [(string) $this->fz1]]);

        $this->assertSame(422, $a->status);
        $this->assertStringContainsString('Bitte den Nachnamen zur Bestätigung eingeben.', $a->body);
        $this->assertMatchesRegularExpression('/value="' . $this->fz1 . '"[^>]*checked/', $a->body);
        $this->assertTrue($this->personDa());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    public function test_korrekte_bestaetigung_loescht_und_uebergibt(): void
    {
        $this->als($this->admin);
        $a = $this->post("/personen/{$this->person}/dsgvo", ['nachname' => '  bEISPIEL ', 'uebergeben' => [(string) $this->fz2]]);

        $this->assertSame(303, $a->status);
        $this->assertSame('/personen', $a->headers['Location']);
        $this->assertFalse($this->personDa());
        $this->assertSame([$this->fz2], array_map('intval', $this->pdo->query('SELECT id FROM fahrzeug')->fetchAll(\PDO::FETCH_COLUMN)));
        $this->assertNull($this->pdo->query("SELECT eigentuemer_person_id FROM fahrzeug WHERE id = {$this->fz2}")->fetchColumn() ?: null);
        $this->assertStringContainsString('Die Person wurde nach DSGVO gelöscht.', $this->get('/personen')->body);
    }

    public function test_eigene_person_zeigt_grund_ohne_formular(): void
    {
        $this->als($this->admin);
        $eigene = (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = {$this->admin}")->fetchColumn();

        $a = $this->get("/personen/$eigene/dsgvo");
        $this->assertSame(200, $a->status);
        $this->assertStringContainsString('Die eigene Person kann nicht nach DSGVO gelöscht werden.', $a->body);
        $this->assertStringNotContainsString('name="nachname"', $a->body);

        $b = $this->post("/personen/$eigene/dsgvo", ['nachname' => 'Muster']);
        $this->assertSame(422, $b->status);
        $this->assertStringContainsString('Die eigene Person kann nicht nach DSGVO gelöscht werden.', $b->body);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM person WHERE id = $eigene")->fetchColumn());
    }

    public function test_fremdes_fahrzeug_bei_uebergabe_zeigt_meldung(): void
    {
        $this->als($this->admin);
        $a = $this->post("/personen/{$this->person}/dsgvo", ['nachname' => 'Beispiel', 'uebergeben' => ['999999']]);

        $this->assertSame(422, $a->status);
        $this->assertStringContainsString('Es können nur Fahrzeuge dieser Person an den Verein übergeben werden.', $a->body);
        $this->assertTrue($this->personDa());
    }

    public function test_sperrkonflikt_meldet_bitte_erneut_versuchen(): void
    {
        $this->pdo->exec("CREATE TRIGGER test_dsgvo_http_sperre BEFORE DELETE ON person FOR EACH ROW SIGNAL SQLSTATE '40001' SET MESSAGE_TEXT = 'Deadlock simuliert'");
        $this->als($this->admin);
        $a = $this->post("/personen/{$this->person}/dsgvo", ['nachname' => 'Beispiel']);

        $this->assertSame(409, $a->status);
        $this->assertStringContainsString('Die Löschung konnte gerade nicht ausgeführt werden. Bitte erneut versuchen.', $a->body);
        $this->assertStringNotContainsString('Deadlock simuliert', $a->body);
        $this->assertTrue($this->personDa());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM benutzer WHERE email = 'carla@verein.de'")->fetchColumn());
    }

    public function test_schaltflaeche_im_personenformular_nur_fuer_admin(): void
    {
        $this->als($this->admin);
        $a = $this->get("/personen/{$this->person}/bearbeiten");
        $this->assertStringContainsString('Person endgültig löschen (DSGVO)', $a->body);
        $this->assertStringContainsString('/personen/' . $this->person . '/dsgvo', $a->body);

        $this->als($this->legeBenutzerAn('bearbeiter@verein.de', rolle: 'bearbeiter'));
        $b = $this->get("/personen/{$this->person}/bearbeiten");
        $this->assertSame(200, $b->status);
        $this->assertStringNotContainsString('Person endgültig löschen (DSGVO)', $b->body);
    }

    public function test_ungueltige_fahrzeugauswahl_bricht_ab(): void
    {
        $this->als($this->admin);
        foreach ([['abc'], [(string) $this->fz1, '1x'], [[(string) $this->fz1]], (string) $this->fz1, ['-1'], ['']] as $wert) {
            $a = $this->post("/personen/{$this->person}/dsgvo", ['nachname' => 'Beispiel', 'uebergeben' => $wert]);
            $this->assertSame(422, $a->status, json_encode($wert));
            $this->assertStringContainsString('Ungültige Fahrzeugauswahl.', $a->body);
        }
        $this->assertTrue($this->personDa());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    public function test_bestaetigung_mit_eszett_nbsp_und_nfd(): void
    {
        $this->als($this->admin);
        foreach (["STRAUSS", "\u{00A0}strauß\u{00A0}", "Strau\u{00DF} "] as $eingabe) {
            $this->pdo->prepare("INSERT INTO person (vorname, nachname) VALUES ('Eva', 'Strauß')")->execute();
            $id = (int) $this->pdo->lastInsertId();
            $a = $this->post("/personen/$id/dsgvo", ['nachname' => $eingabe]);
            $this->assertSame(303, $a->status, $eingabe);
        }
        if (class_exists(\Normalizer::class)) {
            $this->pdo->prepare("INSERT INTO person (vorname, nachname) VALUES ('Eva', 'Müller')")->execute();
            $id = (int) $this->pdo->lastInsertId();
            $this->assertSame(303, $this->post("/personen/$id/dsgvo", ['nachname' => "Mu\u{0308}ller"])->status);
        }
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM person WHERE vorname = 'Eva'")->fetchColumn());
    }

    public function test_inzwischen_geloeschte_person_leitet_um(): void
    {
        $this->pdo->prepare("INSERT INTO person (vorname, nachname) VALUES ('Eva', 'Weg')")->execute();
        $id = (int) $this->pdo->lastInsertId();
        $this->als($this->admin);
        $app = $this->app();
        // Ein anderer löscht die Person, nachdem der Controller sie geladen hat (der Dienst wird erst danach gebaut).
        $app->container()->set(DsgvoLoeschung::class, function (Container $c) use ($id): DsgvoLoeschung {
            $this->pdo->prepare('DELETE FROM person WHERE id = ?')->execute([$id]);
            return new DsgvoLoeschung($c->get('pdo'), $c->get(DokumentRepository::class), $c->get(Aenderungsprotokoll::class),
                $c->get(FahrzeugRepository::class), $c->get(Auftraege::class), null);
        });
        $a = $app->handle(new Request('POST', "/personen/$id/dsgvo", [], ['nachname' => 'Weg', '_csrf' => str_repeat('a', 64)]));

        $this->assertSame(303, $a->status);
        $this->assertSame('/personen', $a->headers['Location']);
        $this->assertStringContainsString('Die Person wurde inzwischen bereits gelöscht.', $this->get('/personen')->body);
    }

    public function test_hinweis_auf_geteilte_mail(): void
    {
        $this->als($this->admin);
        $this->assertStringContainsString('die auch eine andere Person nutzt', $this->get("/personen/{$this->person}/dsgvo")->body);
    }
}
