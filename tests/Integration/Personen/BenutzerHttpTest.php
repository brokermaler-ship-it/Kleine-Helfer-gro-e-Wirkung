<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Personen;

use Emc\Auth\Auth;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

final class BenutzerHttpTest extends DbTestCase
{
    private array $sitzung = [];

    private function app(): App
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung));
    }

    private function anmelden(string $rolle, string $email): int
    {
        $id = $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle);
        $this->sitzungAnmelden($this->sitzung, $id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        return $id;
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

    private function haekchen(int $id): int
    {
        return (int) $this->pdo->query("SELECT ist_boersenwart FROM benutzer WHERE id = $id")->fetchColumn();
    }

    public function test_boersenwart_haekchen(): void
    {
        $this->anmelden('admin', 'admin@verein.de');
        $greta = $this->legeBenutzerAn('greta@verein.de', 'geheim-passwort-1', 'fahrzeugwart');
        $this->assertSame(0, $this->haekchen($greta));

        $formular = $this->get("/admin/benutzer/$greta/bearbeiten");
        $this->assertSame(200, $formular->status);
        $this->assertStringContainsString('name="ist_boersenwart"', $formular->body);
        $this->assertStringContainsString('Börsenwart (Magazin und Modellbahnbörse)', $formular->body);
        $this->assertStringContainsString('Zusatzaufgabe, unabhängig von der Rolle. Bearbeiter und Administratoren dürfen das ohnehin.', $formular->body);
        $this->assertStringNotContainsString('name="ist_boersenwart" value="1" checked', $formular->body);

        $r = $this->post("/admin/benutzer/$greta", ['rolle' => 'fahrzeugwart', 'aktiv' => '1', 'ist_boersenwart' => '1']);
        $this->assertSame(303, $r->status);
        $this->assertSame(1, $this->haekchen($greta));
        $this->assertSame('fahrzeugwart', $this->pdo->query("SELECT rolle FROM benutzer WHERE id = $greta")->fetchColumn());

        $liste = $this->get('/admin/benutzer')->body;
        $this->assertSame(1, substr_count($liste, '>Börsenwart</span>'), 'Badge nur bei Greta');
        $this->assertStringContainsString('name="ist_boersenwart" value="1" checked', $this->get("/admin/benutzer/$greta/bearbeiten")->body);

        $eintrag = $this->pdo->query("SELECT aktion, aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'benutzer' AND objekt_id = $greta ORDER BY id DESC LIMIT 1")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('geaendert', $eintrag['aktion']);
        $this->assertSame(['ist_boersenwart' => ['alt' => 0, 'neu' => 1]], json_decode($eintrag['aenderungen'], true));

        // Greta sieht als Fahrzeugwart mit Häkchen den Börsenwart.
        $gretaSitzung = [];
        $this->sitzungAnmelden($gretaSitzung, $greta);
        $this->assertTrue((new Auth($this->pdo, new Session($gretaSitzung), \Emc\Core\Instanz::fingerabdruck(self::testConfig())))->benutzer()?->istBoersenwart);

        // Ohne Häkchen im Formular wird es entfernt.
        $this->post("/admin/benutzer/$greta", ['rolle' => 'fahrzeugwart', 'aktiv' => '1']);
        $this->assertSame(0, $this->haekchen($greta));
        $this->assertStringNotContainsString('>Börsenwart</span>', $this->get('/admin/benutzer')->body);
        $aenderungen = $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'benutzer' AND objekt_id = $greta ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertSame(['ist_boersenwart' => ['alt' => 1, 'neu' => 0]], json_decode($aenderungen, true));
    }

    /** Ohne freie Person: das Formular bietet „Neue Person anlegen“ an und legt Person und Zugang zusammen an. */
    public function test_zugang_mit_neuer_person(): void
    {
        $this->anmelden('admin', 'admin@verein.de');
        $formular = $this->get('/admin/benutzer/neu');
        $this->assertSame(200, $formular->status);
        $this->assertMatchesRegularExpression('/<option value="neu"\s+selected>/', $formular->body);
        $this->assertStringContainsString('name="neu_vorname"', $formular->body);
        $this->assertStringNotContainsString('id="neue-person" hidden', $formular->body);

        $antwort = $this->post('/admin/benutzer', ['person_id' => 'neu', 'neu_vorname' => ' Dora ', 'neu_nachname' => 'Fink',
            'email' => 'Dora@Verein.de', 'rolle' => 'bearbeiter', 'passwort' => 'start-passwort-1']);
        $this->assertSame(303, $antwort->status);
        $person = $this->pdo->query("SELECT * FROM person WHERE nachname = 'Fink'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Dora', $person['vorname']);
        $this->assertSame('dora@verein.de', $person['email']);
        $this->assertSame(1, (int) $person['aktiv']);
        $benutzer = $this->pdo->query("SELECT * FROM benutzer WHERE email = 'dora@verein.de'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame((int) $person['id'], (int) $benutzer['person_id']);
        $this->assertSame('bearbeiter', $benutzer['rolle']);
        $pid = (int) $person['id'];
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'person' AND objekt_id = $pid AND aktion = 'angelegt'")->fetchColumn());
    }

    public function test_neue_person_pflichtfelder_und_kein_halber_datensatz(): void
    {
        $this->anmelden('admin', 'admin@verein.de');
        $ohneName = $this->post('/admin/benutzer', ['person_id' => 'neu', 'neu_vorname' => '', 'neu_nachname' => '',
            'email' => 'e@verein.de', 'rolle' => 'mitglied', 'passwort' => 'start-passwort-1']);
        $this->assertSame(422, $ohneName->status);
        $this->assertStringContainsString('Bitte gib einen Vornamen an.', $ohneName->body);
        $this->assertStringContainsString('Bitte gib einen Nachnamen an.', $ohneName->body);

        // E-Mail schon vergeben: die neue Person wird nicht angelegt (eine Transaktion), Eingaben bleiben stehen.
        $vorher = (int) $this->pdo->query('SELECT COUNT(*) FROM person')->fetchColumn();
        $doppelt = $this->post('/admin/benutzer', ['person_id' => 'neu', 'neu_vorname' => 'Emil', 'neu_nachname' => 'Gans',
            'email' => 'admin@verein.de', 'rolle' => 'mitglied', 'passwort' => 'start-passwort-1']);
        $this->assertSame(422, $doppelt->status);
        $this->assertStringContainsString('bereits vergeben', $doppelt->body);
        $this->assertStringContainsString('value="Emil"', $doppelt->body);
        $this->assertSame($vorher, (int) $this->pdo->query('SELECT COUNT(*) FROM person')->fetchColumn());

        // Zu kurzes Passwort: ebenfalls keine Person.
        $kurz = $this->post('/admin/benutzer', ['person_id' => 'neu', 'neu_vorname' => 'Emil', 'neu_nachname' => 'Gans',
            'email' => 'emil@verein.de', 'rolle' => 'mitglied', 'passwort' => 'kurz']);
        $this->assertSame(422, $kurz->status);
        $this->assertSame($vorher, (int) $this->pdo->query('SELECT COUNT(*) FROM person')->fetchColumn());
    }

    public function test_mit_freien_personen_ist_neu_nicht_vorausgewaehlt(): void
    {
        $this->anmelden('admin', 'admin@verein.de');
        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('Hans', 'Igel')");
        $body = $this->get('/admin/benutzer/neu')->body;
        $this->assertStringContainsString('Igel, Hans', $body);
        $this->assertMatchesRegularExpression('/<option value="neu">/', $body);
        $this->assertStringContainsString('id="neue-person" hidden', $body);
    }

    public function test_boersenwart_beim_anlegen(): void
    {
        $this->anmelden('admin', 'admin@verein.de');
        $this->assertStringContainsString('name="ist_boersenwart"', $this->get('/admin/benutzer/neu')->body);
        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('Berta', 'Klein'), ('Carl', 'Lang')");
        [$berta, $carl] = array_map('intval', $this->pdo->query("SELECT id FROM person WHERE nachname IN ('Klein', 'Lang') ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));

        $this->assertSame(303, $this->post('/admin/benutzer', ['person_id' => $berta, 'email' => 'berta@verein.de', 'rolle' => 'mitglied', 'passwort' => 'start-passwort-1', 'ist_boersenwart' => '1'])->status);
        $this->assertSame(303, $this->post('/admin/benutzer', ['person_id' => $carl, 'email' => 'carl@verein.de', 'rolle' => 'mitglied', 'passwort' => 'start-passwort-1'])->status);
        $this->assertSame(1, (int) $this->pdo->query("SELECT ist_boersenwart FROM benutzer WHERE email = 'berta@verein.de'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT ist_boersenwart FROM benutzer WHERE email = 'carl@verein.de'")->fetchColumn());
        $bertaId = (int) $this->pdo->query("SELECT id FROM benutzer WHERE email = 'berta@verein.de'")->fetchColumn();
        $aenderungen = json_decode((string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'benutzer' AND objekt_id = $bertaId AND aktion = 'angelegt'")->fetchColumn(), true);
        $this->assertSame(['alt' => null, 'neu' => 1], $aenderungen['ist_boersenwart']);

        // Fehlerhafte Eingabe behält das Häkchen im Formular.
        $fehler = $this->post('/admin/benutzer', ['person_id' => '', 'email' => 'x@verein.de', 'rolle' => 'mitglied', 'passwort' => 'start-passwort-1', 'ist_boersenwart' => '1']);
        $this->assertSame(422, $fehler->status);
        $this->assertMatchesRegularExpression('/name="ist_boersenwart" value="1"\s+checked/', $fehler->body);
    }

    /** Vor dem Datenbank-Update auf 016 fehlt die Spalte: Anmelden, Startseite und Benutzerliste funktionieren. */
    public function test_anmelden_vor_migration_016(): void
    {
        $id = $this->legeBenutzerAn('admin@verein.de', 'geheim-passwort-1', 'admin');
        $this->pdo->exec('ALTER TABLE benutzer DROP COLUMN ist_boersenwart');
        $this->pdo->exec("DELETE FROM schema_migration WHERE version = '016_magazin'");
        try {
            $this->sitzung['_csrf'] = str_repeat('a', 64);
            $login = $this->post('/login', ['email' => 'admin@verein.de', 'passwort' => 'geheim-passwort-1']);
            $this->assertSame(303, $login->status);
            $this->assertSame($id, $this->sitzung['benutzer_id'] ?? null);
            $this->sitzung['_csrf'] = str_repeat('a', 64); // die Anmeldung erneuert die Sitzung

            $app = $this->app();
            $benutzer = $app->container()->get(Auth::class)->benutzer();
            $this->assertNotNull($benutzer);
            $this->assertFalse($benutzer->istBoersenwart);

            $start = $this->get('/');
            $this->assertSame(200, $start->status);
            $this->assertStringContainsString('Es steht ein Datenbank-Update an.', $start->body);
            $liste = $this->get('/admin/benutzer');
            $this->assertSame(200, $liste->status);
            $this->assertStringNotContainsString('>Börsenwart</span>', $liste->body);
            $this->assertSame(200, $this->get("/admin/benutzer/$id/bearbeiten")->status);
            // Speichern führt vor dem Update zur Update-Seite (App::handle), nichts wird geschrieben.
            $speichern = $this->post("/admin/benutzer/$id", ['rolle' => 'mitglied', 'aktiv' => '1', 'ist_boersenwart' => '1']);
            $this->assertSame('/admin/update', $speichern->headers['Location'] ?? null);
            $this->assertSame('admin', $this->pdo->query("SELECT rolle FROM benutzer WHERE id = $id")->fetchColumn());
        } finally {
            $this->pdo->exec('ALTER TABLE benutzer ADD COLUMN ist_boersenwart TINYINT(1) NOT NULL DEFAULT 0');
            $this->pdo->exec("INSERT IGNORE INTO schema_migration (version) VALUES ('016_magazin')");
        }
    }
}
