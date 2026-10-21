<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Fahrzeuge;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

/** Abstellgleis: ausgemusterte Fahrzeuge an einem eigenen Ort, mit Datum, Abstellen und Zurückholen. */
final class AbstellgleisHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private int $h0;
    private int $tt;
    private int $wart;
    private int $mitglied;
    private int $anderes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->tt = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'TT'")->fetchColumn();
        $this->wart = $this->legeBenutzerAn('wart@verein.de', 'geheim-passwort-1', 'fahrzeugwart');
        $this->mitglied = $this->legeBenutzerAn('mitglied@verein.de', 'geheim-passwort-1', 'mitglied');
        $this->anderes = $this->legeBenutzerAn('anderes@verein.de', 'geheim-passwort-1', 'mitglied');
    }

    private function als(int $benutzerId): void
    {
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $benutzerId);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    private function get(string $pfad, array $query = []): Response
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung))->handle(new Request('GET', $pfad, $query));
    }

    private function post(string $pfad, array $d = []): Response
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung))
            ->handle(new Request('POST', $pfad, [], $d + ['_csrf' => str_repeat('a', 64)]));
    }

    private function personVon(int $benutzerId): int
    {
        return (int) $this->pdo->query('SELECT person_id FROM benutzer WHERE id = ' . $benutzerId)->fetchColumn();
    }

    private function fahrzeug(string $nummer, string $bezeichnung, string $status = 'einsatzbereit', ?int $eigentuemerBenutzer = null, ?string $seit = null, ?int $spurweite = null): int
    {
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, status, eigentuemer_person_id, angelegt_von_benutzer_id, ausgemustert_am) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$nummer, $bezeichnung, $spurweite ?? $this->h0, $status, $eigentuemerBenutzer === null ? null : $this->personVon($eigentuemerBenutzer), $eigentuemerBenutzer, $seit]);
        return (int) $this->pdo->lastInsertId();
    }

    private function feld(int $fz, string $spalte): mixed
    {
        return $this->pdo->query("SELECT $spalte FROM fahrzeug WHERE id = $fz")->fetchColumn();
    }

    private function heute(): string
    {
        return date('Y-m-d'); // Zeitzone der Anwendung (von App::erzeuge gesetzt)
    }

    public function test_liste_blendet_aus_abstellgleis_zeigt_mit_datum(): void
    {
        $this->fahrzeug('EMC-0001', 'Fleißige V 100');
        $this->fahrzeug('EMC-0002', 'Alte Dampflok', 'ausgemustert', null, '2026-03-15');
        $this->als($this->mitglied);
        $liste = $this->get('/fahrzeuge')->body;
        $this->assertStringContainsString('Fleißige V 100', $liste);
        $this->assertStringNotContainsString('Alte Dampflok', $liste);

        $r = $this->get('/abstellgleis');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Alte Dampflok', $r->body);
        $this->assertStringContainsString('seit 15.03.2026', $r->body);
        $this->assertStringNotContainsString('Fleißige V 100', $r->body);
    }

    public function test_filter_wirken_auf_abstellgleis(): void
    {
        $this->fahrzeug('EMC-0001', 'Vereinslok H0', 'ausgemustert', null, '2026-01-01');
        $this->fahrzeug('EMC-0002', 'Private TT', 'ausgemustert', $this->mitglied, '2026-01-02', $this->tt);
        $this->fahrzeug('EMC-0003', 'Andere H0', 'ausgemustert', $this->anderes, '2026-01-03');
        $this->als($this->mitglied);
        $namen = fn(array $q): array => array_values(array_filter(
            ['Vereinslok H0', 'Private TT', 'Andere H0'],
            fn(string $n) => str_contains($this->get('/abstellgleis', $q)->body, $n)
        ));
        $this->assertSame(['Vereinslok H0', 'Private TT', 'Andere H0'], $namen([]));
        $this->assertSame(['Private TT'], $namen(['spurweite' => (string) $this->tt]));
        $this->assertSame(['Vereinslok H0'], $namen(['eigentuemer' => 'verein']));
        $this->assertSame(['Private TT', 'Andere H0'], $namen(['eigentuemer' => 'mitglied']));
        $this->assertSame(['Andere H0'], $namen(['person' => (string) $this->personVon($this->anderes)]));
    }

    public function test_meine_fahrzeuge_ohne_ausgemusterte(): void
    {
        $this->fahrzeug('EMC-0001', 'Meine Aktive', 'einsatzbereit', $this->mitglied);
        $this->fahrzeug('EMC-0002', 'Meine Alte', 'ausgemustert', $this->mitglied, '2026-02-01');
        $this->als($this->mitglied);
        $body = $this->get('/fahrzeuge')->body;
        $this->assertStringContainsString('Meine Fahrzeuge', $body);
        $this->assertStringContainsString('Meine Aktive', $body);
        $this->assertStringNotContainsString('Meine Alte', $body);
    }

    public function test_mitglied_stellt_eigenes_fahrzeug_ab_aber_kein_fremdes(): void
    {
        $eigenes = $this->fahrzeug('EMC-0001', 'Meine Lok', 'defekt', $this->mitglied);
        $fremdes = $this->fahrzeug('EMC-0002', 'Fremde Lok', 'einsatzbereit', $this->anderes);
        $this->als($this->mitglied);

        $r = $this->post("/fahrzeuge/$eigenes/abstellen");
        $this->assertSame(303, $r->status);
        $this->assertSame('ausgemustert', $this->feld($eigenes, 'status'));
        $this->assertSame($this->heute(), $this->feld($eigenes, 'ausgemustert_am'));
        $eintrag = $this->pdo->query("SELECT aktion, aenderungen, benutzer_id FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $eigenes")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('geaendert', $eintrag['aktion']);
        $this->assertSame($this->mitglied, (int) $eintrag['benutzer_id']);
        $this->assertStringContainsString('ausgemustert', (string) $eintrag['aenderungen']);
        $this->assertStringContainsString('defekt', (string) $eintrag['aenderungen']);

        $this->assertSame(403, $this->post("/fahrzeuge/$fremdes/abstellen")->status);
        $this->assertSame('einsatzbereit', $this->feld($fremdes, 'status'));
        $this->assertSame(403, $this->post("/fahrzeuge/$fremdes/zurueckholen")->status);
        $this->assertSame(404, $this->post('/fahrzeuge/999999/abstellen')->status);
    }

    public function test_fahrzeugwart_stellt_fremdes_privates_fahrzeug_ab(): void
    {
        $fz = $this->fahrzeug('EMC-0001', 'Private Lok', 'einsatzbereit', $this->mitglied);
        $this->als($this->wart);
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/abstellen")->status);
        $this->assertSame('ausgemustert', $this->feld($fz, 'status'));
        $this->assertSame($this->heute(), $this->feld($fz, 'ausgemustert_am'));
    }

    public function test_zurueckholen_setzt_einsatzbereit_und_leert_datum(): void
    {
        $fz = $this->fahrzeug('EMC-0001', 'Alte Lok', 'ausgemustert', $this->mitglied, '2026-01-10');
        $this->als($this->mitglied);
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/zurueckholen")->status);
        $this->assertSame('einsatzbereit', $this->feld($fz, 'status'));
        $this->assertNull($this->feld($fz, 'ausgemustert_am'));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND aktion = 'geaendert'")->fetchColumn());
    }

    /** Offene dringende Reparatur: wieder in Betrieb heißt „defekt“ (automatisch), nicht „einsatzbereit“. */
    public function test_zurueckholen_mit_offener_dringender_reparatur_wird_defekt(): void
    {
        $fz = $this->fahrzeug('EMC-0001', 'Alte Lok', 'ausgemustert', $this->mitglied, '2026-01-10');
        $this->pdo->exec("INSERT INTO reparatur (objekt_typ, objekt_id, beschreibung, gemeldet_am, prioritaet) VALUES ('fahrzeug', $fz, 'Motor brummt', NOW(), 'dringend')");
        $this->als($this->wart);
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/zurueckholen")->status);
        $this->assertSame('defekt', $this->feld($fz, 'status'));
        $this->assertSame(1, (int) $this->feld($fz, 'status_automatisch'));
        $this->assertNull($this->feld($fz, 'ausgemustert_am'));
        $eintraege = $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(2, $eintraege);
        $this->assertStringContainsString('"neu":"einsatzbereit"', (string) $eintraege[0]);
        $this->assertStringContainsString('defekt (automatisch durch Reparatur)', (string) $eintraege[1]);

        // Abstellen bleibt „ausgemustert“ trotz offener dringender Reparatur.
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/abstellen")->status);
        $this->assertSame('ausgemustert', $this->feld($fz, 'status'));
        $this->assertSame(0, (int) $this->feld($fz, 'status_automatisch'));
    }

    public function test_detailseite_zeigt_hinweis_und_knoepfe(): void
    {
        $alt = $this->fahrzeug('EMC-0001', 'Alte Lok', 'ausgemustert', $this->mitglied, '2026-04-01');
        $aktiv = $this->fahrzeug('EMC-0002', 'Aktive Lok', 'einsatzbereit', $this->mitglied);
        $this->als($this->mitglied);
        $detail = $this->get("/fahrzeuge/$alt")->body;
        $this->assertStringContainsString('Auf dem Abstellgleis seit 01.04.2026', $detail);
        $this->assertStringContainsString('Zurück in Betrieb', $detail);
        $this->assertStringContainsString("/fahrzeuge/$alt/zurueckholen", $detail);
        $this->assertStringNotContainsString('Aufs Abstellgleis', $detail);

        $detail = $this->get("/fahrzeuge/$aktiv")->body;
        $this->assertStringContainsString('Aufs Abstellgleis', $detail);
        $this->assertStringContainsString("/fahrzeuge/$aktiv/abstellen", $detail);
        $this->assertStringNotContainsString('Zurück in Betrieb', $detail);

        // Ohne Bearbeitungsrecht keine Knöpfe, aber der Hinweis
        $this->als($this->anderes);
        $detail = $this->get("/fahrzeuge/$alt")->body;
        $this->assertStringContainsString('Auf dem Abstellgleis seit 01.04.2026', $detail);
        $this->assertStringNotContainsString('Zurück in Betrieb', $detail);
        $this->assertStringNotContainsString('Aufs Abstellgleis', $this->get("/fahrzeuge/$aktiv")->body);
    }

    public function test_formular_status_setzt_und_leert_datum(): void
    {
        $fz = $this->fahrzeug('EMC-0001', 'Lok', 'einsatzbereit', $this->mitglied);
        $this->als($this->mitglied);
        $daten = fn(string $status): array => ['bezeichnung' => 'Lok', 'gattung' => 'lok', 'spurweite_id' => (string) $this->h0, 'stromsystem' => '2L',
            'status' => $status, 'betriebsstunden' => '0', '_stand' => (string) $this->feld($fz, 'geaendert_am')];

        $this->assertSame(303, $this->post("/fahrzeuge/$fz", $daten('ausgemustert'))->status);
        $this->assertSame($this->heute(), $this->feld($fz, 'ausgemustert_am'));

        // Speichern ohne Statuswechsel behält das ursprüngliche Datum
        $this->pdo->exec("UPDATE fahrzeug SET ausgemustert_am = '2026-01-05' WHERE id = $fz");
        $this->assertSame(303, $this->post("/fahrzeuge/$fz", ['bezeichnung' => 'Lok alt'] + $daten('ausgemustert'))->status);
        $this->assertSame('2026-01-05', $this->feld($fz, 'ausgemustert_am'));

        $this->assertSame(303, $this->post("/fahrzeuge/$fz", $daten('defekt'))->status);
        $this->assertNull($this->feld($fz, 'ausgemustert_am'));

        // Anlegen direkt als ausgemustert
        $this->assertSame(303, $this->post('/fahrzeuge', ['bezeichnung' => 'Gleich alt'] + $daten('ausgemustert'))->status);
        $neu = (int) $this->pdo->query("SELECT id FROM fahrzeug WHERE bezeichnung = 'Gleich alt'")->fetchColumn();
        $this->assertSame($this->heute(), $this->feld($neu, 'ausgemustert_am'));
    }

    public function test_navigation_zeigt_abstellgleis_mit_anzahl(): void
    {
        $this->fahrzeug('EMC-0001', 'A', 'ausgemustert', null, '2026-01-01');
        $this->fahrzeug('EMC-0002', 'B', 'ausgemustert', null, '2026-01-01');
        $this->fahrzeug('EMC-0003', 'C');
        $this->als($this->mitglied);
        $body = $this->get('/fahrzeuge')->body;
        $this->assertMatchesRegularExpression('#href="/abstellgleis"[^>]*>\s*Abstellgleis\s*<span[^>]*>2</span>#', $body);
    }

    public function test_suche_findet_ausgemusterte_mit_hinweis_abstellgleis(): void
    {
        $this->fahrzeug('EMC-0007', 'Alte Dampflok', 'ausgemustert', null, '2026-01-01');
        $this->als($this->mitglied);
        $body = $this->get('/fahrzeuge', ['q' => 'EMC-0007'])->body;
        $this->assertStringContainsString('Alte Dampflok', $body);
        $this->assertStringContainsString('>Abstellgleis</span>', $body);
    }

    public function test_statusfilter_beschriftung_passt_zur_textsuche(): void
    {
        $this->als($this->mitglied);
        $ohne = $this->get('/fahrzeuge')->body;
        $this->assertStringContainsString('<option value="">ohne Abstellgleis</option>', $ohne);
        $this->assertStringNotContainsString('ohne ausgemusterte', $ohne);
        $mit = $this->get('/fahrzeuge', ['q' => 'Lok'])->body;
        $this->assertStringContainsString('<option value="">alle (auch Abstellgleis)</option>', $mit);
        $this->assertStringContainsString('Die Suche findet auch Fahrzeuge auf dem Abstellgleis.', $mit);
    }

    /** Das Abstelldatum folgt der Zeitzone der Anwendung, nicht der Sitzungszeitzone des Datenbankservers. */
    public function test_datum_in_zeitzone_der_anwendung(): void
    {
        $alt = date_default_timezone_get();
        $repo = new \Emc\Fahrzeuge\FahrzeugRepository($this->pdo);
        $this->pdo->exec("SET time_zone = '+13:00'");
        date_default_timezone_set('Etc/GMT+12'); // UTC-12: Datum weicht immer vom DB-Datum ab
        try {
            $erwartet = date('Y-m-d');
            $this->assertNotSame($erwartet, (string) $this->pdo->query('SELECT CURDATE()')->fetchColumn());
            $fz = $this->fahrzeug('EMC-0001', 'Lok');
            $repo->setzeAbgestellt($fz, true);
            $this->assertSame($erwartet, $this->feld($fz, 'ausgemustert_am'));

            $daten = ['bezeichnung' => 'Neu', 'spurweite_id' => $this->h0, 'status' => 'ausgemustert'];
            $neu = $repo->anlegen($daten);
            $this->assertSame($erwartet, $this->feld($neu, 'ausgemustert_am'));
            $this->pdo->exec("UPDATE fahrzeug SET status = 'defekt', ausgemustert_am = NULL WHERE id = $neu");
            $repo->aktualisieren($neu, $daten, (string) $this->feld($neu, 'geaendert_am'));
            $this->assertSame($erwartet, $this->feld($neu, 'ausgemustert_am'));
        } finally {
            date_default_timezone_set($alt);
            $this->pdo->exec("SET time_zone = SYSTEM");
        }
    }

    public function test_adresspruefung_ignoriert_ausgemusterte(): void
    {
        $alt = $this->fahrzeug('EMC-0001', 'Alte Lok', 'ausgemustert', null, '2026-01-01');
        $this->pdo->prepare("INSERT INTO decoder (fahrzeug_id, bezeichnung, protokoll, adresse) VALUES (?, 'Motor', 'dcc', 3)")->execute([$alt]);
        $this->als($this->mitglied);
        $r = $this->get('/api/adresspruefung', ['protokoll' => 'dcc', 'adresse' => '3', 'spurweite' => (string) $this->h0]);
        $json = json_decode($r->body, true);
        $this->assertSame([], $json['konflikte']);
        $this->assertNull($json['warnung']);
    }
}
