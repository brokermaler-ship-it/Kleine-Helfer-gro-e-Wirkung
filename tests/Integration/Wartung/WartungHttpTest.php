<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Wartung;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\UploadService;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;
use Emc\Wartung\AusnahmeRepository;
use Emc\Wartung\WartungRepository;

final class WartungHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private int $spurweite;
    private string $speicher;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->spurweite = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    private function app(): App
    {
        $app = App::erzeuge(['speicher_pfad' => $this->speicher] + self::testConfig(), new Session($this->sitzung));
        // Testnaht: keine echten HTTP-Uploads.
        $app->container()->set(UploadService::class, fn() => new UploadService(
            $this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b)
        ));
        return $app;
    }

    /** @return array{0:int,1:int} Benutzer-ID und Person-ID */
    private function anmelden(string $rolle, string $email = ''): array
    {
        $id = $this->legeBenutzerAn($email !== '' ? $email : $rolle . '@verein.de', 'geheim-passwort-1', $rolle);
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        return [$id, (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = $id")->fetchColumn()];
    }

    private function get(string $pfad): Response
    {
        return $this->app()->handle(new Request('GET', $pfad));
    }

    private function post(string $pfad, array $daten = [], array $dateien = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)], $dateien));
    }

    private function fahrzeug(string $gattung = 'lok', ?int $eigentuemer = null, ?int $angelegtVon = null, string $stunden = '40.0'): int
    {
        static $n = 0;
        $n++;
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, gattung, spurweite_id, eigentuemer_person_id, angelegt_von_benutzer_id, betriebsstunden) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([sprintf('EMC-%04d', $n), 'V 200', $gattung, $this->spurweite, $eigentuemer, $angelegtVon, $stunden]);
        return (int) $this->pdo->lastInsertId();
    }

    private function anlage(): int
    {
        $this->pdo->prepare("INSERT INTO anlage (nummer, name, spurweite_id) VALUES ((SELECT COALESCE(MAX(a.nummer), 0) + 1 FROM anlage a), 'Clubanlage', ?)")
            ->execute([$this->spurweite]);
        return (int) $this->pdo->lastInsertId();
    }

    private function art(string $name): int
    {
        $st = $this->pdo->prepare('SELECT id FROM wartungsart WHERE name = ?');
        $st->execute([$name]);
        return (int) $st->fetchColumn();
    }

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
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

    /** @return array<string,mixed> */
    private function eintrag(int $personId, array $u = []): array
    {
        return $u + [
            'datum' => date('Y-m-d'),
            'arten' => [(string) $this->art('Räder und Radschleifer reinigen'), (string) $this->art('Kupplungen prüfen')],
            'betriebsstunden' => '',
            'ausgefuehrt_von' => (string) $personId,
            'notiz' => 'Alles sauber',
        ];
    }

    /** @return array{name:array<int,string>,tmp_name:array<int,string>,error:array<int,int>,size:array<int,int>} */
    private function upload(string ...$fixtures): array
    {
        $f = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
        foreach ($fixtures as $fixture) {
            $tmp = tempnam(sys_get_temp_dir(), 'up');
            $this->temp[] = $tmp;
            copy(dirname(__DIR__, 2) . '/fixtures/dateien/' . $fixture, $tmp);
            $f['name'][] = $fixture;
            $f['tmp_name'][] = $tmp;
            $f['error'][] = UPLOAD_ERR_OK;
            $f['size'][] = (int) filesize($tmp);
        }
        return $f;
    }

    /** @return array<string,mixed> */
    private function letzteWartung(): array
    {
        return $this->pdo->query('SELECT * FROM wartung ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    public function test_mitglied_traegt_an_eigenem_fahrzeug_ein(): void
    {
        [$uid, $pid] = $this->anmelden('mitglied');
        $fz = $this->fahrzeug('lok', $pid, null);
        $r = $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid));
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/$fz#wartung", $r->headers['Location']);
        $this->assertSame('Die Wartung wurde eingetragen.', $this->meldung('erfolg'));
        $w = $this->letzteWartung();
        $this->assertSame('fahrzeug', $w['objekt_typ']);
        $this->assertSame($fz, (int) $w['objekt_id']);
        $this->assertSame($pid, (int) $w['ausgefuehrt_von_person_id']);
        $this->assertSame($uid, (int) $w['erfasst_von_benutzer_id']);
        $this->assertSame('Alles sauber', $w['notiz']);
        $this->assertSame(2, $this->zahl('SELECT COUNT(*) FROM wartung_art WHERE wartung_id = ' . (int) $w['id']));
        $this->assertSame('einsatzbereit', $this->pdo->query("SELECT status FROM fahrzeug WHERE id = $fz")->fetchColumn());

        // Protokoll am Fahrzeug, Verlauf und Formular auf der Fahrzeugseite.
        $eintrag = json_decode((string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
        $this->assertSame(['alt' => null, 'neu' => date('d.m.Y') . ': Räder und Radschleifer reinigen, Kupplungen prüfen'], $eintrag['wartung']);
        $seite = $this->get("/fahrzeuge/$fz")->body;
        $this->assertStringContainsString('id="tab-wartung"', $seite);
        $this->assertLessThan(strpos($seite, 'id="tab-verlauf"'), strpos($seite, 'id="tab-wartung"'));
        $this->assertGreaterThan(strpos($seite, 'id="tab-dateien"'), strpos($seite, 'id="tab-wartung"'));
        $this->assertStringContainsString('Wartung eintragen', $seite);
        $this->assertStringContainsString('Stundenstand zum Zeitpunkt der Arbeit eintragen', $seite);
        $this->assertStringContainsString('Max Muster', $seite);
        $this->assertStringContainsString('Alles sauber', $seite);
        $this->assertStringContainsString(e("/fahrzeuge/$fz/wartungen"), $seite);
    }

    public function test_mitglied_an_fremdem_fahrzeug_403(): void
    {
        $andere = $this->legeBenutzerAn('andere@verein.de');
        [, $pid] = $this->anmelden('mitglied');
        $fz = $this->fahrzeug('lok', null, $andere);
        $this->assertSame(403, $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid))->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartung'));
        $seite = $this->get("/fahrzeuge/$fz")->body;
        $this->assertStringNotContainsString(e("/fahrzeuge/$fz/wartungen"), $seite);
        $this->assertStringNotContainsString(e("/fahrzeuge/$fz/wartungsplan"), $seite);
        $this->assertSame(404, $this->post('/fahrzeuge/999999/wartungen', $this->eintrag($pid))->status);
    }

    public function test_fahrzeugwart_an_anlage_403(): void
    {
        [, $pid] = $this->anmelden('fahrzeugwart');
        $anlage = $this->anlage();
        $this->assertSame(403, $this->post("/anlagen/$anlage/wartungen", $this->eintrag($pid, ['arten' => [(string) $this->art('Gleise reinigen')]]))->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartung'));
        // Fremdes Privatfahrzeug darf der Fahrzeugwart warten.
        $fz = $this->fahrzeug('wagen', $this->zahl("SELECT person_id FROM benutzer WHERE id = " . $this->legeBenutzerAn('x@verein.de')));
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid))->status);
    }

    public function test_bearbeiter_an_anlage_erlaubt(): void
    {
        [, $pid] = $this->anmelden('bearbeiter');
        $anlage = $this->anlage();
        $r = $this->post("/anlagen/$anlage/wartungen", $this->eintrag($pid, ['arten' => [(string) $this->art('Gleise reinigen')], 'betriebsstunden' => '99']));
        $this->assertSame(303, $r->status);
        $this->assertSame("/anlagen/$anlage#wartung", $r->headers['Location']);
        $w = $this->letzteWartung();
        $this->assertSame('anlage', $w['objekt_typ']);
        $this->assertNull($w['betriebsstunden']);
        $this->assertNull($w['stundenstand_bezug']);
        $this->assertSame('in_ordnung', $this->pdo->query("SELECT status FROM anlage WHERE id = $anlage")->fetchColumn());
        $seite = $this->get("/anlagen/$anlage")->body;
        $this->assertStringContainsString('Wartung eintragen', $seite);
        $this->assertStringContainsString('Gleise reinigen', $seite);
        $this->assertStringNotContainsString('name="betriebsstunden"', $seite);
    }

    public function test_hoeherer_stand_aktualisiert_fahrzeug(): void
    {
        [, $pid] = $this->anmelden('fahrzeugwart');
        $fz = $this->fahrzeug('lok', null, null, '40.0');
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid, ['betriebsstunden' => '52,5']))->status);
        $this->assertSame('52.5', $this->pdo->query("SELECT betriebsstunden FROM fahrzeug WHERE id = $fz")->fetchColumn());
        $w = $this->letzteWartung();
        $this->assertSame('52.5', $w['betriebsstunden']);
        $this->assertSame('52.5', $w['stundenstand_bezug']);
        $eintrag = json_decode((string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
        $this->assertSame(['alt' => '40,0', 'neu' => '52,5'], $eintrag['betriebsstunden']);
    }

    public function test_niedrigerer_stand_nachtrag_aendert_fahrzeug_nicht(): void
    {
        [, $pid] = $this->anmelden('fahrzeugwart');
        $fz = $this->fahrzeug('lok', null, null, '40.0');
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid, ['betriebsstunden' => '30', 'datum' => date('Y-m-d', strtotime('-3 months'))]))->status);
        $this->assertSame('40.0', $this->pdo->query("SELECT betriebsstunden FROM fahrzeug WHERE id = $fz")->fetchColumn());
        $w = $this->letzteWartung();
        $this->assertSame('30.0', $w['stundenstand_bezug']);
        $this->assertSame('30.0', $w['betriebsstunden']);
        $eintrag = json_decode((string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
        $this->assertArrayNotHasKey('betriebsstunden', $eintrag);
    }

    public function test_ohne_stand_bezug_ist_aktueller_stand(): void
    {
        [, $pid] = $this->anmelden('fahrzeugwart');
        $fz = $this->fahrzeug('lok', null, null, '40.0');
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid))->status);
        $w = $this->letzteWartung();
        $this->assertNull($w['betriebsstunden']);
        $this->assertSame('40.0', $w['stundenstand_bezug']);
    }

    public function test_nachtrag_ohne_stand_hat_keinen_stundenbezug(): void
    {
        [, $pid] = $this->anmelden('fahrzeugwart');
        $fz = $this->fahrzeug('lok', null, null, '40.0');
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid, ['datum' => date('Y-m-d', strtotime('-1 day'))]))->status);
        $w = $this->letzteWartung();
        $this->assertNull($w['betriebsstunden']);
        $this->assertNull($w['stundenstand_bezug']); // damaliger Stand unbekannt
        $this->assertSame('40.0', $this->pdo->query("SELECT betriebsstunden FROM fahrzeug WHERE id = $fz")->fetchColumn());
    }

    public function test_fahrzeug_ohne_betriebsstunden_bekommt_keinen_bezug(): void
    {
        [, $pid] = $this->anmelden('fahrzeugwart');
        $wagen = $this->fahrzeug('wagen', null, null, '0.0');
        $this->assertSame(303, $this->post("/fahrzeuge/$wagen/wartungen", $this->eintrag($pid))->status);
        $this->assertNull($this->letzteWartung()['stundenstand_bezug']);
        // Hat das Fahrzeug schon einmal einen eingegebenen Stand (auch 0), zählt der aktuelle Stand als Bezug.
        $lok = $this->fahrzeug('lok', null, null, '0.0');
        $this->assertSame(303, $this->post("/fahrzeuge/$lok/wartungen", $this->eintrag($pid, ['betriebsstunden' => '0']))->status);
        $this->assertSame(303, $this->post("/fahrzeuge/$lok/wartungen", $this->eintrag($pid))->status);
        $this->assertSame('0.0', $this->letzteWartung()['stundenstand_bezug']);
        // Ohne Stunden zeigt der Wagen kein „bei … h“.
        $seite = $this->get("/fahrzeuge/$wagen")->body;
        $this->assertStringNotContainsString('bei 20,0 h', $seite);
    }

    public function test_unpassende_art_422(): void
    {
        [, $pid] = $this->anmelden('fahrzeugwart');
        $fz = $this->fahrzeug('wagen');
        $r = $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid, ['arten' => [(string) $this->art('Haftreifen prüfen/tauschen')], 'notiz' => 'Mein Text']));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Diese Wartungsart passt nicht zu diesem Objekt.', $r->body);
        $this->assertStringContainsString('Mein Text', $r->body);
        $this->assertStringContainsString('href="' . e("/fahrzeuge/$fz#wartung") . '"', $r->body); // „Zurück“ führt zum Tab Wartung
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartung'));
        $r = $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid, ['datum' => date('Y-m-d', strtotime('+1 day'))]));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Das Datum darf nicht in der Zukunft liegen.', $r->body);
        // Inaktive Arten sind nicht wählbar.
        $this->pdo->exec("UPDATE wartungsart SET aktiv = 0 WHERE name = 'Kupplungen prüfen'");
        $this->assertSame(422, $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid))->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartung'));
    }

    public function test_foto_an_wartung(): void
    {
        [, $pid] = $this->anmelden('fahrzeugwart');
        $fz = $this->fahrzeug();
        $r = $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid), ['fotos' => $this->upload('foto_gedreht.jpg', 'anleitung.pdf')]);
        $this->assertSame(303, $r->status);
        $w = $this->letzteWartung();
        $wid = (int) $w['id'];
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'wartung' AND objekt_id = $wid"));
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'fahrzeug'"));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertSame('anleitung.pdf: Nur Fotos können an eine Wartung angehängt werden.', $this->meldung('warnung'));
        $this->assertSame('Die Wartung wurde eingetragen.', $this->meldung('erfolg'));
        $dok = $this->zahl("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'wartung'");
        $seite = $this->get("/fahrzeuge/$fz")->body;
        $this->assertStringContainsString(e("/dateien/$dok/vorschau"), $seite);
        // Das Foto erscheint nicht unter den Dateien des Fahrzeugs.
        $this->assertSame([], (new \Emc\Dateien\DokumentRepository($this->pdo, $this->speicher))->fuerObjekt('fahrzeug', $fz));
    }

    public function test_einzelnes_wartungsfoto_loeschen(): void
    {
        [, $pid] = $this->anmelden('mitglied');
        $fz = $this->fahrzeug('lok', $pid);
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid), ['fotos' => $this->upload('foto_gedreht.jpg')])->status);
        $dok = $this->zahl("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'wartung'");
        $titel = (string) $this->pdo->query("SELECT titel FROM dokument WHERE id = $dok")->fetchColumn();
        $pfad = (string) $this->pdo->query("SELECT pfad FROM dokument WHERE id = $dok")->fetchColumn();
        $loeschUrl = e("/dateien/$dok/loeschen");
        $this->assertStringContainsString($loeschUrl, $this->get("/fahrzeuge/$fz")->body);

        // Fremdes Mitglied: kein Link, Löschen verboten.
        $this->anmelden('mitglied', 'fremd@verein.de');
        $this->assertStringNotContainsString($loeschUrl, $this->get("/fahrzeuge/$fz")->body);
        $this->assertSame(403, $this->post("/dateien/$dok/loeschen")->status);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM dokument'));

        // Eigentümer: gleiches Recht wie beim Löschen der Wartung.
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, (int) $this->pdo->query("SELECT id FROM benutzer WHERE email = 'mitglied@verein.de'")->fetchColumn());
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        $r = $this->post("/dateien/$dok/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/$fz#wartung", $r->headers['Location']);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertFileDoesNotExist($this->speicher . '/' . $pfad);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM wartung'));
        $eintrag = json_decode((string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
        $this->assertSame(['alt' => $titel . ' (Wartung vom ' . date('d.m.Y') . ')', 'neu' => null], $eintrag['datei']);
    }

    public function test_ausnahmen(): void
    {
        $fz = $this->fahrzeug('lok');
        $raeder = $this->art('Räder und Radschleifer reinigen');
        $kupplung = $this->art('Kupplungen prüfen');

        [, $pid] = $this->anmelden('mitglied', 'm@verein.de');
        $this->assertSame(403, $this->post("/fahrzeuge/$fz/wartungsplan", ['modus' => [$raeder => 'aus']])->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartungsplan_ausnahme'));

        [, $pid] = $this->anmelden('fahrzeugwart', 'w@verein.de');
        $r = $this->post("/fahrzeuge/$fz/wartungsplan", ['modus' => [$raeder => 'aus', $kupplung => 'standard']]);
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/$fz#wartung", $r->headers['Location']);
        $this->assertSame('Der Wartungsplan wurde gespeichert.', $this->meldung('erfolg'));
        $this->assertSame(1, $this->zahl("SELECT aus FROM wartungsplan_ausnahme WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz AND wartungsart_id = $raeder"));
        $seite = $this->get("/fahrzeuge/$fz")->body;
        $this->assertStringNotContainsString('name="arten[]" value="' . $raeder . '"', $seite);
        $this->assertStringContainsString('name="arten[]" value="' . $kupplung . '"', $seite);
        // Abgeschaltete Art lässt sich auch nicht per POST eintragen.
        $this->assertSame(422, $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid, ['arten' => [(string) $raeder]]))->status);

        $r = $this->post("/fahrzeuge/$fz/wartungsplan", ['modus' => [$raeder => 'eigen'], 'monate' => [$raeder => ''], 'stunden' => [$raeder => '']]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte ein eigenes Intervall in Monaten oder Stunden angeben.', $r->body);
        $this->assertSame(1, $this->zahl("SELECT aus FROM wartungsplan_ausnahme WHERE wartungsart_id = $raeder"));

        $this->assertSame(303, $this->post("/fahrzeuge/$fz/wartungsplan", ['modus' => [$raeder => 'eigen'], 'monate' => [$raeder => '3'], 'stunden' => [$raeder => '']])->status);
        $this->assertSame(
            ['fahrzeug:' . $fz . ':' . $raeder => ['aus' => false, 'intervall_monate' => 3, 'intervall_stunden' => null]],
            (new AusnahmeRepository($this->pdo))->alle()
        );
        $this->assertSame([$raeder => ['aus' => false, 'intervall_monate' => 3, 'intervall_stunden' => null]], (new AusnahmeRepository($this->pdo))->fuerObjekt('fahrzeug', $fz));
        $this->assertStringContainsString('name="arten[]" value="' . $raeder . '"', $this->get("/fahrzeuge/$fz")->body);

        $this->assertSame(303, $this->post("/fahrzeuge/$fz/wartungsplan", ['modus' => [$raeder => 'standard']])->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartungsplan_ausnahme'));

        // Anlage: Bearbeiter erlaubt, Fahrzeugwart 403.
        $anlage = $this->anlage();
        $gleise = $this->art('Gleise reinigen');
        $this->assertSame(403, $this->post("/anlagen/$anlage/wartungsplan", ['modus' => [$gleise => 'aus']])->status);
        $this->anmelden('bearbeiter', 'b@verein.de');
        $this->assertSame(303, $this->post("/anlagen/$anlage/wartungsplan", ['modus' => [$gleise => 'eigen'], 'monate' => [$gleise => '1']])->status);
        $this->assertSame(1, $this->zahl("SELECT intervall_monate FROM wartungsplan_ausnahme WHERE objekt_typ = 'anlage' AND objekt_id = $anlage AND wartungsart_id = $gleise"));
        $this->assertStringContainsString('Wartungsplan anpassen', $this->get("/anlagen/$anlage")->body);
    }

    public function test_fahrzeug_loeschen_entfernt_wartungen_ausnahmen_fotos(): void
    {
        [, $pid] = $this->anmelden('fahrzeugwart');
        $fz = $this->fahrzeug();
        $anderes = $this->fahrzeug();
        $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid), ['fotos' => $this->upload('foto_gedreht.jpg')]);
        $this->post("/fahrzeuge/$anderes/wartungen", $this->eintrag($pid));
        $this->post("/fahrzeuge/$fz/wartungsplan", ['modus' => [$this->art('Kupplungen prüfen') => 'aus']]);
        $pfad = (string) $this->pdo->query('SELECT pfad FROM dokument LIMIT 1')->fetchColumn();
        $this->assertFileExists($this->speicher . '/' . $pfad);

        $this->assertSame(303, $this->post("/fahrzeuge/$fz/loeschen")->status);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM wartung'));
        $this->assertSame($anderes, $this->zahl('SELECT objekt_id FROM wartung'));
        $this->assertSame(2, $this->zahl('SELECT COUNT(*) FROM wartung_art'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartungsplan_ausnahme'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument_zuordnung'));
        $this->assertFileDoesNotExist($this->speicher . '/' . $pfad);
    }

    public function test_anlage_loeschen_entfernt_wartungen_und_ausnahmen(): void
    {
        [, $pid] = $this->anmelden('bearbeiter');
        $anlage = $this->anlage();
        $gleise = $this->art('Gleise reinigen');
        $this->post("/anlagen/$anlage/wartungen", $this->eintrag($pid, ['arten' => [(string) $gleise]]), ['fotos' => $this->upload('foto_gedreht.jpg')]);
        $this->post("/anlagen/$anlage/wartungsplan", ['modus' => [$gleise => 'aus']]);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM wartung'));
        $this->assertSame(303, $this->post("/anlagen/$anlage/loeschen")->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartung'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartung_art'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartungsplan_ausnahme'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));
    }

    public function test_wartung_loeschen(): void
    {
        $andere = $this->legeBenutzerAn('andere@verein.de');
        [, $pid] = $this->anmelden('mitglied');
        $eigenes = $this->fahrzeug('lok', $pid);
        $fremdes = $this->fahrzeug('lok', null, $andere);
        $this->assertSame(303, $this->post("/fahrzeuge/$eigenes/wartungen", $this->eintrag($pid, ['betriebsstunden' => '50']), ['fotos' => $this->upload('foto_gedreht.jpg')])->status);
        $eigeneWartung = (int) $this->letzteWartung()['id'];
        $fremdeWartung = (new WartungRepository($this->pdo))->eintragen('fahrzeug', $fremdes, ['datum' => date('Y-m-d'), 'betriebsstunden' => null, 'stundenstand_bezug' => 40.0, 'ausgefuehrt_von_person_id' => null, 'notiz' => null], [$this->art('Kupplungen prüfen')], null);

        $this->assertSame(403, $this->post("/wartungen/$fremdeWartung/loeschen")->status);
        $this->assertSame(404, $this->post('/wartungen/999999/loeschen')->status);
        $r = $this->post("/wartungen/$eigeneWartung/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame("/fahrzeuge/$eigenes#wartung", $r->headers['Location']);
        $this->assertSame('Die Wartung wurde gelöscht.', $this->meldung('erfolg'));
        $this->assertSame([$fremdeWartung], array_map('intval', $this->pdo->query('SELECT id FROM wartung')->fetchAll(\PDO::FETCH_COLUMN)));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM wartung_art'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertSame('50.0', $this->pdo->query("SELECT betriebsstunden FROM fahrzeug WHERE id = $eigenes")->fetchColumn());
        $eintrag = json_decode((string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $eigenes ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
        $this->assertSame(['alt' => date('d.m.Y') . ': Räder und Radschleifer reinigen, Kupplungen prüfen', 'neu' => null], $eintrag['wartung']);

        // Anlagenwartung: nur ab Bearbeiter.
        $anlage = $this->anlage();
        $aw = (new WartungRepository($this->pdo))->eintragen('anlage', $anlage, ['datum' => date('Y-m-d'), 'betriebsstunden' => null, 'stundenstand_bezug' => null, 'ausgefuehrt_von_person_id' => null, 'notiz' => null], [$this->art('Gleise reinigen')], null);
        $this->anmelden('fahrzeugwart', 'w@verein.de');
        $this->assertSame(403, $this->post("/wartungen/$aw/loeschen")->status);
        $this->assertSame(303, $this->post("/wartungen/$fremdeWartung/loeschen")->status);
        $this->anmelden('bearbeiter', 'b@verein.de');
        $this->assertSame(303, $this->post("/wartungen/$aw/loeschen")->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartung'));
    }

    public function test_verlauf_zeigt_ehemaliges_mitglied(): void
    {
        $this->anmelden('mitglied');
        $fz = $this->fahrzeug();
        $repo = new WartungRepository($this->pdo);
        $repo->eintragen('fahrzeug', $fz, ['datum' => '2026-03-01', 'betriebsstunden' => '12.5', 'stundenstand_bezug' => 12.5, 'ausgefuehrt_von_person_id' => null, 'notiz' => 'Nachtrag <b>'], [$this->art('Kupplungen prüfen')], null);
        $seite = $this->get("/fahrzeuge/$fz")->body;
        $this->assertStringContainsString('ehemaliges Mitglied', $seite);
        $this->assertStringContainsString('01.03.2026', $seite);
        $this->assertStringContainsString('12,5 h', $seite);
        $this->assertStringContainsString('Nachtrag &lt;b&gt;', $seite);
        $liste = $repo->fuerObjekt('fahrzeug', $fz);
        $this->assertCount(1, $liste);
        $this->assertNull($liste[0]['ausgefuehrt_von']);
        $this->assertSame(['Kupplungen prüfen'], $liste[0]['arten']);
        $this->assertSame('12.5', $liste[0]['betriebsstunden']);
    }

    public function test_verwendete_wartungsart_nicht_loeschbar(): void
    {
        [, $pid] = $this->anmelden('fahrzeugwart');
        $fz = $this->fahrzeug();
        $this->post("/fahrzeuge/$fz/wartungen", $this->eintrag($pid));
        $kupplung = $this->art('Kupplungen prüfen');
        $r = $this->post("/admin/wartungsarten/$kupplung/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame('Diese Wartungsart wurde schon verwendet. Bitte stattdessen deaktivieren.', $this->meldung('fehler'));
        $this->assertSame($kupplung, $this->art('Kupplungen prüfen'));
        // Eine nur in Ausnahmen verwendete Art lässt sich löschen (die Ausnahme verschwindet mit).
        $gleise = $this->art('Gleise reinigen');
        (new AusnahmeRepository($this->pdo))->speichere('anlage', $this->anlage(), $gleise, 'aus', null, null);
        $this->assertSame(303, $this->post("/admin/wartungsarten/$gleise/loeschen")->status);
        $this->assertSame(0, $this->art('Gleise reinigen'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM wartungsplan_ausnahme'));
    }

    public function test_letzte_je_art_juengstes_datum(): void
    {
        $fz = $this->fahrzeug();
        $raeder = $this->art('Räder und Radschleifer reinigen');
        $kupplung = $this->art('Kupplungen prüfen');
        $repo = new WartungRepository($this->pdo);
        $d = fn(string $datum, ?float $stand) => ['datum' => $datum, 'betriebsstunden' => null, 'stundenstand_bezug' => $stand, 'ausgefuehrt_von_person_id' => null, 'notiz' => null];
        $repo->eintragen('fahrzeug', $fz, $d('2026-09-01', 60.0), [$raeder, $kupplung], null);
        $repo->eintragen('fahrzeug', $fz, $d('2026-03-01', 30.0), [$raeder], null); // Nachtrag älter
        $repo->eintragen('fahrzeug', $fz, $d('2026-09-01', 61.0), [$kupplung], null); // gleiches Datum, höhere ID
        $letzte = $repo->letzteJeArt('fahrzeug', $fz);
        $this->assertSame(['datum' => '2026-09-01', 'stundenstand' => 60.0], $letzte["fahrzeug:$fz:$raeder"]);
        $this->assertSame(['datum' => '2026-09-01', 'stundenstand' => 61.0], $letzte["fahrzeug:$fz:$kupplung"]);
        $this->assertCount(2, $letzte);
        $this->assertCount(2, $repo->letzteJeArt('fahrzeug'));
        $this->assertSame([], $repo->letzteJeArt('anlage'));
        $this->assertCount(3, $repo->idsFuerObjekt('fahrzeug', $fz));
        $liste = $repo->fuerObjekt('fahrzeug', $fz);
        $this->assertSame(['2026-09-01', '2026-09-01', '2026-03-01'], array_column($liste, 'datum'));
    }
}
