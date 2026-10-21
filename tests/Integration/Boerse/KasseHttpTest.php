<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Boerse;

use Emc\Auth\Benutzer;
use Emc\Auth\Rolle;
use Emc\Boerse\AngebotDienst;
use Emc\Boerse\AngebotRepository;
use Emc\Boerse\BoerseRepository;
use Emc\Boerse\KasseDienst;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\DbTestCase;

/** Kasse über HTTP: Rechte, Auswahl der Börse, Warenkorb in der Sitzung, Verkauf, Fehler, Verkaufsliste und Storno. */
final class KasseHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private AngebotDienst $angebote;
    private KasseDienst $kasse;
    private Benutzer $wart;
    private Benutzer $carla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $rep = new AngebotRepository($this->pdo);
        $magazinRep = new MagazinRepository($this->pdo);
        $protokoll = new Aenderungsprotokoll($this->pdo);
        $this->angebote = new AngebotDienst($this->pdo, $rep, $magazinRep, $protokoll, new BoerseRepository($this->pdo));
        $this->kasse = new KasseDienst($this->pdo, $rep, new MagazinDienst($this->pdo, $magazinRep, $protokoll, $rep), $protokoll);
        $this->wart = $this->benutzer('greta@verein.de', 'mitglied', true);
        $this->carla = $this->benutzer('carla@verein.de', 'mitglied', false);
    }

    private function benutzer(string $email, string $rolle, bool $boersenwart): Benutzer
    {
        $id = $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle);
        $this->pdo->prepare('UPDATE benutzer SET ist_boersenwart = ? WHERE id = ?')->execute([$boersenwart ? 1 : 0, $id]);
        $person = (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = $id")->fetchColumn();
        return new Benutzer($id, $person, $email, $email, Rolle::from($rolle), false, '', $boersenwart);
    }

    private function anmelden(Benutzer $b): void
    {
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $b->id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    private function app(): App
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung));
    }

    private function get(string $pfad, array $query = []): Response
    {
        return $this->app()->handle(new Request('GET', $pfad, $query, [], [], ['HTTP_HOST' => 'verein.example']));
    }

    private function post(string $pfad, array $daten = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)]));
    }

    private function boerse(string $status = 'laeuft', string $name = 'Herbstbörse'): int
    {
        $this->pdo->prepare('INSERT INTO boerse (name, datum, status) VALUES (?, ?, ?)')->execute([$name, date('Y') . '-11-08', $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function angebot(?int $boerseId, string $preis = '12.50', string $bezeichnung = 'BR 50 Märklin'): int
    {
        $kat = (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'")->fetchColumn();
        return $this->angebote->anlegen([
            'bezeichnung' => $bezeichnung, 'beschreibung' => null, 'kategorie_id' => $kat, 'spurweite_id' => null, 'zustand' => 'gebraucht',
            'wunschpreis' => $preis, 'menge' => 1, 'herkunft' => 'mitglied', 'person_id' => $this->carla->personId, 'erloes_spende' => 0,
            'boerse_id' => $boerseId, 'notiz' => null,
        ], $this->carla, new \DateTimeImmutable());
    }

    private function lauf(int $angebotId): int
    {
        return (int) $this->pdo->query("SELECT laufnummer FROM boerse_angebot WHERE id = $angebotId")->fetchColumn();
    }

    private function nummer(int $angebotId): string
    {
        return 'B' . date('Y') . '-' . $this->lauf($angebotId);
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

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    public function test_ohne_haekchen_403(): void
    {
        $b = $this->boerse();
        $this->anmelden($this->carla);
        $this->assertSame(403, $this->get('/kasse')->status);
        $this->assertSame(403, $this->get("/boersen/$b/kasse")->status);
        $this->assertSame(403, $this->get("/boersen/$b/verkaeufe")->status);
        $this->assertSame(403, $this->post("/boersen/$b/kasse/hinzu", ['nummer' => '1'])->status);
        $this->assertSame(403, $this->post("/boersen/$b/kasse/abschliessen", ['zahlart' => 'bar'])->status);
        $this->assertSame(403, $this->post('/verkaeufe/1/storno', ['grund' => 'x'])->status);
        // Fahrzeugwart ohne Häkchen ist kein Börsenwart.
        $fw = $this->benutzer('fw@verein.de', 'fahrzeugwart', false);
        $this->anmelden($fw);
        $this->assertSame(403, $this->get('/kasse')->status);
        $this->assertStringNotContainsString('href="' . url('/kasse') . '"', $this->get('/angebote')->body);
    }

    public function test_kasse_waehlt_laufende_boerse(): void
    {
        $this->anmelden($this->wart);
        $r = $this->get('/kasse');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Gerade läuft keine Börse.', $r->body);
        $this->boerse('in_vorbereitung', 'Winterbörse');
        $b = $this->boerse();
        $r = $this->get('/kasse');
        $this->assertSame(303, $r->status);
        $this->assertSame(url("/boersen/$b/kasse"), $r->headers['Location']);
        $this->boerse('laeuft', 'Sommerbörse');
        $r = $this->get('/kasse');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Herbstbörse', $r->body);
        $this->assertStringContainsString('Sommerbörse', $r->body);
        $this->assertStringNotContainsString('Winterbörse', $r->body);
        $this->assertStringContainsString('href="' . url('/kasse') . '"', $this->get('/angebote')->body, 'Menüpunkt Kasse für Börsenwarte');
    }

    public function test_verkauf_ueber_die_kasse(): void
    {
        $b = $this->boerse();
        $erstes = $this->angebot($b, '12.50', 'BR 50 Märklin');
        $zweites = $this->angebot(null, '7.50', 'Fleischmann Wagen');
        $this->anmelden($this->wart);

        $r = $this->post("/boersen/$b/kasse/hinzu", ['nummer' => (string) $this->lauf($erstes)]);
        $this->assertSame(303, $r->status, $r->body);
        $this->assertSame([['angebot_id' => $erstes, 'preis' => '12.50']], $this->sitzung['kasse_' . $b]);
        $r = $this->post("/boersen/$b/kasse/hinzu", ['nummer' => (string) $this->lauf($erstes)]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString(e($this->nummer($erstes) . ' ist schon im Warenkorb.'), $r->body);

        // Suche in offenen Angeboten dieser Börse und dauerhaften, Hinzufügen per angebot_id
        $r = $this->get("/boersen/$b/kasse", ['q' => 'Fleischmann']);
        $this->assertStringContainsString('Fleischmann Wagen', $r->body);
        $this->assertStringContainsString('name="angebot_id" value="' . $zweites . '"', $r->body);
        $this->assertSame(303, $this->post("/boersen/$b/kasse/hinzu", ['angebot_id' => (string) $zweites])->status);

        $r = $this->get("/boersen/$b/kasse");
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('inputmode="numeric"', $r->body);
        $this->assertStringContainsString('placeholder="z. B. 118"', $r->body);
        $this->assertStringContainsString('autofocus', $r->body);
        $this->assertStringContainsString($this->nummer($erstes), $r->body);
        $this->assertStringContainsString('Wunschpreis 12,50 €', $r->body);
        $this->assertStringContainsString('20,00 €', $r->body, 'Summe');
        $this->assertStringContainsString('Karte/Überweisung', $r->body);
        foreach (['name="name"', 'name="email"', 'name="telefon"', 'Käufer'] as $kein) {
            $this->assertStringNotContainsString($kein, $r->body, 'keine Käuferdaten');
        }

        // Preis ändern
        $this->assertSame(303, $this->post("/boersen/$b/kasse/preis", ['preis' => [(string) $zweites => '10']])->status);
        $this->assertStringContainsString('22,50 €', $this->get("/boersen/$b/kasse")->body);
        $r = $this->post("/boersen/$b/kasse/preis", ['preis' => [(string) $zweites => 'zehn']]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte den Betrag angeben, z. B. 12,50.', $r->body);

        // ohne Zahlart
        $r = $this->post("/boersen/$b/kasse/abschliessen", ['preis' => [(string) $erstes => '12,50', (string) $zweites => '10,00']]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte die Zahlart wählen.', $r->body);
        $this->assertCount(2, $this->sitzung['kasse_' . $b]);

        $r = $this->post("/boersen/$b/kasse/abschliessen", ['zahlart' => 'bar', 'preis' => [(string) $erstes => '12,50', (string) $zweites => '10,00']]);
        $this->assertSame(303, $r->status, $r->body);
        $this->assertSame(['Verkauf über 22,50 € abgeschlossen (bar).'], $this->meldungen());
        $this->assertSame([], $this->sitzung['kasse_' . $b] ?? []);
        $this->assertSame('22.50', $this->pdo->query('SELECT summe FROM boerse_verkauf')->fetchColumn());
        $this->assertSame('10.00', $this->pdo->query("SELECT preis FROM boerse_verkauf_position WHERE angebot_id = $zweites")->fetchColumn());
    }

    public function test_bereits_verkauft_bleibt_im_korb_und_rot(): void
    {
        $b = $this->boerse();
        $a = $this->angebot($b);
        $weiteres = $this->angebot($b, '3.00', 'Signal');
        $this->anmelden($this->wart);
        $this->post("/boersen/$b/kasse/hinzu", ['angebot_id' => (string) $a]);
        $this->post("/boersen/$b/kasse/hinzu", ['angebot_id' => (string) $weiteres]);
        // Eine andere Kasse verkauft das Stück inzwischen.
        $this->kasse->abschliessen($b, [['angebot_id' => $a, 'preis' => '12.50']], 'bar', null, new \DateTimeImmutable());

        $r = $this->post("/boersen/$b/kasse/abschliessen", ['zahlart' => 'karte_ueberweisung']);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString(e($this->nummer($a) . ' ist bereits verkauft.'), $r->body);
        $this->assertMatchesRegularExpression('/kasse-karte-fehler[^>]*data-angebot="' . $a . '"/', $r->body);
        $this->assertDoesNotMatchRegularExpression('/kasse-karte-fehler[^>]*data-angebot="' . $weiteres . '"/', $r->body);
        $this->assertCount(2, $this->sitzung['kasse_' . $b]);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM boerse_verkauf'));
        $this->assertSame('angeboten', $this->pdo->query("SELECT status FROM boerse_angebot WHERE id = $weiteres")->fetchColumn());
        // Entfernen
        $this->assertSame(303, $this->post("/boersen/$b/kasse/entfernen", ['angebot_id' => (string) $a])->status);
        $this->assertSame([['angebot_id' => $weiteres, 'preis' => '3.00']], $this->sitzung['kasse_' . $b]);
    }

    public function test_boerse_laeuft_nicht(): void
    {
        $b = $this->boerse('in_vorbereitung');
        $a = $this->angebot($b);
        $this->anmelden($this->wart);
        $r = $this->get("/boersen/$b/kasse");
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Die Börse läuft nicht – Verkauf nicht möglich.', $r->body);
        $r = $this->post("/boersen/$b/kasse/hinzu", ['nummer' => (string) $this->lauf($a)]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Die Börse läuft nicht – Verkauf nicht möglich.', $r->body);
        $this->assertSame(404, $this->get('/boersen/999/kasse')->status);
    }

    public function test_verkaufsliste_und_storno(): void
    {
        $b = $this->boerse();
        $a = $this->angebot($b);
        $alt = $this->angebot($b, '4.00', 'Alt');
        $v = $this->kasse->abschliessen($b, [['angebot_id' => $a, 'preis' => '12.50']], 'karte_ueberweisung', $this->wart->id, new \DateTimeImmutable());
        $gestern = $this->kasse->abschliessen($b, [['angebot_id' => $alt, 'preis' => '4.00']], 'bar', $this->wart->id, new \DateTimeImmutable('-1 day'));
        $this->anmelden($this->wart);

        $r = $this->get("/boersen/$b/verkaeufe");
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString($this->nummer($a), $r->body);
        $this->assertStringContainsString('12,50 €', $r->body);
        $this->assertStringContainsString('Karte/Überweisung', $r->body);
        $this->assertStringContainsString('action="' . e(url("/verkaeufe/$v/storno")) . '"', $r->body);
        $this->assertStringNotContainsString('action="' . e(url("/verkaeufe/$gestern/storno")) . '"', $r->body, 'Storno nur am selben Tag');

        $r = $this->post("/verkaeufe/$v/storno", ['grund' => '']);
        $this->assertSame(303, $r->status);
        $this->assertSame(['Bitte einen Grund für das Storno angeben.'], $this->meldungen('fehler'));
        $this->assertSame(url("/boersen/$b/verkaeufe"), $r->headers['Location']);

        $r = $this->post("/verkaeufe/$v/storno", ['grund' => 'Falsch eingetippt']);
        $this->assertSame(303, $r->status);
        $this->assertSame(['Der Verkauf wurde storniert. Die Stücke sind wieder im Angebot.'], $this->meldungen());
        $this->assertSame('angeboten', $this->pdo->query("SELECT status FROM boerse_angebot WHERE id = $a")->fetchColumn());
        $r = $this->get("/boersen/$b/verkaeufe");
        $this->assertStringContainsString('storniert: Falsch eingetippt', $r->body);
        $this->assertStringNotContainsString('action="' . e(url("/verkaeufe/$v/storno")) . '"', $r->body);

        $this->assertSame(404, $this->post('/verkaeufe/999/storno', ['grund' => 'x'])->status);
    }
}
