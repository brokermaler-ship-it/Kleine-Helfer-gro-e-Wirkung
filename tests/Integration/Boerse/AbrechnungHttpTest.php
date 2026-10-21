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

/** Abrechnung über HTTP: Rechte, Seite und Druckansicht, Haken „ausgezahlt“, Abschluss und Wiederöffnen (nur Admin). */
final class AbrechnungHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private AngebotDienst $angebote;
    private KasseDienst $kasse;
    private Benutzer $wart;
    private Benutzer $carla;
    private Benutzer $eva;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $rep = new AngebotRepository($this->pdo);
        $magazinRep = new MagazinRepository($this->pdo);
        $protokoll = new Aenderungsprotokoll($this->pdo);
        $this->angebote = new AngebotDienst($this->pdo, $rep, $magazinRep, $protokoll, new BoerseRepository($this->pdo));
        $this->kasse = new KasseDienst($this->pdo, $rep, new MagazinDienst($this->pdo, $magazinRep, $protokoll, $rep), $protokoll);
        $this->wart = $this->benutzer('greta@verein.de', 'mitglied', true, 'Greta', 'Gleis');
        $this->carla = $this->benutzer('carla@verein.de', 'mitglied', false, 'Carla', 'Beispiel');
        $this->eva = $this->benutzer('eva@verein.de', 'mitglied', false, 'Eva', 'Exempel');
    }

    private function benutzer(string $email, string $rolle, bool $boersenwart, string $vorname = 'Max', string $nachname = 'Muster'): Benutzer
    {
        $id = $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle);
        $this->pdo->prepare('UPDATE benutzer SET ist_boersenwart = ? WHERE id = ?')->execute([$boersenwart ? 1 : 0, $id]);
        $person = (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = $id")->fetchColumn();
        $this->pdo->prepare('UPDATE person SET vorname = ?, nachname = ? WHERE id = ?')->execute([$vorname, $nachname, $person]);
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

    private function boerse(string $status = 'laeuft'): int
    {
        $this->pdo->prepare("INSERT INTO boerse (name, datum, status, provision_prozent) VALUES ('Herbstbörse', ?, ?, '10.00')")->execute([date('Y-m-d'), $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function angebot(int $boerseId, Benutzer $von, string $preis, bool $spende = false): int
    {
        $kat = (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'")->fetchColumn();
        return $this->angebote->anlegen([
            'bezeichnung' => 'BR 50 Märklin', 'beschreibung' => null, 'kategorie_id' => $kat, 'spurweite_id' => null, 'zustand' => 'gebraucht',
            'wunschpreis' => $preis, 'menge' => 1, 'herkunft' => 'mitglied', 'person_id' => $von->personId, 'erloes_spende' => $spende ? 1 : 0,
            'boerse_id' => $boerseId, 'notiz' => null,
        ], $von, new \DateTimeImmutable());
    }

    /** Börse mit Carla 12,50 € + 0,05 € (Provision 1,26 €, Auszahlung 11,29 €) und Evas Spende 8,00 €. */
    private function boerseMitVerkaeufen(): int
    {
        $b = $this->boerse();
        $p = [];
        foreach ([[$this->carla, '12.50', false], [$this->carla, '0.05', false], [$this->eva, '8.00', true]] as [$von, $preis, $spende]) {
            $p[] = ['angebot_id' => $this->angebot($b, $von, $preis, $spende), 'preis' => $preis];
        }
        $this->kasse->abschliessen($b, $p, 'bar', null, new \DateTimeImmutable());
        return $b;
    }

    public function test_boersenwart_sieht_abrechnung(): void
    {
        $b = $this->boerseMitVerkaeufen();
        $this->anmelden($this->wart);

        $r = $this->get("/boersen/$b/abrechnung");

        $this->assertSame(200, $r->status, $r->body);
        $this->assertStringContainsString('11,29 €', $r->body);
        $this->assertStringContainsString('0,00 € (Spende)', $r->body);
        $this->assertStringContainsString('Carla Beispiel', $r->body);
        $this->assertStringContainsString('Eva Exempel', $r->body);
        $this->assertStringContainsString('1,26 €', $r->body, 'Provisionen');
        $this->assertStringContainsString('20,55 €', $r->body, 'Summe Zahlarten');
        $this->assertStringNotContainsString('Die Summen passen nicht zusammen', $r->body);
        $this->assertStringContainsString('Börse abschließen? Unverkaufte Stücke gehen zurück. Danach sind Kasse und Angebote dieser Börse gesperrt.', $r->body);
        $this->assertStringContainsString('navbar', $r->body);
        $this->assertStringContainsString(e(url("/boersen/$b/abrechnung", ['druck' => '1'])), $r->body);

        $seite = $this->get("/boersen/$b");
        $this->assertStringContainsString(e(url("/boersen/$b/abrechnung")), $seite->body);
    }

    public function test_druckansicht_ohne_navigation(): void
    {
        $b = $this->boerseMitVerkaeufen();
        $this->anmelden($this->wart);

        $r = $this->get("/boersen/$b/abrechnung", ['druck' => '1']);

        $this->assertSame(200, $r->status);
        $this->assertStringNotContainsString('navbar', $r->body);
        $this->assertStringNotContainsString('<form', $r->body, 'keine Formulare in der Druckansicht');
        $this->assertStringContainsString('11,29 €', $r->body);
        $this->assertStringContainsString('0,00 € (Spende)', $r->body);
        $this->assertStringContainsString('window.print', $r->body);
    }

    public function test_mitglied_ohne_haekchen_403(): void
    {
        $b = $this->boerseMitVerkaeufen();
        $this->anmelden($this->carla);
        $this->assertSame(403, $this->get("/boersen/$b/abrechnung")->status);
        $this->assertSame(403, $this->get("/boersen/$b/abrechnung", ['druck' => '1'])->status);
        $this->assertSame(403, $this->post("/boersen/$b/abschliessen")->status);
        $this->assertSame(403, $this->post("/boersen/$b/auszahlungen", ['person_id' => (string) $this->carla->personId])->status);
        $this->assertSame(403, $this->post("/boersen/$b/auszahlungen/{$this->carla->personId}/loeschen")->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM boerse_auszahlung')->fetchColumn());
        $this->assertSame('laeuft', $this->pdo->query("SELECT status FROM boerse WHERE id = $b")->fetchColumn());
        $this->anmelden($this->wart);
        $this->assertSame(404, $this->get('/boersen/999999/abrechnung')->status);
    }

    public function test_haken_ausgezahlt(): void
    {
        $b = $this->boerseMitVerkaeufen();
        $this->anmelden($this->wart);
        $heute = date('Y-m-d');
        $this->assertStringContainsString('value="' . $heute . '"', $this->get("/boersen/$b/abrechnung")->body, 'Standard heute');

        $r = $this->post("/boersen/$b/auszahlungen", ['person_id' => (string) $this->carla->personId, 'ausgezahlt_am' => $heute]);

        $this->assertSame(303, $r->status);
        $this->assertSame(url("/boersen/$b/abrechnung"), $r->headers['Location']);
        $this->assertSame(['Auszahlung an Carla Beispiel vermerkt.'], $this->meldungen());
        $this->assertSame(['11.29', $heute], array_values($this->pdo->query('SELECT betrag, ausgezahlt_am FROM boerse_auszahlung')->fetch(\PDO::FETCH_ASSOC)));
        $seite = $this->get("/boersen/$b/abrechnung")->body;
        $this->assertStringContainsString('ausgezahlt am ' . date('d.m.Y'), $seite);

        // ungültiges Datum, Spende, unbekannte Person
        $this->post("/boersen/$b/auszahlungen", ['person_id' => (string) $this->eva->personId, 'ausgezahlt_am' => $heute]);
        $this->assertContains('Für dieses Mitglied ist nichts auszuzahlen.', $this->meldungen('fehler'));
        $this->post("/boersen/$b/auszahlungen", ['person_id' => (string) $this->eva->personId, 'ausgezahlt_am' => 'gestern']);
        $this->assertContains('Bitte ein gültiges Datum für die Auszahlung angeben.', $this->meldungen('fehler'));
        $this->assertSame(404, $this->post("/boersen/$b/auszahlungen", ['person_id' => 'abc'])->status);

        // Haken entfernen
        $r = $this->post("/boersen/$b/auszahlungen/{$this->carla->personId}/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM boerse_auszahlung')->fetchColumn());
    }

    public function test_abschliessen_und_wieder_oeffnen(): void
    {
        $b = $this->boerseMitVerkaeufen();
        $offen = $this->angebot($b, $this->carla, '3.00');
        $this->anmelden($this->wart);

        $r = $this->post("/boersen/$b/abschliessen");

        $this->assertSame(303, $r->status);
        $this->assertSame(['Die Börse ist abgeschlossen. 1 Stück geht zurück.'], $this->meldungen());
        $this->assertSame('zurueck', $this->pdo->query("SELECT status FROM boerse_angebot WHERE id = $offen")->fetchColumn());
        $seite = $this->get("/boersen/$b/abrechnung")->body;
        $this->assertStringNotContainsString('Börse abschließen?', $seite);
        $this->assertStringContainsString('11,29 €', $seite, 'Abrechnung bleibt sichtbar');
        $this->post("/boersen/$b/abschliessen");
        $this->assertContains('Nur eine laufende Börse lässt sich abschließen.', $this->meldungen('fehler'));

        // Wiederöffnen: Börsenwart-Mitglied und Bearbeiter nicht, Admin schon
        $this->assertSame(403, $this->post("/boersen/$b/wieder-oeffnen")->status);
        $bearbeiter = $this->benutzer('bea@verein.de', 'bearbeiter', false);
        $this->anmelden($bearbeiter);
        $this->assertSame(403, $this->post("/boersen/$b/wieder-oeffnen")->status);
        $this->assertStringNotContainsString('wieder-oeffnen', $this->get("/boersen/$b/abrechnung")->body);
        $admin = $this->benutzer('admin@verein.de', 'admin', false);
        $this->anmelden($admin);
        $this->assertStringContainsString(e(url("/boersen/$b/wieder-oeffnen")), $this->get("/boersen/$b/abrechnung")->body);
        $r = $this->post("/boersen/$b/wieder-oeffnen");
        $this->assertSame(303, $r->status);
        $this->assertSame(['Die Börse ist wieder geöffnet.'], $this->meldungen());
        $this->assertSame('laeuft', $this->pdo->query("SELECT status FROM boerse WHERE id = $b")->fetchColumn());
        $this->assertSame('zurueck', $this->pdo->query("SELECT status FROM boerse_angebot WHERE id = $offen")->fetchColumn());
    }

    public function test_mehrere_stuecke_meldung(): void
    {
        $b = $this->boerse();
        $this->angebot($b, $this->carla, '3.00');
        $this->angebot($b, $this->carla, '4.00');
        $this->anmelden($this->wart);
        $this->post("/boersen/$b/abschliessen");
        $this->assertSame(['Die Börse ist abgeschlossen. 2 Stücke gehen zurück.'], $this->meldungen());
    }

    public function test_provision_nach_verkauf_gesperrt(): void
    {
        $b = $this->boerseMitVerkaeufen();
        $this->anmelden($this->wart);
        $stand = (string) $this->pdo->query("SELECT geaendert_am FROM boerse WHERE id = $b")->fetchColumn();

        $r = $this->post("/boersen/$b", ['name' => 'Herbstbörse', 'datum' => date('Y-m-d'), 'ort' => '', 'provision_prozent' => '15', 'stand' => $stand]);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Die Provision lässt sich nach dem ersten Verkauf nicht mehr ändern.', $r->body);
        $this->assertSame('10.00', $this->pdo->query("SELECT provision_prozent FROM boerse WHERE id = $b")->fetchColumn());
    }

    /**
     * Verkauf nach dem Haken „ausgezahlt“: Hinweis in der Zeile und oben auf Seite und Druckansicht, in der
     * Druckansicht mit Feld für die Nachzahlung.
     */
    public function test_warnung_wenn_auszahlung_abweicht(): void
    {
        $b = $this->boerseMitVerkaeufen();
        $this->anmelden($this->wart);
        $this->post("/boersen/$b/auszahlungen", ['person_id' => (string) $this->carla->personId, 'ausgezahlt_am' => date('Y-m-d')]);
        $seite = $this->get("/boersen/$b/abrechnung")->body;
        $this->assertStringNotContainsString('weicht der Betrag vom vermerkten ab', $seite);

        $this->kasse->abschliessen($b, [['angebot_id' => $this->angebot($b, $this->carla, '5.00'), 'preis' => '5.00']], 'bar', null, new \DateTimeImmutable());

        $seite = $this->get("/boersen/$b/abrechnung")->body;
        $this->assertStringContainsString('Vermerkt waren 11,29 €, jetzt sind es 15,79 €.', $seite);
        $this->assertStringContainsString('Bei 1 Mitglied weicht der Betrag vom vermerkten ab – bitte nachzahlen und neu vermerken.', $seite);
        $druck = $this->get("/boersen/$b/abrechnung", ['druck' => '1'])->body;
        $this->assertStringContainsString('Bei 1 Mitglied weicht der Betrag vom vermerkten ab – bitte nachzahlen und neu vermerken.', $druck);
        $this->assertStringContainsString('Nachzahlung 4,50 € am ____________', $druck);
    }

    public function test_auszahlungsdatum_nicht_in_der_zukunft(): void
    {
        $b = $this->boerseMitVerkaeufen();
        $this->anmelden($this->wart);
        $morgen = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $this->post("/boersen/$b/auszahlungen", ['person_id' => (string) $this->carla->personId, 'ausgezahlt_am' => $morgen]);
        $this->assertContains('Das Auszahlungsdatum darf nicht in der Zukunft liegen.', $this->meldungen('fehler'));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM boerse_auszahlung')->fetchColumn());
        $this->assertStringContainsString('max="' . date('Y-m-d') . '"', $this->get("/boersen/$b/abrechnung")->body);
    }
}
