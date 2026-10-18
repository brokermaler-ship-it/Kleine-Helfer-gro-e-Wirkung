<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Fahrzeuge;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

final class FahrzeugHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private int $spurweite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spurweite = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
    }

    private function app(): App
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung));
    }

    private function anmelden(string $rolle): int
    {
        $id = $this->legeBenutzerAn($rolle . '@verein.de', 'geheim-passwort-1', $rolle);
        $this->sitzungAnmelden($this->sitzung, $id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        return $id;
    }

    private function get(string $pfad, array $query = []): Response
    {
        return $this->app()->handle(new Request('GET', $pfad, $query));
    }

    private function post(string $pfad, array $daten = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)]));
    }

    /** @return array<string,mixed> */
    private function formdaten(array $u = []): array
    {
        return $u + ['bezeichnung' => 'BR 218', 'spurweite_id' => (string) $this->spurweite, 'gattung' => 'lok', 'stromsystem' => '2L', 'status' => 'einsatzbereit'];
    }

    public function test_startseite_leitet_zur_liste(): void
    {
        $this->anmelden('mitglied');
        $this->assertSame('/fahrzeuge', $this->get('/')->headers['Location']);
    }

    public function test_mitglied_darf_lesen_aber_nicht_anlegen(): void
    {
        $this->anmelden('mitglied');
        $this->assertSame(200, $this->get('/fahrzeuge')->status);
        $this->assertSame(403, $this->get('/fahrzeuge/neu')->status);
        $this->assertSame(403, $this->post('/fahrzeuge', $this->formdaten())->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    public function test_bearbeiter_legt_an_und_protokoll_entsteht(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->post('/fahrzeuge', $this->formdaten());
        $this->assertSame(303, $r->status);
        $id = (int) $this->pdo->query('SELECT id FROM fahrzeug')->fetchColumn();
        $this->assertSame('/fahrzeuge/' . $id, $r->headers['Location']);
        $this->assertSame('angelegt', $this->pdo->query("SELECT aktion FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $id")->fetchColumn());

        $detail = $this->get('/fahrzeuge/' . $id);
        $this->assertSame(200, $detail->status);
        $this->assertStringContainsString('EMC-0001', $detail->body);
        $this->assertStringContainsString('BR 218', $detail->body);
        foreach (['Übersicht', 'Digital', 'Dateien', 'Verlauf'] as $tab) {
            $this->assertStringContainsString($tab, $detail->body);
        }
        $this->assertStringContainsString('Verein', $detail->body);
    }

    public function test_formularfehler_werden_angezeigt(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->post('/fahrzeuge', $this->formdaten(['bezeichnung' => '', 'baureihe' => 'BR 99']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte eine Bezeichnung eingeben.', $r->body);
        $this->assertStringContainsString('BR 99', $r->body);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
    }

    public function test_unbekannte_spurweite_wird_abgelehnt(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->post('/fahrzeuge', $this->formdaten(['spurweite_id' => '999999']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte eine Spurweite wählen.', $r->body);
    }

    public function test_aendern_ohne_aenderung_ist_kein_konflikt_und_konflikt_wird_gemeldet(): void
    {
        $this->anmelden('bearbeiter');
        $this->post('/fahrzeuge', $this->formdaten());
        $id = (int) $this->pdo->query('SELECT id FROM fahrzeug')->fetchColumn();
        $stand = (string) $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = $id")->fetchColumn();

        $form = $this->get("/fahrzeuge/$id/bearbeiten");
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('name="_stand"', $form->body);

        $ok = $this->post("/fahrzeuge/$id", $this->formdaten(['_stand' => $stand]));
        $this->assertSame(303, $ok->status);

        $neu = $this->post("/fahrzeuge/$id", $this->formdaten(['bezeichnung' => 'Neu', '_stand' => $stand]));
        $this->assertSame(409, $neu->status);
        $this->assertStringContainsString('inzwischen von jemand anderem geändert', $neu->body);
        $this->assertStringContainsString('Neu', $neu->body);
        $this->assertSame('BR 218', $this->pdo->query("SELECT bezeichnung FROM fahrzeug WHERE id = $id")->fetchColumn());
    }

    public function test_validierungsfehler_behaelt_urspruenglichen_stand(): void
    {
        $this->anmelden('bearbeiter');
        $this->post('/fahrzeuge', $this->formdaten());
        $id = (int) $this->pdo->query('SELECT id FROM fahrzeug')->fetchColumn();
        $s0 = (string) $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = $id")->fetchColumn();

        // B speichert zwischenzeitlich.
        $this->assertSame(303, $this->post("/fahrzeuge/$id", $this->formdaten(['bezeichnung' => 'Von B', '_stand' => $s0]))->status);

        // A sendet ungültige Daten mit dem alten Stand.
        $fehler = $this->post("/fahrzeuge/$id", $this->formdaten(['bezeichnung' => '', '_stand' => $s0]));
        $this->assertSame(422, $fehler->status);
        $this->assertStringContainsString('name="_stand" value="' . $s0 . '"', $fehler->body);

        // A korrigiert und sendet den Stand aus der 422-Antwort: Konflikt, B bleibt erhalten.
        preg_match('/name="_stand" value="([^"]*)"/', $fehler->body, $m);
        $neu = $this->post("/fahrzeuge/$id", $this->formdaten(['bezeichnung' => 'Von A', '_stand' => $m[1]]));
        $this->assertSame(409, $neu->status);
        $this->assertSame('Von B', $this->pdo->query("SELECT bezeichnung FROM fahrzeug WHERE id = $id")->fetchColumn());
    }

    public function test_aendern_protokolliert_nur_unterschiede(): void
    {
        $this->anmelden('bearbeiter');
        $this->post('/fahrzeuge', $this->formdaten());
        $id = (int) $this->pdo->query('SELECT id FROM fahrzeug')->fetchColumn();
        $stand = (string) $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = $id")->fetchColumn();
        $this->post("/fahrzeuge/$id", $this->formdaten(['status' => 'defekt', '_stand' => $stand]));
        $json = (string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND aktion = 'geaendert'")->fetchColumn();
        $this->assertSame(['status'], array_keys(json_decode($json, true)));
    }

    public function test_loeschen_mit_protokoll_und_404(): void
    {
        $this->anmelden('bearbeiter');
        $this->post('/fahrzeuge', $this->formdaten());
        $id = (int) $this->pdo->query('SELECT id FROM fahrzeug')->fetchColumn();
        $this->assertSame(303, $this->post("/fahrzeuge/$id/loeschen")->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn());
        $this->assertSame('geloescht', $this->pdo->query("SELECT aktion FROM aenderungsprotokoll WHERE objekt_id = $id ORDER BY id DESC LIMIT 1")->fetchColumn());
        $this->assertSame(404, $this->get("/fahrzeuge/$id")->status);
        $this->assertSame(404, $this->post("/fahrzeuge/$id", $this->formdaten(['_stand' => '2020-01-01 00:00:00.000000']))->status);
    }

    public function test_mitglied_darf_nicht_loeschen(): void
    {
        $this->anmelden('bearbeiter');
        $this->post('/fahrzeuge', $this->formdaten());
        $id = (int) $this->pdo->query('SELECT id FROM fahrzeug')->fetchColumn();
        $this->sitzung = [];
        $this->anmelden('mitglied');
        $this->assertSame(403, $this->post("/fahrzeuge/$id/loeschen")->status);
    }

    public function test_mitgliederfahrzeug_zeigt_externes_badge_und_filter_arrays_sind_harmlos(): void
    {
        $this->anmelden('bearbeiter');
        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('Anna', 'Bauer')");
        $pid = (int) $this->pdo->lastInsertId();
        $this->post('/fahrzeuge', $this->formdaten(['eigentuemer_person_id' => (string) $pid]));
        $id = (int) $this->pdo->query('SELECT id FROM fahrzeug')->fetchColumn();
        $this->assertStringContainsString('extern – Eigentum von Anna Bauer', $this->get("/fahrzeuge/$id")->body);
        $liste = $this->get('/fahrzeuge', ['q' => ['x'], 'spurweite' => ['1'], 'status' => ['a']]);
        $this->assertSame(200, $liste->status);
        $this->assertStringContainsString('extern – Eigentum von Anna Bauer', $this->get('/fahrzeuge')->body);
    }

    public function test_ungueltige_id_ist_404(): void
    {
        $this->anmelden('mitglied');
        $this->assertSame(404, $this->get('/fahrzeuge/abc')->status);
        $this->assertSame(404, $this->get('/fahrzeuge/99999999999999')->status);
    }
}
