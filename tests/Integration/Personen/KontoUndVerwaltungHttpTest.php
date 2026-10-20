<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Personen;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

final class KontoUndVerwaltungHttpTest extends DbTestCase
{
    private array $sitzung = [];

    private function app(): App
    {
        $app = App::erzeuge(self::testConfig(), new Session($this->sitzung));
        $app->container()->get('router')->add('GET', '/fahrzeuge', fn() => new Response('Liste'));
        return $app;
    }

    private function anmelden(string $rolle = 'mitglied', bool $mussAendern = false, string $email = 'max@verein.de'): int
    {
        $id = $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle, true, $mussAendern);
        $this->sitzungAnmelden($this->sitzung, $id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        return $id;
    }

    /** @param array<string,mixed> $daten */
    private function post(string $pfad, array $daten = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)]));
    }

    public function test_erzwungene_passwortaenderung_und_freigabe(): void
    {
        $id = $this->anmelden('mitglied', true);
        $a = $this->app()->handle(new Request('GET', '/fahrzeuge'));
        $this->assertSame('/konto/passwort', $a->headers['Location']);

        $form = $this->app()->handle(new Request('GET', '/konto/passwort'));
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('name="altes_passwort"', $form->body);

        $b = $this->post('/konto/passwort', [
            'altes_passwort' => 'geheim-passwort-1', 'neues_passwort' => 'brandneues-passwort', 'wiederholung' => 'brandneues-passwort',
        ]);
        $this->assertSame(303, $b->status);
        $z = $this->pdo->query("SELECT * FROM benutzer WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $z['muss_passwort_aendern']);
        $this->assertTrue(password_verify('brandneues-passwort', $z['passwort_hash']));
        $this->assertSame(200, $this->app()->handle(new Request('GET', '/fahrzeuge'))->status);
    }

    public function test_passwortaenderung_fehler(): void
    {
        $id = $this->anmelden('mitglied', true);
        $falsch = $this->post('/konto/passwort', [
            'altes_passwort' => 'falsch', 'neues_passwort' => 'brandneues-passwort', 'wiederholung' => 'brandneues-passwort',
        ]);
        $this->assertSame(422, $falsch->status);
        $this->assertStringContainsString('Das bisherige Passwort ist falsch.', $falsch->body);

        $kurz = $this->post('/konto/passwort', ['altes_passwort' => 'geheim-passwort-1', 'neues_passwort' => 'kurz', 'wiederholung' => 'kurz']);
        $this->assertStringContainsString('mindestens 10 Zeichen', $kurz->body);

        $anders = $this->post('/konto/passwort', [
            'altes_passwort' => 'geheim-passwort-1', 'neues_passwort' => 'brandneues-passwort', 'wiederholung' => 'anderes-passwort-x',
        ]);
        $this->assertStringContainsString('stimmen nicht überein', $anders->body);
        $this->assertSame(1, (int) $this->pdo->query("SELECT muss_passwort_aendern FROM benutzer WHERE id = $id")->fetchColumn());
    }

    public function test_personen_nur_fuer_bearbeiter(): void
    {
        $this->anmelden('mitglied');
        $this->assertSame(403, $this->app()->handle(new Request('GET', '/personen'))->status);
        $this->assertSame(403, $this->post('/personen', ['vorname' => 'A', 'nachname' => 'B'])->status);
    }

    public function test_person_anlegen_aendern_loeschen_mit_protokoll(): void
    {
        $this->anmelden('bearbeiter');
        $a = $this->post('/personen', ['vorname' => 'Anna', 'nachname' => 'Bauer', 'email' => 'anna@x.de']);
        $this->assertSame(303, $a->status);
        $pid = (int) $this->pdo->query("SELECT id FROM person WHERE nachname = 'Bauer'")->fetchColumn();
        $this->assertGreaterThan(0, $pid);
        $this->assertStringContainsString('Bauer', $this->app()->handle(new Request('GET', '/personen'))->body);
        $this->assertSame(200, $this->app()->handle(new Request('GET', "/personen/$pid/bearbeiten"))->status);

        $this->post("/personen/$pid", ['vorname' => 'Anna', 'nachname' => 'Berger', 'email' => 'anna@x.de', 'aktiv' => '1']);
        $this->assertSame('Berger', $this->pdo->query("SELECT nachname FROM person WHERE id = $pid")->fetchColumn());

        $kaputt = $this->post("/personen/$pid", ['vorname' => '', 'nachname' => 'X']);
        $this->assertSame(422, $kaputt->status);

        $this->post("/personen/$pid/loeschen");
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM person WHERE id = $pid")->fetchColumn());

        $aktionen = $this->pdo->query("SELECT aktion FROM aenderungsprotokoll WHERE objekt_typ = 'person' AND objekt_id = $pid ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['angelegt', 'geaendert', 'geloescht'], $aktionen);
    }

    public function test_person_mit_zugang_loeschen_zeigt_meldung(): void
    {
        $this->anmelden('bearbeiter');
        $pid = (int) $this->pdo->query('SELECT person_id FROM benutzer LIMIT 1')->fetchColumn();
        $a = $this->post("/personen/$pid/loeschen");
        $this->assertSame(303, $a->status);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM person WHERE id = $pid")->fetchColumn());
        $this->assertStringContainsString('Diese Person hat einen Zugang.', $this->app()->handle(new Request('GET', '/personen'))->body);
    }

    public function test_person_mit_abgestellten_fahrzeugen_loeschen_verweist_aufs_abstellgleis(): void
    {
        $this->anmelden('bearbeiter');
        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('Olga', 'Ohnezugang'), ('Paul', 'Aktiv')");
        $olga = (int) $this->pdo->query("SELECT id FROM person WHERE nachname = 'Ohnezugang'")->fetchColumn();
        $paul = (int) $this->pdo->query("SELECT id FROM person WHERE nachname = 'Aktiv'")->fetchColumn();
        $ins = $this->pdo->prepare("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, status, eigentuemer_person_id) VALUES (?, 'Lok', (SELECT MIN(id) FROM spurweite), ?, ?)");
        $ins->execute(['EMC-0001', 'ausgemustert', $olga]);
        $ins->execute(['EMC-0002', 'einsatzbereit', $paul]);

        $this->assertSame(303, $this->post("/personen/$olga/loeschen")->status);
        $body = $this->app()->handle(new Request('GET', '/personen'))->body;
        $this->assertStringContainsString('weil ihr noch Fahrzeuge gehören (davon 1 auf dem Abstellgleis)', $body);
        $this->assertStringContainsString('href="/abstellgleis?person=' . $olga . '"', $body);

        $this->post("/personen/$paul/loeschen");
        $body = $this->app()->handle(new Request('GET', '/personen'))->body;
        $this->assertStringContainsString('weil ihr noch Fahrzeuge gehören.', $body);
        $this->assertStringNotContainsString('/abstellgleis?person=', $body);
    }

    public function test_benutzerverwaltung_nur_fuer_admin(): void
    {
        $this->anmelden('bearbeiter');
        $this->assertSame(403, $this->app()->handle(new Request('GET', '/admin/benutzer'))->status);
    }

    public function test_benutzer_anlegen_zuruecksetzen_aendern_loeschen(): void
    {
        $adminId = $this->anmelden('admin');
        $this->pdo->prepare("INSERT INTO person (vorname, nachname) VALUES ('Berta', 'Klein')")->execute();
        $pid = (int) $this->pdo->lastInsertId();

        $this->assertSame(200, $this->app()->handle(new Request('GET', '/admin/benutzer'))->status);
        $this->assertSame(200, $this->app()->handle(new Request('GET', '/admin/benutzer/neu'))->status);

        $a = $this->post('/admin/benutzer', ['person_id' => $pid, 'email' => 'Berta@Verein.de', 'rolle' => 'bearbeiter', 'passwort' => 'start-passwort-1']);
        $this->assertSame(303, $a->status);
        $bid = (int) $this->pdo->query("SELECT id FROM benutzer WHERE email = 'berta@verein.de'")->fetchColumn();
        $this->assertGreaterThan(0, $bid);

        $this->post("/admin/benutzer/$bid/passwort", ['passwort' => 'zurueck-gesetzt-1']);
        $z = $this->pdo->query("SELECT * FROM benutzer WHERE id = $bid")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $z['muss_passwort_aendern']);
        $this->assertTrue(password_verify('zurueck-gesetzt-1', $z['passwort_hash']));

        $this->post("/admin/benutzer/$bid", ['rolle' => 'mitglied', 'aktiv' => '1']);
        $this->assertSame('mitglied', $this->pdo->query("SELECT rolle FROM benutzer WHERE id = $bid")->fetchColumn());

        // Letzter Admin kann sich nicht selbst loeschen.
        $this->post("/admin/benutzer/$adminId/loeschen");
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM benutzer WHERE id = $adminId")->fetchColumn());

        $this->post("/admin/benutzer/$bid/loeschen");
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM benutzer WHERE id = $bid")->fetchColumn());

        // Protokoll enthaelt nie Passwort-Hashes.
        $alles = implode('', $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'benutzer'")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertStringNotContainsString('$2y$', $alles);
        $this->assertStringNotContainsString('passwort_hash', $alles);
        $this->assertStringNotContainsString('start-passwort-1', $alles);
        $aktionen = $this->pdo->query("SELECT aktion FROM aenderungsprotokoll WHERE objekt_typ = 'benutzer' AND objekt_id = $bid ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['angelegt', 'geaendert', 'geaendert', 'geloescht'], $aktionen);
    }
}
