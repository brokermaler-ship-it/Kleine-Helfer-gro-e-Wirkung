<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Wartung;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;
use Emc\Wartung\WartungsartRepository;

final class WartungsartHttpTest extends DbTestCase
{
    private array $sitzung = [];

    private function app(): App
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung));
    }

    private function anmelden(string $rolle): int
    {
        $id = $this->legeBenutzerAn($rolle . '@verein.de', 'geheim-passwort-1', $rolle);
        $this->sitzung = [];
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

    /** @return array<string,string> */
    private function formdaten(array $u = []): array
    {
        return $u + ['name' => 'Schleifer prüfen', 'gilt_fuer' => 'fahrzeug_antrieb', 'intervall_monate' => '6', 'intervall_stunden' => '', 'aktiv' => '1', 'sortierung' => '90'];
    }

    private function idVon(string $name): int
    {
        $st = $this->pdo->prepare('SELECT id FROM wartungsart WHERE name = ?');
        $st->execute([$name]);
        return (int) $st->fetchColumn();
    }

    private function meldung(string $typ): ?string
    {
        foreach ($this->sitzung['_meldungen'] ?? [] as $m) {
            if (($m['typ'] ?? null) === $typ) {
                return $m['text'];
            }
        }
        return null;
    }

    public function test_mitglied_hat_keinen_zugriff(): void
    {
        $this->anmelden('mitglied');
        $this->assertSame(403, $this->get('/admin/wartungsarten')->status);
        $this->assertSame(403, $this->post('/admin/wartungsarten', $this->formdaten())->status);
        $this->assertSame(403, $this->post('/admin/wartungsarten/' . $this->idVon('Gleise reinigen') . '/loeschen')->status);
        $this->assertSame(8, (int) $this->pdo->query('SELECT COUNT(*) FROM wartungsart')->fetchColumn());
        $this->assertStringNotContainsString('/admin/wartungsarten', $this->get('/fahrzeuge')->body);
    }

    public function test_fahrzeugwart_sieht_startbestand_und_menue(): void
    {
        $this->anmelden('fahrzeugwart');
        $r = $this->get('/admin/wartungsarten');
        $this->assertSame(200, $r->status);
        foreach (['Räder und Radschleifer reinigen', 'Getriebe und Lager schmieren', 'Haftreifen prüfen/tauschen', 'Motor/Kohlen prüfen',
                  'Kupplungen prüfen', 'Gleise reinigen', 'Weichen und Antriebe prüfen', 'Verkabelung und Stecker prüfen'] as $name) {
            $this->assertStringContainsString(e($name), $r->body);
        }
        $this->assertStringContainsString('6 Monate oder 20 h', $r->body);
        $this->assertStringContainsString('24 Monate oder 100 h', $r->body);
        $this->assertStringContainsString('Lok/Triebwagen', $r->body);
        // Menü: „Verwaltung“ mit nur diesem Eintrag.
        $this->assertStringContainsString('>Verwaltung<', $r->body);
        $this->assertStringContainsString('href="/admin/wartungsarten"', $r->body);
        $this->assertStringNotContainsString('href="/admin/spurweiten"', $r->body);
        $this->assertStringNotContainsString('href="/admin/benutzer"', $r->body);
        // Bearbeitungsformular
        $r = $this->get('/admin/wartungsarten', ['bearbeiten' => (string) $this->idVon('Gleise reinigen')]);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Wartungsart bearbeiten', $r->body);
        $this->assertStringContainsString('value="Gleise reinigen"', $r->body);
    }

    public function test_anlegen_aendern_deaktivieren_loeschen(): void
    {
        $benutzer = $this->anmelden('fahrzeugwart');
        $r = $this->post('/admin/wartungsarten', $this->formdaten());
        $this->assertSame(303, $r->status);
        $this->assertSame('/admin/wartungsarten', $r->headers['Location']);
        $this->assertSame('Die Wartungsart wurde gespeichert.', $this->meldung('erfolg'));
        $id = $this->idVon('Schleifer prüfen');
        $this->assertGreaterThan(0, $id);
        $zeile = (new WartungsartRepository($this->pdo))->finde($id);
        $this->assertSame('fahrzeug_antrieb', $zeile['gilt_fuer']);
        $this->assertSame(6, $zeile['intervall_monate']);
        $this->assertNull($zeile['intervall_stunden']);
        $this->assertSame(1, $zeile['aktiv']);
        $this->assertSame(90, $zeile['sortierung']);

        // ändern
        $this->sitzung['_meldungen'] = [];
        $r = $this->post('/admin/wartungsarten', $this->formdaten(['id' => (string) $id, 'name' => 'Schleifer prüfen und reinigen', 'intervall_stunden' => '30']));
        $this->assertSame(303, $r->status);
        $zeile = (new WartungsartRepository($this->pdo))->finde($id);
        $this->assertSame('Schleifer prüfen und reinigen', $zeile['name']);
        $this->assertSame(30, $zeile['intervall_stunden']);
        $this->assertStringContainsString('6 Monate oder 30 h', $this->get('/admin/wartungsarten')->body);

        // deaktivieren (Checkbox nicht gesendet)
        $daten = $this->formdaten(['id' => (string) $id, 'name' => 'Schleifer prüfen und reinigen']);
        unset($daten['aktiv']);
        $this->assertSame(303, $this->post('/admin/wartungsarten', $daten)->status);
        $this->assertSame(0, (new WartungsartRepository($this->pdo))->finde($id)['aktiv']);
        $this->assertStringContainsString('inaktiv', $this->get('/admin/wartungsarten')->body);

        // löschen
        $this->sitzung['_meldungen'] = [];
        $r = $this->post('/admin/wartungsarten/' . $id . '/loeschen');
        $this->assertSame(303, $r->status);
        $this->assertSame('/admin/wartungsarten', $r->headers['Location']);
        $this->assertSame('Die Wartungsart wurde gelöscht.', $this->meldung('erfolg'));
        $this->assertNull((new WartungsartRepository($this->pdo))->finde($id));

        // Änderungsprotokoll
        $aktionen = $this->pdo->query("SELECT aktion FROM aenderungsprotokoll WHERE objekt_typ = 'wartungsart' AND objekt_id = $id ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['angelegt', 'geaendert', 'geaendert', 'geloescht'], $aktionen);
        $this->assertSame($benutzer, (int) $this->pdo->query("SELECT benutzer_id FROM aenderungsprotokoll WHERE objekt_typ = 'wartungsart' LIMIT 1")->fetchColumn());
        $diff = json_decode((string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'wartungsart' AND aktion = 'geaendert' ORDER BY id LIMIT 1")->fetchColumn(), true);
        $this->assertSame(['alt' => 'Schleifer prüfen', 'neu' => 'Schleifer prüfen und reinigen'], $diff['name']);
        $this->assertSame(['alt' => null, 'neu' => 30], $diff['intervall_stunden']);
    }

    public function test_doppelter_name_und_fehler_422(): void
    {
        $this->anmelden('fahrzeugwart');
        $r = $this->post('/admin/wartungsarten', $this->formdaten(['name' => 'gleise REINIGEN', 'gilt_fuer' => 'anlage']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Diese Wartungsart gibt es schon.', $r->body);
        $this->assertSame(8, (int) $this->pdo->query('SELECT COUNT(*) FROM wartungsart')->fetchColumn());

        $r = $this->post('/admin/wartungsarten', $this->formdaten(['gilt_fuer' => 'anlage', 'intervall_stunden' => '10']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Anlagen haben keine Betriebsstunden', $r->body);

        $r = $this->post('/admin/wartungsarten', $this->formdaten(['intervall_monate' => '']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte ein Intervall in Monaten oder Betriebsstunden angeben.', $r->body);

        // Umbenennen auf einen vorhandenen Namen
        $r = $this->post('/admin/wartungsarten', $this->formdaten(['id' => (string) $this->idVon('Gleise reinigen'), 'name' => 'Kupplungen prüfen', 'gilt_fuer' => 'anlage']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Diese Wartungsart gibt es schon.', $r->body);
    }

    public function test_unbekannte_id_404(): void
    {
        $this->anmelden('admin');
        $this->assertSame(404, $this->post('/admin/wartungsarten', $this->formdaten(['id' => '999999']))->status);
        $this->assertSame(404, $this->post('/admin/wartungsarten/999999/loeschen')->status);
        $this->assertSame(200, $this->get('/admin/wartungsarten', ['bearbeiten' => '999999'])->status);
    }

    public function test_passende_fuer(): void
    {
        $repo = new WartungsartRepository($this->pdo);
        $namen = fn(array $l) => array_column($l, 'name');
        $this->assertCount(5, $repo->passendeFuer('fahrzeug', 'lok'));
        $this->assertCount(5, $repo->passendeFuer('fahrzeug', 'triebwagen'));
        $this->assertSame(['Räder und Radschleifer reinigen', 'Kupplungen prüfen'], $namen($repo->passendeFuer('fahrzeug', 'wagen')));
        $this->assertSame(['Räder und Radschleifer reinigen', 'Kupplungen prüfen'], $namen($repo->passendeFuer('fahrzeug', 'sonderfahrzeug')));
        $this->assertSame(['Gleise reinigen', 'Weichen und Antriebe prüfen', 'Verkabelung und Stecker prüfen'], $namen($repo->passendeFuer('anlage', null)));
        $this->pdo->exec("UPDATE wartungsart SET aktiv = 0 WHERE name = 'Gleise reinigen'");
        $this->assertSame(['Weichen und Antriebe prüfen', 'Verkabelung und Stecker prüfen'], $namen($repo->passendeFuer('anlage', null)));
        $this->assertSame(range(10, 80, 10), array_column($repo->alle(), 'sortierung'));
    }
}
