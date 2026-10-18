<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Digital;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

final class DecoderHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private int $h0;
    private int $fz1;
    private int $fz2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->fz1 = $this->fahrzeug('V 200 060');
        $this->fz2 = $this->fahrzeug('BR 218');
    }

    private function fahrzeug(string $bez): int
    {
        $n = (int) $this->pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn() + 1;
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) VALUES (?, ?, ?)')
            ->execute([sprintf('EMC-%04d', $n), $bez, $this->h0]);
        return (int) $this->pdo->lastInsertId();
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

    private function decoderDaten(array $u = []): array
    {
        return $u + ['bezeichnung' => 'Motor', 'protokoll' => 'dcc', 'adresse' => '3'];
    }

    private function anzahl(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM decoder')->fetchColumn();
    }

    public function test_speichern_trotz_konflikt_mit_warnung(): void
    {
        $this->anmelden('bearbeiter');
        $this->assertSame(303, $this->post("/fahrzeuge/{$this->fz1}/decoder", $this->decoderDaten())->status);
        $this->assertSame([], array_filter($this->sitzung['_meldungen'] ?? [], fn($m) => $m['typ'] === 'warnung'));

        $r = $this->post("/fahrzeuge/{$this->fz2}/decoder", $this->decoderDaten());
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/{$this->fz2}#digital", $r->headers['Location']);
        $this->assertSame(2, $this->anzahl());
        $warnungen = array_values(array_filter($this->sitzung['_meldungen'], fn($m) => $m['typ'] === 'warnung'));
        $this->assertCount(1, $warnungen);
        $this->assertSame('Achtung: Adresse 3 (DCC) ist in Spur H0 bereits vergeben an EMC-0001 V 200 060.', $warnungen[0]['text']);
    }

    public function test_ungueltige_adresse_verhindert_speichern(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->post("/fahrzeuge/{$this->fz1}/decoder", $this->decoderDaten(['adresse' => '0']));
        $this->assertSame(422, $r->status);
        $this->assertSame(0, $this->anzahl());
        $this->assertStringContainsString('Für DCC sind Adressen von 1 bis 10239 möglich.', $r->body);
    }

    public function test_mfx_ohne_adresse_wird_null_gespeichert(): void
    {
        $this->anmelden('bearbeiter');
        $this->assertSame(303, $this->post("/fahrzeuge/{$this->fz1}/decoder", $this->decoderDaten(['protokoll' => 'mfx', 'adresse' => '']))->status);
        $this->assertNull($this->pdo->query('SELECT adresse FROM decoder')->fetchColumn());
    }

    public function test_dcc_ohne_adresse_und_unbekanntes_protokoll_abgelehnt(): void
    {
        $this->anmelden('bearbeiter');
        $r = $this->post("/fahrzeuge/{$this->fz1}/decoder", $this->decoderDaten(['adresse' => '']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte eine Adresse eingeben.', $r->body);
        $this->assertSame(422, $this->post("/fahrzeuge/{$this->fz1}/decoder", $this->decoderDaten(['protokoll' => 'xyz']))->status);
        $this->assertSame(422, $this->post("/fahrzeuge/{$this->fz1}/decoder", $this->decoderDaten(['bezeichnung' => '']))->status);
        $this->assertSame(0, $this->anzahl());
    }

    public function test_aendern_loeschen_und_protokoll(): void
    {
        $this->anmelden('bearbeiter');
        $this->post("/fahrzeuge/{$this->fz1}/decoder", $this->decoderDaten(['sound' => '1']));
        $id = (int) $this->pdo->query('SELECT id FROM decoder')->fetchColumn();
        $this->assertSame('1', (string) $this->pdo->query('SELECT sound FROM decoder')->fetchColumn());

        $form = $this->get("/decoder/$id/bearbeiten");
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('value="Motor"', $form->body);

        $this->assertSame(303, $this->post("/decoder/$id", $this->decoderDaten(['adresse' => '4']))->status);
        $this->assertSame('4', (string) $this->pdo->query('SELECT adresse FROM decoder')->fetchColumn());
        $this->assertSame('0', (string) $this->pdo->query('SELECT sound FROM decoder')->fetchColumn());

        $aktionen = $this->pdo->query("SELECT aktion FROM aenderungsprotokoll WHERE objekt_typ = 'decoder' AND objekt_id = $id ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['angelegt', 'geaendert'], $aktionen);
        $json = (string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'decoder' AND aktion = 'geaendert'")->fetchColumn();
        $this->assertSame($this->fz1, json_decode($json, true)['fahrzeug_id']['neu']);

        $r = $this->post("/decoder/$id/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/{$this->fz1}#digital", $r->headers['Location']);
        $this->assertSame(0, $this->anzahl());
        $this->assertSame('geloescht', $this->pdo->query("SELECT aktion FROM aenderungsprotokoll WHERE objekt_typ = 'decoder' ORDER BY id DESC LIMIT 1")->fetchColumn());
    }

    public function test_aendern_warnt_nicht_wegen_eigener_adresse(): void
    {
        $this->anmelden('bearbeiter');
        $this->post("/fahrzeuge/{$this->fz1}/decoder", $this->decoderDaten());
        $id = (int) $this->pdo->query('SELECT id FROM decoder')->fetchColumn();
        $this->sitzung['_meldungen'] = [];
        $this->post("/decoder/$id", $this->decoderDaten(['bezeichnung' => 'Motor neu']));
        $this->assertSame([], array_filter($this->sitzung['_meldungen'], fn($m) => $m['typ'] === 'warnung'));
    }

    public function test_mitglied_darf_nicht_schreiben(): void
    {
        $this->anmelden('mitglied');
        $this->assertSame(403, $this->post("/fahrzeuge/{$this->fz1}/decoder", $this->decoderDaten())->status);
        $this->assertSame(0, $this->anzahl());
    }

    public function test_unbekannte_ids_geben_404(): void
    {
        $this->anmelden('bearbeiter');
        $this->assertSame(404, $this->post('/fahrzeuge/99999/decoder', $this->decoderDaten())->status);
        $this->assertSame(404, $this->get('/decoder/99999/bearbeiten')->status);
        $this->assertSame(404, $this->post('/decoder/99999/loeschen')->status);
    }

    public function test_tab_digital_zeigt_decoder_und_konflikt(): void
    {
        $this->anmelden('bearbeiter');
        $this->post("/fahrzeuge/{$this->fz1}/decoder", $this->decoderDaten());
        $this->post("/fahrzeuge/{$this->fz2}/decoder", $this->decoderDaten(['bezeichnung' => 'Sound Heck']));
        $body = $this->get("/fahrzeuge/{$this->fz2}")->body;
        $this->assertStringContainsString('Sound Heck', $body);
        $this->assertStringContainsString('kurz', $body);
        $this->assertStringContainsString('⚠️', $body);
        $this->assertStringContainsString('/decoder/', $body);
        $this->assertStringContainsString('Decoder hinzufügen', $body);
    }

    public function test_api_liefert_konflikte_als_json(): void
    {
        $this->anmelden('mitglied');
        $this->pdo->prepare("INSERT INTO decoder (fahrzeug_id, bezeichnung, protokoll, adresse) VALUES (?, 'Motor', 'dcc', 3)")->execute([$this->fz1]);
        $r = $this->get('/api/adresspruefung', ['fahrzeug' => (string) $this->fz2, 'spurweite' => (string) $this->h0, 'protokoll' => 'dcc', 'adresse' => '3']);
        $this->assertSame(200, $r->status);
        $this->assertStringStartsWith('application/json', $r->headers['Content-Type']);
        $j = json_decode($r->body, true);
        $this->assertNull($j['fehler']);
        $this->assertSame('kurz', $j['adressart']);
        $this->assertSame('EMC-0001', $j['konflikte'][0]['inventarnummer']);
        $this->assertStringContainsString('bereits vergeben an EMC-0001 V 200 060', $j['warnung']);
    }

    public function test_api_fehler_und_unbekanntes_protokoll(): void
    {
        $this->anmelden('mitglied');
        $q = ['spurweite' => (string) $this->h0, 'protokoll' => 'dcc', 'adresse' => '0'];
        $j = json_decode($this->get('/api/adresspruefung', $q)->body, true);
        $this->assertSame('Für DCC sind Adressen von 1 bis 10239 möglich.', $j['fehler']);
        $this->assertSame([], $j['konflikte']);
        $j = json_decode($this->get('/api/adresspruefung', ['protokoll' => 'xyz'] + $q)->body, true);
        $this->assertNotNull($j['fehler']);
        $this->assertSame([], $j['konflikte']);
        $j = json_decode($this->get('/api/adresspruefung', ['adresse' => ['1']] + $q)->body, true);
        $this->assertNotNull($j['fehler']);
    }

    public function test_api_braucht_anmeldung(): void
    {
        $this->assertSame(303, $this->get('/api/adresspruefung')->status);
    }

    public function test_adressuebersicht(): void
    {
        $this->anmelden('mitglied');
        foreach ([$this->fz1, $this->fz2] as $f) {
            $this->pdo->prepare("INSERT INTO decoder (fahrzeug_id, bezeichnung, protokoll, adresse) VALUES (?, 'Motor', 'dcc', 3)")->execute([$f]);
        }
        $r = $this->get('/adressen');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('table-warning', $r->body);
        $this->assertStringContainsString('EMC-0002', $r->body);
        $r = $this->get('/adressen', ['spurweite' => (string) (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'N'")->fetchColumn()]);
        $this->assertStringNotContainsString('table-warning', $r->body);
        $this->assertSame(200, $this->get('/adressen', ['spurweite' => ['x']])->status);
    }
}
