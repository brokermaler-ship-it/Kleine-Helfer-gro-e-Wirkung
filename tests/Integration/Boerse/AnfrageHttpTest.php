<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Boerse;

use Emc\Auth\Benutzer;
use Emc\Auth\Rolle;
use Emc\Boerse\AnfrageDienst;
use Emc\Boerse\AngebotDienst;
use Emc\Boerse\AngebotRepository;
use Emc\Boerse\BoerseRepository;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\UploadService;
use Emc\Magazin\MagazinRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;
use Emc\Tests\Support\ExifJpeg;

/** Eingang der Börsenanfragen beim Börsenwart: Hinweis, Liste, Status, Antwort per E-Mail, Übernahme, Löschen. */
final class AnfrageHttpTest extends DbTestCase
{
    private const IP = '203.0.113.9';

    private array $sitzung = [];
    private string $speicher;
    /** @var list<string> */
    private array $temp = [];
    private Benutzer $wart;
    private int $wartId;
    private int $fahrzeugwartId;
    private int $mitgliedId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
        // Börsenwart als Mitglied mit Häkchen (unabhängig von der Rolle).
        $this->wartId = $this->benutzer('wart@verein.de', 'mitglied', true);
        $this->fahrzeugwartId = $this->benutzer('fw@verein.de', 'fahrzeugwart', false);
        $this->mitgliedId = $this->benutzer('mitglied@verein.de', 'mitglied', false);
        $person = (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = {$this->wartId}")->fetchColumn();
        $this->wart = new Benutzer($this->wartId, $person, 'wart@verein.de', 'wart@verein.de', Rolle::Mitglied, false, 'Max', true);
        $this->anmelden($this->wartId);
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    private function benutzer(string $email, string $rolle, bool $boersenwart): int
    {
        $id = $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle);
        $this->pdo->prepare('UPDATE benutzer SET ist_boersenwart = ? WHERE id = ?')->execute([$boersenwart ? 1 : 0, $id]);
        return $id;
    }

    private function anmelden(int $id): void
    {
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    private function app(): App
    {
        $app = App::erzeuge(['speicher_pfad' => $this->speicher] + self::testConfig(), new Session($this->sitzung));
        $app->container()->set(UploadService::class, fn() => new UploadService(
            $this->pdo, $this->speicher, 8 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b)
        ));
        return $app;
    }

    private function get(string $pfad, array $query = []): Response
    {
        return $this->app()->handle(new Request('GET', $pfad, $query, [], [], ['HTTP_HOST' => 'verein.example']));
    }

    private function post(string $pfad, array $daten = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)], [], ['HTTP_HOST' => 'verein.example']));
    }

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /** @return list<string> */
    private function meldungen(string $typ = 'erfolg'): array
    {
        $texte = [];
        foreach ($this->sitzung['_meldungen'] ?? [] as $m) {
            if ($m['typ'] === $typ) {
                $texte[] = $m['text'];
            }
        }
        return $texte;
    }

    /** @return array<string,mixed> */
    private function foto(string $inhalt, string $name): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $this->temp[] = $tmp;
        file_put_contents($tmp, $inhalt);
        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($inhalt)];
    }

    /** Anfrage über den Dienst des öffentlichen Bereichs; liefert ihre ID. */
    private function anfrage(string $art, ?int $angebotId = null, array $mehr = [], int $fotos = 0): int
    {
        $daten = $mehr + ['name' => 'Erika Beispiel', 'email' => 'erika@test.emc78.de', 'telefon' => null, 'nachricht' => 'Ist die Lok noch zu haben?', 'wunsch' => $art === 'sammlung' ? 'spenden' : null];
        $dateien = [];
        for ($i = 0; $i < $fotos; $i++) {
            $dateien[] = $this->foto(ExifJpeg::erzeuge(), 'IMG_' . $i . '.jpg');
        }
        $dienst = $this->app()->container()->get(AnfrageDienst::class);
        $this->assertSame('gespeichert', $dienst->nimmEntgegen($art, $angebotId, $daten, $dateien, self::IP, new \DateTimeImmutable()));
        $this->pdo->exec('DELETE FROM boerse_anfrage_sperre');
        return $this->zahl('SELECT MAX(id) FROM boerse_anfrage');
    }

    private function kategorie(string $schluessel = 'sonstiges'): int
    {
        return (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = '$schluessel'")->fetchColumn();
    }

    /** Freigegebenes Vereinsangebot; liefert [ID, Nummer]. */
    private function angebot(): array
    {
        $dienst = new AngebotDienst($this->pdo, new AngebotRepository($this->pdo), new MagazinRepository($this->pdo), new Aenderungsprotokoll($this->pdo), new BoerseRepository($this->pdo));
        $id = $dienst->anlegen([
            'bezeichnung' => 'Märklin BR 50', 'beschreibung' => 'OVP', 'kategorie_id' => $this->kategorie(), 'spurweite_id' => null,
            'zustand' => 'gebraucht', 'wunschpreis' => '12.50', 'menge' => 1, 'herkunft' => 'mitglied', 'person_id' => $this->wart->personId,
            'erloes_spende' => 0, 'boerse_id' => null, 'notiz' => null, 'oeffentlich' => 'freigegeben',
        ], $this->wart, new \DateTimeImmutable());
        $z = $this->pdo->query("SELECT jahr, laufnummer FROM boerse_angebot WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        return [$id, 'B' . $z['jahr'] . '-' . $z['laufnummer']];
    }

    private function anfrageStatus(int $id): string
    {
        return (string) $this->pdo->query("SELECT status FROM boerse_anfrage WHERE id = $id")->fetchColumn();
    }

    public function test_hinweis_fuer_boersenwart(): void
    {
        [$angebot] = $this->angebot();
        $erste = $this->anfrage('kaufanfrage', $angebot);
        $this->anfrage('sammlung', null, ['nachricht' => 'Kiste mit Spur-N-Wagen']);

        $html = $this->get('/')->body;
        $this->assertStringContainsString('2 neue Börsenanfragen', $html);
        $this->assertStringContainsString('href="/anfragen?status=offen"', $html);
        $this->assertStringContainsString('data-neue-anfragen="2"', $html);

        $this->anmelden($this->fahrzeugwartId);
        $html = $this->get('/')->body;
        $this->assertStringNotContainsString('Börsenanfrage', $html);
        $this->assertStringNotContainsString('data-neue-anfragen', $html);
        $this->assertStringNotContainsString('/anfragen', $html);

        $this->anmelden($this->wartId);
        $r = $this->get('/anfragen/' . $erste);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('in_bearbeitung', $this->anfrageStatus($erste));
        $this->assertSame($this->wartId, $this->zahl("SELECT bearbeitet_von_benutzer_id FROM boerse_anfrage WHERE id = $erste"));
        $html = $this->get('/')->body;
        $this->assertStringContainsString('1 neue Börsenanfrage', $html);
        $this->assertStringNotContainsString('1 neue Börsenanfragen', $html);
        $this->assertStringContainsString('data-neue-anfragen="1"', $html);
    }

    public function test_liste_filter_und_status(): void
    {
        [$angebot, $nummer] = $this->angebot();
        $kauf = $this->anfrage('kaufanfrage', $angebot);
        $sammlung = $this->anfrage('sammlung', null, ['name' => 'Rudi Sammler', 'nachricht' => 'Kiste mit Spur-N-Wagen']);

        $html = $this->get('/anfragen')->body;
        $this->assertStringContainsString('Erika Beispiel', $html);
        $this->assertStringContainsString('Rudi Sammler', $html);
        $this->assertStringContainsString($nummer, $html);

        $r = $this->post("/anfragen/$sammlung/status", ['status' => 'erledigt']);
        $this->assertSame(303, $r->status);
        $this->assertSame('erledigt', $this->anfrageStatus($sammlung));
        $html = $this->get('/anfragen')->body;
        $this->assertStringNotContainsString('Rudi Sammler', $html);
        $this->assertStringContainsString('Rudi Sammler', $this->get('/anfragen', ['status' => 'erledigt'])->body);
        $this->assertStringContainsString('Rudi Sammler', $this->get('/anfragen', ['status' => 'alle'])->body);
        $this->assertStringNotContainsString('Rudi Sammler', $this->get('/anfragen', ['status' => 'offen'])->body);

        $this->assertSame(422, $this->post("/anfragen/$kauf/status", ['status' => 'kaputt'])->status);
        $this->assertSame('offen', $this->anfrageStatus($kauf));
        $this->assertSame(404, $this->get('/anfragen/999999')->status);
        $this->assertSame(404, $this->get('/anfragen/abc')->status);
    }

    public function test_mailto_mit_betreff(): void
    {
        [$angebot, $nummer] = $this->angebot();
        $id = $this->anfrage('kaufanfrage', $angebot);
        $html = $this->get('/anfragen/' . $id)->body;
        $betreff = rawurlencode('Deine Anfrage an die Modellbahnbörse (' . $nummer . ')');
        $this->assertStringContainsString('href="mailto:erika@test.emc78.de?subject=' . $betreff, $html);
        $this->assertStringContainsString('Kaufanfrage zu', $html);
        $this->assertStringContainsString('href="/angebote/' . $angebot . '"', $html);
        $this->assertStringContainsString('wird automatisch gelöscht am ' . (new \DateTimeImmutable())->modify('+12 months')->format('d.m.Y'), $html);

        $sammlung = $this->anfrage('sammlung', null, ['wunsch' => 'verkaufen', 'nachricht' => 'Kiste']);
        $html = $this->get('/anfragen/' . $sammlung)->body;
        $this->assertStringContainsString('?subject=' . rawurlencode('Deine Sammlung – Anfrage an die Modellbahnbörse'), $html);
        $this->assertStringContainsString('Sammlung angeboten – möchte verkaufen', $html);
    }

    public function test_freitext_wird_maskiert_und_nicht_verlinkt(): void
    {
        $id = $this->anfrage('sammlung', null, [
            'name' => 'Eve <script>alert(1)</script>',
            'email' => 'erika.b+boerse@test.emc78.de',
            'telefon' => '+49 (0) 621/123-45',
            'nachricht' => "Siehe https://boese.example/x \"><img src=x onerror=alert(2)>\njavascript:alert(3)",
        ]);
        $html = $this->get('/anfragen/' . $id)->body;
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('Eve &lt;script&gt;', $html);
        $this->assertStringNotContainsString('href="https://boese.example', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        // Lokaler Teil kodiert (kein + roh im Link), Telefon nur mit Ziffern und +.
        $this->assertStringContainsString('href="mailto:erika.b%2Bboerse@test.emc78.de?subject=', $html);
        $this->assertStringContainsString('href="tel:+4962112345"', $html);
        // Name im Betreff/Text nur kodiert: kein Zeilenumbruch, keine weiteren Kopfzeilen.
        $this->assertDoesNotMatchRegularExpression('/mailto:[^"]*(%0[AD]|\r|\n)(cc|bcc)/i', $html);
    }

    public function test_mailto_injektion_nur_als_text(): void
    {
        $id = $this->anfrage('kaufanfrage', null, ['email' => "o'hara@test.emc78.de"]);
        foreach (["o'hara@test.emc78.de", 'a?cc=x&bcc=spy@evil.example', 'a@b.de?bcc=spy@evil.example'] as $email) {
            $this->pdo->prepare('UPDATE boerse_anfrage SET email = ? WHERE id = ?')->execute([$email, $id]);
            $html = $this->get('/anfragen/' . $id)->body;
            $this->assertStringNotContainsString('mailto:', $html);
            $this->assertStringContainsString(e($email), $html);
            $this->assertStringNotContainsString('bcc=spy@evil.example"', $html);
        }
    }

    public function test_ungueltige_email_ohne_mailto(): void
    {
        $id = $this->anfrage('kaufanfrage', null, ['email' => null, 'telefon' => '0621 123456']);
        // E-Mail nachträglich unbrauchbar (z. B. aus einer älteren Version): kein mailto-Link.
        $this->pdo->exec("UPDATE boerse_anfrage SET email = 'kaputt@@x?cc=bose@example.org' WHERE id = $id");
        $html = $this->get('/anfragen/' . $id)->body;
        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringContainsString('href="tel:0621123456"', $html);
        $this->assertStringContainsString('kaputt@@x?cc=bose@example.org', $html);
    }

    public function test_uebernahme_sammlung(): void
    {
        $id = $this->anfrage('sammlung', null, ['name' => 'Rudi Sammler', 'nachricht' => str_repeat('Kiste mit Spur-N-Wagen und Gleisen ', 5), 'wunsch' => 'spenden'], 2);
        $this->assertSame(2, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'boerse_anfrage' AND objekt_id = $id"));

        $form = $this->get("/anfragen/$id/uebernehmen");
        $this->assertSame(200, $form->status, $form->body);
        $this->assertStringContainsString('action="/anfragen/' . $id . '/uebernehmen"', $form->body);
        $this->assertStringContainsString('value="' . e(trim(mb_substr(str_repeat('Kiste mit Spur-N-Wagen und Gleisen ', 5), 0, 80))) . '"', $form->body);
        // Der Name gehört nicht in die unveränderliche Buchung (er überlebte sonst die Löschung nach 12 Monaten).
        $this->assertStringContainsString('value="Anfrage über die Webseite (Nr. ' . $id . ')"', $form->body);
        $this->assertStringNotContainsString('value="Anfrage über die Webseite: Rudi', $form->body);
        $this->assertMatchesRegularExpression('/<option value="spende" selected>/', $form->body);
        $this->assertMatchesRegularExpression('/<option value="' . $this->kategorie() . '"[^>]*selected>/', $form->body);

        $r = $this->post("/anfragen/$id/uebernehmen", [
            'bezeichnung' => 'Spur-N-Wagen, Kiste', 'kategorie_id' => (string) $this->kategorie(), 'zustand' => 'gebraucht', 'hersteller' => '',
            'artikelnummer' => '', 'spurweite_id' => '', 'lagerort' => 'Keller', 'notiz' => '', 'autor_verlag' => '', 'jahr' => '', 'isbn' => '',
            'menge' => '1', 'herkunft' => 'spende', 'freitext' => 'Anfrage über die Webseite (Nr. ' . $id . ')', 'datum' => date('Y-m-d'), 'betrag' => '',
        ]);
        $this->assertSame(303, $r->status, $r->body);
        $artikel = $this->zahl('SELECT MAX(id) FROM magazin_artikel');
        $this->assertSame('/magazin/' . $artikel, $r->headers['Location']);
        $this->assertSame(1, $this->zahl("SELECT SUM(menge) FROM magazin_buchung WHERE artikel_id = $artikel"));
        $this->assertSame('spende', (string) $this->pdo->query("SELECT herkunft FROM magazin_buchung WHERE artikel_id = $artikel AND art = 'eingang'")->fetchColumn());
        $this->assertSame('Anfrage über die Webseite (Nr. ' . $id . ')', (string) $this->pdo->query("SELECT freitext FROM magazin_buchung WHERE artikel_id = $artikel AND art = 'eingang'")->fetchColumn());
        $this->assertSame(2, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'magazin_artikel' AND objekt_id = $artikel"));
        $erstes = $this->zahl("SELECT MIN(dokument_id) FROM dokument_zuordnung WHERE objekt_typ = 'boerse_anfrage' AND objekt_id = $id");
        $this->assertSame($erstes, $this->zahl("SELECT titelbild_dokument_id FROM magazin_artikel WHERE id = $artikel"));
        $this->assertSame('erledigt', $this->anfrageStatus($id));
        $this->assertSame($artikel, $this->zahl("SELECT magazin_artikel_id FROM boerse_anfrage WHERE id = $id"));
        $this->assertStringContainsString('übernommen als M' . $artikel, $this->get('/anfragen/' . $id)->body);
        $this->assertStringNotContainsString('Ins Magazin übernehmen', $this->get('/anfragen/' . $id)->body);
        // Die Fotos sieht man jetzt am Artikel.
        $this->assertStringContainsString('/dateien/' . $erstes . '/vorschau', $this->get('/magazin/' . $artikel)->body);

        // Zweite Übernahme: Meldung, kein zweiter Artikel.
        $zweite = $this->post("/anfragen/$id/uebernehmen", [
            'bezeichnung' => 'Nochmal', 'kategorie_id' => (string) $this->kategorie(), 'zustand' => 'gebraucht', 'menge' => '1', 'herkunft' => 'spende', 'datum' => date('Y-m-d'),
        ]);
        $this->assertContains('Diese Anfrage wurde schon übernommen.', $this->meldungen('fehler'));
        $this->assertSame(303, $zweite->status);
        $this->assertSame($artikel, $this->zahl('SELECT MAX(id) FROM magazin_artikel'));
        $this->get("/anfragen/$id/uebernehmen");
        $this->assertContains('Diese Anfrage wurde schon übernommen.', $this->meldungen('fehler'));

        // Kaufanfrage: keine Übernahme.
        $kauf = $this->anfrage('kaufanfrage');
        $this->assertSame(404, $this->get("/anfragen/$kauf/uebernehmen")->status);
        $this->assertSame(404, $this->post("/anfragen/$kauf/uebernehmen", ['bezeichnung' => 'x'])->status);
        $this->assertStringNotContainsString('Ins Magazin übernehmen', $this->get('/anfragen/' . $kauf)->body);
    }

    public function test_uebernahme_mit_fehlern_und_kauf(): void
    {
        $id = $this->anfrage('sammlung', null, ['wunsch' => 'verkaufen', 'nachricht' => 'Kiste']);
        $this->assertMatchesRegularExpression('/<option value="kauf" selected>/', $this->get("/anfragen/$id/uebernehmen")->body);
        $r = $this->post("/anfragen/$id/uebernehmen", ['bezeichnung' => '', 'kategorie_id' => '', 'menge' => '0', 'herkunft' => 'kauf']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte prüfe die markierten Felder.', $r->body);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
        $this->assertNotSame('erledigt', $this->anfrageStatus($id));
    }

    public function test_loeschen_von_hand(): void
    {
        $uebernommen = $this->anfrage('sammlung', null, [], 1);
        $foto = $this->zahl("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'boerse_anfrage' AND objekt_id = $uebernommen");
        $this->post("/anfragen/$uebernommen/uebernehmen", [
            'bezeichnung' => 'Kiste', 'kategorie_id' => (string) $this->kategorie(), 'zustand' => 'gebraucht', 'menge' => '1', 'herkunft' => 'spende', 'datum' => date('Y-m-d'),
        ]);
        $artikel = $this->zahl('SELECT MAX(id) FROM magazin_artikel');
        $andere = $this->anfrage('sammlung', null, ['name' => 'Lotte Loesch'], 2);
        $eigene = $this->pdo->query("SELECT d.id, d.pfad FROM dokument d JOIN dokument_zuordnung z ON z.dokument_id = d.id WHERE z.objekt_typ = 'boerse_anfrage' AND z.objekt_id = $andere")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $eigene);

        $this->assertStringContainsString('Anfrage samt Fotos endgültig löschen?', $this->get("/anfragen/$andere")->body);
        $r = $this->post("/anfragen/$andere/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame('/anfragen', $r->headers['Location']);
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM boerse_anfrage WHERE id = $andere"));
        foreach ($eigene as $d) {
            $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument WHERE id = ' . (int) $d['id']));
            $this->assertFileDoesNotExist($this->speicher . '/' . $d['pfad']);
        }

        $this->post("/anfragen/$uebernommen/loeschen");
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM boerse_anfrage WHERE id = $uebernommen"));
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE dokument_id = $foto AND objekt_typ = 'magazin_artikel' AND objekt_id = $artikel"));
        $this->assertSame(404, $this->post("/anfragen/$uebernommen/loeschen")->status);
    }

    public function test_foto_einer_anfrage_loescht_der_boersenwart(): void
    {
        $id = $this->anfrage('sammlung', null, [], 2);
        $fotos = array_map('intval', $this->pdo->query("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'boerse_anfrage' AND objekt_id = $id ORDER BY dokument_id")->fetchAll(\PDO::FETCH_COLUMN));
        $html = $this->get('/anfragen/' . $id)->body;
        $this->assertStringContainsString('/dateien/' . $fotos[0] . '/vorschau', $html);
        $this->assertStringContainsString('action="/dateien/' . $fotos[0] . '/loeschen"', $html);
        $this->assertSame(200, $this->get('/dateien/' . $fotos[0] . '/anzeige')->status);

        $this->anmelden($this->mitgliedId);
        $this->assertSame(404, $this->get('/dateien/' . $fotos[0] . '/anzeige')->status);
        $this->assertSame(404, $this->post('/dateien/' . $fotos[0] . '/loeschen')->status, 'wie unbekannt');
        $this->anmelden($this->fahrzeugwartId);
        $this->assertSame(404, $this->post('/dateien/' . $fotos[0] . '/loeschen')->status);
        $this->assertSame(2, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'boerse_anfrage' AND objekt_id = $id"));

        $this->anmelden($this->wartId);
        $r = $this->post('/dateien/' . $fotos[0] . '/loeschen');
        $this->assertSame(303, $r->status);
        $this->assertSame('/anfragen/' . $id . '#fotos', $r->headers['Location']);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument WHERE id = ' . $fotos[0]));
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'boerse_anfrage' AND objekt_id = $id"));
    }

    public function test_mitglied_403(): void
    {
        $id = $this->anfrage('sammlung');
        foreach ([$this->mitgliedId, $this->fahrzeugwartId] as $benutzer) {
            $this->anmelden($benutzer);
            $this->assertSame(403, $this->get('/anfragen')->status);
            $this->assertSame(403, $this->get('/anfragen/' . $id)->status);
            $this->assertSame(403, $this->get("/anfragen/$id/uebernehmen")->status);
            $this->assertSame(403, $this->post("/anfragen/$id/status", ['status' => 'erledigt'])->status);
            $this->assertSame(403, $this->post("/anfragen/$id/loeschen")->status);
            $this->assertSame(403, $this->post("/anfragen/$id/uebernehmen", ['bezeichnung' => 'x'])->status);
        }
        $this->assertSame('offen', $this->anfrageStatus($id));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
        // Ohne Anmeldung: Login.
        $this->sitzung = [];
        $this->assertSame(303, $this->get('/anfragen')->status);
    }

    public function test_protokoll_ohne_personendaten(): void
    {
        [$angebot] = $this->angebot();
        $kauf = $this->anfrage('kaufanfrage', $angebot, ['name' => 'Paula Privat', 'email' => 'paula.privat@test.emc78.de', 'telefon' => '0621 999888', 'nachricht' => 'Geheimnachricht']);
        $sammlung = $this->anfrage('sammlung', null, ['name' => 'Paula Privat', 'email' => 'paula.privat@test.emc78.de', 'nachricht' => 'Geheimkiste'], 1);
        $this->get('/anfragen/' . $kauf);
        $this->post("/anfragen/$kauf/status", ['status' => 'erledigt']);
        $this->post("/anfragen/$sammlung/uebernehmen", [
            'bezeichnung' => 'Kiste', 'kategorie_id' => (string) $this->kategorie(), 'zustand' => 'gebraucht', 'menge' => '1', 'herkunft' => 'spende', 'datum' => date('Y-m-d'),
            'freitext' => 'Anfrage über die Webseite (Nr. ' . $sammlung . ')',
        ]);
        $this->assertSame('erledigt', $this->anfrageStatus($sammlung));
        $fotos = array_map('intval', $this->pdo->query("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'boerse_anfrage' AND objekt_id = $sammlung")->fetchAll(\PDO::FETCH_COLUMN));
        $this->post('/dateien/' . $fotos[0] . '/loeschen');
        $this->post("/anfragen/$kauf/loeschen");

        $eintraege = $this->pdo->query("SELECT aktion, aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'boerse_anfrage'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertGreaterThanOrEqual(4, count($eintraege));
        foreach ($eintraege as $e) {
            $text = (string) $e['aenderungen'];
            foreach (['Paula', 'Privat', 'paula', '999888', 'Geheim'] as $verboten) {
                $this->assertStringNotContainsString($verboten, $text);
            }
        }
        $aktionen = array_column($eintraege, 'aktion');
        $this->assertContains('geloescht', $aktionen);
        $this->assertContains('geaendert', $aktionen);
        // Status im Protokoll der Sammlung.
        $this->assertStringContainsString('erledigt', (string) $this->pdo->query("SELECT GROUP_CONCAT(aenderungen) FROM aenderungsprotokoll WHERE objekt_typ = 'boerse_anfrage' AND objekt_id = $sammlung")->fetchColumn());
    }

    public function test_menue_badge_am_boersenmenue(): void
    {
        $this->anfrage('sammlung');
        $html = $this->get('/fahrzeuge')->body;
        $this->assertMatchesRegularExpression('/>Börse <span class="badge[^"]*" data-neue-anfragen="1"/', $html);
        $this->assertStringContainsString('href="/anfragen"', $html);
        // Ab 10: in der Kopfzeile „9+“ (Breite ab xl), am Menüpunkt „Anfragen“ und im Hinweis die genaue Zahl.
        for ($i = 0; $i < 9; $i++) {
            $this->anfrage('kaufanfrage');
        }
        $html = $this->get('/fahrzeuge')->body;
        $this->assertMatchesRegularExpression('/data-neue-anfragen="10" title="10 neue Börsenanfragen">9\+ </', $html);
        $this->assertMatchesRegularExpression('/>Anfragen <span class="badge[^>]*>10 </', $html);
        $this->assertStringContainsString('10 neue Börsenanfragen', $html);
        $this->anmelden($this->mitgliedId);
        $html = $this->get('/fahrzeuge')->body;
        $this->assertStringNotContainsString('href="/anfragen"', $html);
        $this->assertStringNotContainsString('data-neue-anfragen', $html);
    }

    public function test_alte_anfragen_beim_listenaufruf_geloescht(): void
    {
        $id = $this->anfrage('kaufanfrage');
        $this->pdo->exec("UPDATE boerse_anfrage SET eingegangen_am = DATE_SUB(NOW(), INTERVAL 13 MONTH) WHERE id = $id");
        $this->assertSame(200, $this->get('/anfragen')->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
    }
}
