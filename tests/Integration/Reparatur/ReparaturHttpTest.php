<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Reparatur;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\UploadService;
use Emc\Reparatur\ReparaturRepository;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;

/** Reparatur bearbeiten (Ersatzteile, Kosten, Status, Verwerfen), Seite „Reparaturen“ und Reparaturblock. */
final class ReparaturHttpTest extends DbTestCase
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

    private function app(array $extra = []): App
    {
        $app = App::erzeuge(['speicher_pfad' => $this->speicher] + $extra + self::testConfig(), new Session($this->sitzung));
        $app->container()->set(UploadService::class, fn() => new UploadService(
            $this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b)
        ));
        return $app;
    }

    /** @return int Benutzer-ID */
    private function anmelden(string $rolle, string $email = ''): int
    {
        $email = $email !== '' ? $email : $rolle . '@verein.de';
        $st = $this->pdo->prepare('SELECT id FROM benutzer WHERE email = ?');
        $st->execute([$email]);
        $id = (int) ($st->fetchColumn() ?: $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle));
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        return $id;
    }

    private function personVon(int $benutzerId): int
    {
        return (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = $benutzerId")->fetchColumn();
    }

    private function person(string $vorname, string $nachname, bool $aktiv = true): int
    {
        $this->pdo->prepare('INSERT INTO person (vorname, nachname, aktiv) VALUES (?, ?, ?)')->execute([$vorname, $nachname, $aktiv ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function get(string $pfad, array $query = []): Response
    {
        return $this->app()->handle(new Request('GET', $pfad, $query));
    }

    private function post(string $pfad, array $daten = [], array $dateien = []): Response
    {
        return $this->app()->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)], $dateien));
    }

    private function fahrzeug(string $nummer = 'EMC-0012', string $bezeichnung = 'V 200', ?int $eigentuemer = null): int
    {
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, gattung, spurweite_id, eigentuemer_person_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$nummer, $bezeichnung, 'lok', $this->spurweite, $eigentuemer]);
        return (int) $this->pdo->lastInsertId();
    }

    private function anlage(int $nummer = 3, string $name = 'Clubanlage H0'): int
    {
        $this->pdo->prepare('INSERT INTO anlage (nummer, name, spurweite_id) VALUES (?, ?, ?)')->execute([$nummer, $name, $this->spurweite]);
        return (int) $this->pdo->lastInsertId();
    }

    private function reparatur(string $typ, int $objektId, string $beschreibung = 'Motor brummt', string $prioritaet = 'normal', ?int $benutzerId = null, string $gemeldetAm = ''): int
    {
        $id = (new ReparaturRepository($this->pdo))->anlegen($typ, $objektId, ['beschreibung' => $beschreibung, 'prioritaet' => $prioritaet], $benutzerId);
        if ($gemeldetAm !== '') {
            $this->pdo->prepare('UPDATE reparatur SET gemeldet_am = ? WHERE id = ?')->execute([$gemeldetAm, $id]);
        }
        return $id;
    }

    private function stand(int $id): string
    {
        return (string) $this->wert("SELECT geaendert_am FROM reparatur WHERE id = $id");
    }

    /** @return array<string,mixed> vollständiges Formular wie im Browser abgeschickt */
    private function formular(int $id, array $mehr = []): array
    {
        $r = $this->pdo->query("SELECT * FROM reparatur WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        return $mehr + [
            'stand' => $this->stand($id),
            'beschreibung' => $r['beschreibung'],
            'wo_bauteil' => (string) $r['wo_bauteil'],
            'prioritaet' => $r['prioritaet'],
            'status' => $r['status'],
            'zustaendig' => (string) $r['zustaendig_person_id'],
            'termin' => (string) $r['termin'],
            'notizen' => (string) $r['notizen'],
            'was_gemacht' => (string) $r['was_gemacht'],
            'teile' => [['bezeichnung' => '', 'artikelnummer' => '', 'bezugsquelle' => '', 'kosten' => '']],
        ];
    }

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function wert(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }

    /** @return ?array{typ:string,text:string} */
    private function meldung(string $typ): ?array
    {
        foreach ($this->sitzung['_meldungen'] ?? [] as $m) {
            if (($m['typ'] ?? null) === $typ) {
                return $m;
            }
        }
        return null;
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

    public function test_fahrzeugwart_plant_und_erledigt(): void
    {
        $fz = $this->fahrzeug();
        $rid = $this->reparatur('fahrzeug', $fz);
        $werkstatt = $this->person('Erika', 'Beispiel');
        $this->anmelden('fahrzeugwart');

        $formular = $this->get("/reparaturen/$rid/bearbeiten");
        $this->assertSame(200, $formular->status);
        $this->assertStringContainsString('name="stand" value="' . $this->stand($rid) . '"', $formular->body);
        $this->assertStringContainsString('name="teile[0][bezeichnung]"', $formular->body);
        $this->assertStringContainsString('Zeile hinzufügen', $formular->body);

        $r = $this->post("/reparaturen/$rid", $this->formular($rid, [
            'status' => 'geplant',
            'zustaendig' => (string) $werkstatt,
            'termin' => '2026-11-03',
            'teile' => [
                ['bezeichnung' => 'Haftreifen', 'artikelnummer' => '12345', 'bezugsquelle' => 'Fachhandel', 'kosten' => '12,50', 'bestellt' => '1'],
                ['bezeichnung' => 'Schleifer', 'artikelnummer' => '', 'bezugsquelle' => '', 'kosten' => '7.25'],
                ['bezeichnung' => '', 'artikelnummer' => '', 'bezugsquelle' => '', 'kosten' => ''],
            ],
        ]));
        $this->assertSame(303, $r->status);
        $this->assertSame("/reparaturen/$rid", $r->headers['Location']);
        $this->assertSame('Die Reparatur wurde gespeichert.', $this->meldung('erfolg')['text']);
        $this->assertSame('19.75', (string) $this->wert("SELECT SUM(kosten) FROM ersatzteil WHERE reparatur_id = $rid"));
        $this->assertSame(2, $this->zahl("SELECT COUNT(*) FROM ersatzteil WHERE reparatur_id = $rid"));
        $this->assertSame('einsatzbereit', $this->wert("SELECT status FROM fahrzeug WHERE id = $fz"));

        $detail = $this->get("/reparaturen/$rid");
        $this->assertSame(200, $detail->status);
        $b = $detail->body;
        $this->assertStringContainsString('19,75 €', $b);
        $this->assertStringContainsString('12,50 €', $b);
        $this->assertStringContainsString('7,25 €', $b);
        $this->assertStringContainsString('Haftreifen', $b);
        $this->assertStringContainsString('Fachhandel', $b);
        $this->assertStringContainsString('Erika Beispiel', $b);
        $this->assertStringContainsString('03.11.2026', $b);
        $this->assertStringContainsString('>geplant</span>', $b);
        $this->assertStringContainsString('F12', $b);
        $this->assertStringContainsString('href="/fahrzeuge/' . $fz . '"', $b);
        $this->assertStringContainsString('href="/reparaturen/' . $rid . '/bearbeiten"', $b);

        // In Arbeit → Fahrzeug „in Wartung“ (automatisch).
        $this->assertSame(303, $this->post("/reparaturen/$rid", $this->formular($rid, ['status' => 'in_arbeit']))->status);
        $this->assertSame('in_wartung', $this->wert("SELECT status FROM fahrzeug WHERE id = $fz"));
        $this->assertSame(1, $this->zahl("SELECT status_automatisch FROM fahrzeug WHERE id = $fz"));
        // Das Formular ohne Ersatzteilzeilen hat die Ersatzteile ersetzt – also zuerst wieder eintragen.
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM ersatzteil WHERE reparatur_id = $rid"));

        // Erledigt ohne „Was wurde gemacht“ → Fehler, nichts gespeichert.
        $fehler = $this->post("/reparaturen/$rid", $this->formular($rid, ['status' => 'erledigt']));
        $this->assertSame(422, $fehler->status);
        $this->assertStringContainsString('Bitte kurz beschreiben, was gemacht wurde.', $fehler->body);
        $this->assertSame('in_arbeit', $this->wert("SELECT status FROM reparatur WHERE id = $rid"));

        $this->assertSame(303, $this->post("/reparaturen/$rid", $this->formular($rid, ['status' => 'erledigt', 'was_gemacht' => 'Haftreifen getauscht']))->status);
        $this->assertNotNull($this->wert("SELECT abgeschlossen_am FROM reparatur WHERE id = $rid"));
        $this->assertSame('einsatzbereit', $this->wert("SELECT status FROM fahrzeug WHERE id = $fz"));
        $this->assertSame(0, $this->zahl("SELECT status_automatisch FROM fahrzeug WHERE id = $fz"));
        $this->assertStringContainsString('Haftreifen getauscht', $this->get("/reparaturen/$rid")->body);
    }

    public function test_formular_zeigt_vorhandene_ersatzteile_und_kosten_mit_komma(): void
    {
        $fz = $this->fahrzeug();
        $rid = $this->reparatur('fahrzeug', $fz);
        $this->anmelden('fahrzeugwart');
        $this->post("/reparaturen/$rid", $this->formular($rid, ['teile' => [['bezeichnung' => 'Haftreifen', 'kosten' => '4,9', 'bestellt' => '1']]]));
        $b = $this->get("/reparaturen/$rid/bearbeiten")->body;
        $this->assertStringContainsString('name="teile[0][bezeichnung]" value="Haftreifen"', $b);
        $this->assertStringContainsString('name="teile[0][kosten]" value="4,90"', $b);
        $this->assertMatchesRegularExpression('/name="teile\[0\]\[bestellt\]" value="1"[^>]*checked/', $b);
        // Plus eine leere Zeile.
        $this->assertStringContainsString('name="teile[1][bezeichnung]" value=""', $b);
    }

    public function test_dringend_setzt_defekt_und_herabstufen_setzt_zurueck(): void
    {
        $fz = $this->fahrzeug();
        $rid = $this->reparatur('fahrzeug', $fz);
        $this->anmelden('fahrzeugwart');
        $this->post("/reparaturen/$rid", $this->formular($rid, ['prioritaet' => 'dringend']));
        $this->assertSame('defekt', $this->wert("SELECT status FROM fahrzeug WHERE id = $fz"));
        $this->post("/reparaturen/$rid", $this->formular($rid, ['prioritaet' => 'niedrig']));
        $this->assertSame('einsatzbereit', $this->wert("SELECT status FROM fahrzeug WHERE id = $fz"));
    }

    public function test_mitglied_an_eigenem_fahrzeug_erlaubt_fremd_403(): void
    {
        $mitglied = $this->anmelden('mitglied');
        $eigenes = $this->fahrzeug('EMC-0001', 'Eigene Lok', $this->personVon($mitglied));
        $fremdes = $this->fahrzeug('EMC-0002', 'Vereinslok');
        $eigeneRep = $this->reparatur('fahrzeug', $eigenes);
        $fremdeRep = $this->reparatur('fahrzeug', $fremdes, 'Fremder Defekt');

        $this->assertSame(200, $this->get("/reparaturen/$eigeneRep/bearbeiten")->status);
        $this->assertSame(303, $this->post("/reparaturen/$eigeneRep", $this->formular($eigeneRep, ['status' => 'geplant']))->status);
        $this->assertSame('geplant', $this->wert("SELECT status FROM reparatur WHERE id = $eigeneRep"));

        $this->assertSame(403, $this->get("/reparaturen/$fremdeRep/bearbeiten")->status);
        $this->assertSame(403, $this->post("/reparaturen/$fremdeRep", $this->formular($fremdeRep, ['status' => 'verworfen']))->status);
        $this->assertSame(403, $this->post("/reparaturen/$fremdeRep/fotos", [], ['fotos' => $this->upload('foto_gedreht.jpg')])->status);
        $this->assertSame('gemeldet', $this->wert("SELECT status FROM reparatur WHERE id = $fremdeRep"));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));

        // Lesen dürfen alle; ohne Recht keine Schaltfläche „Bearbeiten“.
        $detail = $this->get("/reparaturen/$fremdeRep");
        $this->assertSame(200, $detail->status);
        $this->assertStringContainsString('Fremder Defekt', $detail->body);
        $this->assertStringNotContainsString("/reparaturen/$fremdeRep/bearbeiten", $detail->body);
        $this->assertStringNotContainsString("/reparaturen/$fremdeRep/fotos", $detail->body);
        $this->assertStringContainsString("/reparaturen/$eigeneRep/bearbeiten", $this->get("/reparaturen/$eigeneRep")->body);
    }

    public function test_fahrzeugwart_an_anlage_403_bearbeiter_erlaubt(): void
    {
        $anl = $this->anlage();
        $rid = $this->reparatur('anlage', $anl, 'Weiche schaltet nicht');
        $this->anmelden('fahrzeugwart');
        $this->assertSame(403, $this->get("/reparaturen/$rid/bearbeiten")->status);
        $this->assertSame(403, $this->post("/reparaturen/$rid", $this->formular($rid, ['status' => 'in_arbeit']))->status);

        $this->anmelden('bearbeiter');
        $formular = $this->get("/reparaturen/$rid/bearbeiten");
        $this->assertSame(200, $formular->status);
        $this->assertStringContainsString('name="wo_bauteil"', $formular->body);
        $this->assertSame(303, $this->post("/reparaturen/$rid", $this->formular($rid, ['status' => 'in_arbeit', 'wo_bauteil' => 'W12 Antrieb']))->status);
        $this->assertSame('W12 Antrieb', $this->wert("SELECT wo_bauteil FROM reparatur WHERE id = $rid"));
        $this->assertSame('in_arbeit', $this->wert("SELECT status FROM anlage WHERE id = $anl"));
    }

    public function test_wo_bauteil_bei_fahrzeugen_nicht_im_formular(): void
    {
        $rid = $this->reparatur('fahrzeug', $this->fahrzeug());
        $this->anmelden('fahrzeugwart');
        $this->assertStringNotContainsString('name="wo_bauteil"', $this->get("/reparaturen/$rid/bearbeiten")->body);
    }

    public function test_verwerfen_statt_loeschen(): void
    {
        $fz = $this->fahrzeug();
        $rid = $this->reparatur('fahrzeug', $fz, 'Doppelt gemeldet');
        $this->anmelden('fahrzeugwart');
        $r = $this->post("/reparaturen/$rid", $this->formular($rid, ['status' => 'verworfen']));
        $this->assertSame(303, $r->status);
        $this->assertSame('Die Reparatur wurde verworfen.', $this->meldung('erfolg')['text']);
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM reparatur WHERE id = $rid"));
        $this->assertSame('verworfen', $this->wert("SELECT status FROM reparatur WHERE id = $rid"));
        $this->assertNotNull($this->wert("SELECT abgeschlossen_am FROM reparatur WHERE id = $rid"));

        $this->assertStringNotContainsString('Doppelt gemeldet', $this->get('/reparaturen')->body);
        $this->assertStringNotContainsString('Doppelt gemeldet', $this->get('/reparaturen', ['status' => 'offen'])->body);
        $this->assertStringContainsString('Doppelt gemeldet', $this->get('/reparaturen', ['status' => 'alle'])->body);
        $this->assertStringContainsString('Doppelt gemeldet', $this->get('/reparaturen', ['status' => 'verworfen'])->body);

        // Es gibt keine Löschroute und keine Schaltfläche.
        $this->assertSame(404, $this->post("/reparaturen/$rid/loeschen")->status);
        $this->assertStringNotContainsString('/loeschen"', str_replace('/dateien/', '', $this->get("/reparaturen/$rid")->body));
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM reparatur WHERE id = $rid"));

        // Erneutes Speichern einer verworfenen Reparatur meldet „gespeichert“.
        $this->post("/reparaturen/$rid", $this->formular($rid, ['notizen' => 'war doppelt']));
        $this->assertSame('Die Reparatur wurde gespeichert.', $this->meldung('erfolg')['text']);
    }

    public function test_gleichzeitige_bearbeitung(): void
    {
        $fz = $this->fahrzeug();
        $rid = $this->reparatur('fahrzeug', $fz);
        $this->anmelden('fahrzeugwart');
        $stand = $this->stand($rid);
        $erste = $this->formular($rid, [
            'stand' => $stand,
            'beschreibung' => 'Motor brummt laut',
            'teile' => [['bezeichnung' => 'Motor', 'kosten' => '30']],
        ]);
        $zweite = $this->formular($rid, [
            'stand' => $stand,
            'beschreibung' => 'Zweite Fassung',
            'prioritaet' => 'dringend',
            'teile' => [['bezeichnung' => 'Schleifer', 'kosten' => '2,00']],
        ]);
        $this->assertSame(303, $this->post("/reparaturen/$rid", $erste)->status);

        $r = $this->post("/reparaturen/$rid", $zweite);
        $this->assertSame(409, $r->status);
        $this->assertStringContainsString('Diese Reparatur wurde inzwischen von jemand anderem geändert.', $r->body);
        // Die Eingaben bleiben im Formular, gespeichert ist nichts davon.
        $this->assertStringContainsString('Zweite Fassung', $r->body);
        $this->assertStringContainsString('value="Schleifer"', $r->body);
        $this->assertStringContainsString('name="stand" value="' . $stand . '"', $r->body);
        $this->assertSame('Motor brummt laut', $this->wert("SELECT beschreibung FROM reparatur WHERE id = $rid"));
        $this->assertSame('normal', $this->wert("SELECT prioritaet FROM reparatur WHERE id = $rid"));
        $this->assertSame(['Motor'], $this->pdo->query("SELECT bezeichnung FROM ersatzteil WHERE reparatur_id = $rid")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame('30.00', (string) $this->wert("SELECT kosten FROM ersatzteil WHERE reparatur_id = $rid"));
        $this->assertSame('einsatzbereit', $this->wert("SELECT status FROM fahrzeug WHERE id = $fz"));
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'reparatur' AND objekt_id = $rid"));

        // Ohne oder mit unsinnigem Stand ebenfalls Konflikt.
        $this->assertSame(409, $this->post("/reparaturen/$rid", ['stand' => ''] + $zweite)->status);
        $this->assertSame(409, $this->post("/reparaturen/$rid", ['stand' => ['x']] + $zweite)->status);
    }

    public function test_protokoll_mit_ersatzteilen(): void
    {
        $rid = $this->reparatur('fahrzeug', $this->fahrzeug());
        $this->anmelden('fahrzeugwart');
        $this->post("/reparaturen/$rid", $this->formular($rid, [
            'status' => 'wartet_auf_teile',
            'teile' => [['bezeichnung' => 'Haftreifen', 'kosten' => '4,9'], ['bezeichnung' => 'Schleifer']],
        ]));
        $eintrag = json_decode((string) $this->wert("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'reparatur' AND objekt_id = $rid ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(['alt' => 'gemeldet', 'neu' => 'wartet_auf_teile'], $eintrag['status']);
        $this->assertSame(['alt' => null, 'neu' => 'Haftreifen (4,90 €); Schleifer'], $eintrag['ersatzteile']);
        $this->assertArrayNotHasKey('beschreibung', $eintrag);

        // Unverändert gespeichert → kein weiterer Eintrag.
        $vorher = $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'reparatur' AND objekt_id = $rid");
        $this->post("/reparaturen/$rid", $this->formular($rid, ['teile' => [['bezeichnung' => 'Haftreifen', 'kosten' => '4.90'], ['bezeichnung' => 'Schleifer']]]));
        $this->assertSame($vorher, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'reparatur' AND objekt_id = $rid"));
    }

    public function test_eingabefehler_422_behaelt_eingaben(): void
    {
        $rid = $this->reparatur('fahrzeug', $this->fahrzeug());
        $this->anmelden('fahrzeugwart');
        $r = $this->post("/reparaturen/$rid", $this->formular($rid, [
            'beschreibung' => 'Neue Beschreibung',
            'zustaendig' => '999999',
            'termin' => '32.13.2026',
            'teile' => [['bezeichnung' => 'Haftreifen', 'kosten' => '1.234,50'], ['bezeichnung' => '', 'kosten' => '3']],
        ]));
        $this->assertSame(422, $r->status);
        $b = $r->body;
        $this->assertStringContainsString('Bitte die Kosten als Betrag angeben, z. B. 12,50.', $b);
        $this->assertStringContainsString('Bitte eine Bezeichnung für das Ersatzteil angeben.', $b);
        $this->assertStringContainsString('Bitte ein gültiges Datum angeben.', $b);
        $this->assertStringContainsString('value="1.234,50"', $b);
        $this->assertStringContainsString('Neue Beschreibung', $b);
        $this->assertSame('Motor brummt', $this->wert("SELECT beschreibung FROM reparatur WHERE id = $rid"));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM ersatzteil'));
    }

    public function test_zustaendig_bleibt_waehlbar_auch_wenn_inaktiv(): void
    {
        $rid = $this->reparatur('fahrzeug', $this->fahrzeug());
        $alt = $this->person('Otto', 'Ruhestand');
        $this->anmelden('fahrzeugwart');
        $this->post("/reparaturen/$rid", $this->formular($rid, ['zustaendig' => (string) $alt]));
        $this->pdo->exec("UPDATE person SET aktiv = 0 WHERE id = $alt");
        // Die bisher zuständige Person verhindert das Speichern nicht.
        $this->assertSame(303, $this->post("/reparaturen/$rid", $this->formular($rid, ['notizen' => 'Teile bestellt']))->status);
        $this->assertSame($alt, $this->zahl("SELECT zustaendig_person_id FROM reparatur WHERE id = $rid"));
        // Eine andere inaktive Person ist nicht wählbar.
        $andere = $this->person('Ida', 'Inaktiv', false);
        $this->assertSame(422, $this->post("/reparaturen/$rid", $this->formular($rid, ['zustaendig' => (string) $andere]))->status);
    }

    public function test_liste_filter_und_sortierung(): void
    {
        $fz = $this->fahrzeug();
        $anl = $this->anlage();
        $erika = $this->person('Erika', 'Beispiel');
        $this->reparatur('fahrzeug', $fz, 'Normal alt', 'normal', null, '2026-01-01 10:00:00');
        $this->reparatur('fahrzeug', $fz, 'Niedrig neu', 'niedrig', null, '2026-03-01 10:00:00');
        $this->reparatur('fahrzeug', $fz, 'Dringend alt', 'dringend', null, '2026-01-02 10:00:00');
        $this->reparatur('fahrzeug', $fz, 'Normal neu', 'normal', null, '2026-02-01 10:00:00');
        $anlRep = $this->reparatur('anlage', $anl, 'Weiche klemmt', 'normal', null, '2026-01-05 10:00:00');
        $this->pdo->exec("UPDATE reparatur SET zustaendig_person_id = $erika WHERE id = $anlRep");
        $erledigt = $this->reparatur('fahrzeug', $fz, 'Längst erledigt');
        $this->pdo->exec("UPDATE reparatur SET status = 'erledigt' WHERE id = $erledigt");
        $this->pdo->exec("INSERT INTO ersatzteil (reparatur_id, bezeichnung, kosten) VALUES ($anlRep, 'Antrieb', 19.90), ($anlRep, 'Kabel', 0.10)");

        $this->anmelden('mitglied');
        $r = $this->get('/reparaturen');
        $this->assertSame(200, $r->status);
        $b = $r->body;
        $reihenfolge = ['Dringend alt', 'Normal neu', 'Weiche klemmt', 'Normal alt', 'Niedrig neu'];
        $positionen = array_map(fn(string $t) => strpos($b, $t), $reihenfolge);
        $this->assertNotContains(false, $positionen);
        $sortiert = $positionen;
        sort($sortiert);
        $this->assertSame($sortiert, $positionen, 'dringend vor normal vor niedrig, dann neueste Meldung zuerst');
        $this->assertStringNotContainsString('Längst erledigt', $b);
        $this->assertStringContainsString('F12', $b);
        $this->assertStringContainsString('A3', $b);
        $this->assertStringContainsString('20,00 €', $b);
        $this->assertStringContainsString('href="/reparaturen/' . $anlRep . '"', $b);
        $this->assertMatchesRegularExpression('/<option value="offen" selected>/', $b);

        $nurAnlagen = $this->get('/reparaturen', ['typ' => 'anlage'])->body;
        $this->assertStringContainsString('Weiche klemmt', $nurAnlagen);
        $this->assertStringNotContainsString('Normal neu', $nurAnlagen);
        $this->assertStringContainsString('Clubanlage H0', $nurAnlagen);

        $nurFahrzeuge = $this->get('/reparaturen', ['typ' => 'fahrzeug'])->body;
        $this->assertStringNotContainsString('Weiche klemmt', $nurFahrzeuge);
        $this->assertStringContainsString('Normal neu', $nurFahrzeuge);

        $zustaendig = $this->get('/reparaturen', ['zustaendig' => (string) $erika])->body;
        $this->assertStringContainsString('Weiche klemmt', $zustaendig);
        $this->assertStringNotContainsString('Normal neu', $zustaendig);

        $alle = $this->get('/reparaturen', ['status' => 'alle'])->body;
        $this->assertStringContainsString('Längst erledigt', $alle);
        $this->assertStringContainsString('Normal neu', $alle);

        $nurErledigt = $this->get('/reparaturen', ['status' => 'erledigt'])->body;
        $this->assertStringContainsString('Längst erledigt', $nurErledigt);
        $this->assertStringNotContainsString('Normal neu', $nurErledigt);

        // Unsinnige Filterwerte → Standard (offen, alle Objekte).
        $unsinn = $this->get('/reparaturen', ['status' => 'kaputt', 'typ' => 'lok', 'zustaendig' => 'x'])->body;
        $this->assertStringContainsString('Weiche klemmt', $unsinn);
        $this->assertStringNotContainsString('Längst erledigt', $unsinn);

        $leer = $this->get('/reparaturen', ['status' => 'wartet_auf_teile'])->body;
        $this->assertStringContainsString('Keine Reparaturen gefunden.', $leer);
    }

    public function test_ehemaliges_mitglied(): void
    {
        $fz = $this->fahrzeug();
        $melder = $this->legeBenutzerAn('melder@verein.de');
        $this->pdo->exec("UPDATE person SET vorname = 'Paula', nachname = 'Melder' WHERE id = " . $this->personVon($melder));
        $rid = $this->reparatur('fahrzeug', $fz, 'Motor brummt', 'normal', $melder);
        $this->anmelden('mitglied');
        $b = $this->get("/reparaturen/$rid")->body;
        $this->assertStringContainsString('Paula Melder', $b);
        $this->assertStringNotContainsString('ehemaliges Mitglied', $b);
        $this->pdo->exec("UPDATE reparatur SET gemeldet_von_benutzer_id = NULL WHERE id = $rid");
        $b = $this->get("/reparaturen/$rid")->body;
        $this->assertStringContainsString('ehemaliges Mitglied', $b);
        $this->assertStringNotContainsString('Paula Melder', $b);
        $this->assertStringContainsString(date('d.m.Y'), $b);
    }

    public function test_unbekannte_reparatur_404_und_login(): void
    {
        $r = $this->get('/reparaturen');
        $this->assertSame(303, $r->status);
        $this->assertSame('/login?ziel=' . rawurlencode('/reparaturen'), $r->headers['Location']);
        $this->anmelden('fahrzeugwart');
        foreach (['/reparaturen/999', '/reparaturen/abc', '/reparaturen/999/bearbeiten', '/reparaturen/99999999999'] as $pfad) {
            $this->assertSame(404, $this->get($pfad)->status, $pfad);
        }
        $this->assertSame(404, $this->post('/reparaturen/999', ['stand' => 'x'])->status);
        $this->assertSame(404, $this->post('/reparaturen/999/fotos')->status);
    }

    public function test_objekt_inzwischen_geloescht_404(): void
    {
        $fz = $this->fahrzeug();
        $rid = $this->reparatur('fahrzeug', $fz);
        $this->anmelden('admin');
        $formular = $this->formular($rid, ['status' => 'geplant']);
        // Objekt ohne Aufräumen weg (gleichzeitiges Löschen): Speichern legt nichts an und meldet 404.
        $this->pdo->exec("DELETE FROM fahrzeug WHERE id = $fz");
        $this->assertSame(404, $this->post("/reparaturen/$rid", $formular)->status);
        $this->assertSame('gemeldet', $this->wert("SELECT status FROM reparatur WHERE id = $rid"));
        $detail = $this->get("/reparaturen/$rid");
        $this->assertSame(200, $detail->status);
        $this->assertStringContainsString('gelöscht', $detail->body);
    }

    public function test_fotos_hochladen_und_loeschen(): void
    {
        $fz = $this->fahrzeug();
        $rid = $this->reparatur('fahrzeug', $fz);
        $this->anmelden('fahrzeugwart');
        $detail = $this->get("/reparaturen/$rid")->body;
        $this->assertStringContainsString('action="/reparaturen/' . $rid . '/fotos"', $detail);
        $this->assertStringContainsString('Noch keine Fotos.', $detail);

        $r = $this->post("/reparaturen/$rid/fotos", [], ['fotos' => $this->upload('foto_gedreht.jpg', 'anleitung.pdf')]);
        $this->assertSame(303, $r->status);
        $this->assertSame("/reparaturen/$rid", $r->headers['Location']);
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'reparatur' AND objekt_id = $rid"));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM dokument'));
        $this->assertSame('anleitung.pdf: Nur Fotos (JPEG, PNG oder WebP) können an eine Reparatur angehängt werden.', $this->meldung('warnung')['text']);
        $this->assertSame('Das Foto wurde hinzugefügt.', $this->meldung('erfolg')['text']);
        $eintrag = json_decode((string) $this->wert("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(['alt' => null, 'neu' => 'foto_gedreht.jpg (Reparatur vom ' . date('d.m.Y') . ')'], $eintrag['datei']);

        $dok = $this->zahl("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'reparatur'");
        $detail = $this->get("/reparaturen/$rid")->body;
        $this->assertStringContainsString('/dateien/' . $dok . '/vorschau', $detail);
        $this->assertStringContainsString('action="/dateien/' . $dok . '/loeschen"', $detail);
        $this->assertStringContainsString('Foto löschen', $detail);

        // Ohne Recht: Foto sichtbar, aber ohne „Foto löschen“.
        $this->anmelden('mitglied');
        $detail = $this->get("/reparaturen/$rid")->body;
        $this->assertStringContainsString('/dateien/' . $dok . '/vorschau', $detail);
        $this->assertStringNotContainsString('Foto löschen', $detail);

        // Nach dem Löschen zurück zur Reparatur.
        $this->anmelden('fahrzeugwart');
        $r = $this->post("/dateien/$dok/loeschen");
        $this->assertSame(303, $r->status);
        $this->assertSame("/reparaturen/$rid", $r->headers['Location']);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM dokument'));
    }

    public function test_fotoformular_und_zu_grosse_anfrage(): void
    {
        $fz = $this->fahrzeug();
        $rid = $this->reparatur('fahrzeug', $fz);
        $this->anmelden('fahrzeugwart');
        $detail = $this->get("/reparaturen/$rid")->body;
        $this->assertMatchesRegularExpression('/<input[^>]*type="file"[^>]*accept="image\/jpeg,image\/png,image\/webp,image\/heic,image\/heif"[^>]*multiple[^>]*data-foto-feld/', $detail);
        $this->assertMatchesRegularExpression('/höchstens \d+ MB je Foto/', $detail);

        $r = $this->app(['post_max_bytes' => 8 * 1048576])->handle(new Request('POST', "/reparaturen/$rid/fotos", [], [], [], ['CONTENT_LENGTH' => (string) (9 * 1048576)]));
        $this->assertSame(303, $r->status);
        $this->assertSame("/reparaturen/$rid", $r->headers['Location']);
        $this->assertStringContainsString('Bitte weniger oder kleinere Fotos auswählen', $this->meldung('fehler')['text']);
    }

    public function test_ohne_fotos_hochladen(): void
    {
        $rid = $this->reparatur('fahrzeug', $this->fahrzeug());
        $this->anmelden('fahrzeugwart');
        $this->assertSame(303, $this->post("/reparaturen/$rid/fotos")->status);
        $this->assertSame('Es wurde kein Foto ausgewählt.', $this->meldung('fehler')['text']);
    }

    public function test_fahrzeugseite_tab_anlagenseite_abschnitt_menue(): void
    {
        $fz = $this->fahrzeug();
        $anl = $this->anlage();
        $offen = $this->reparatur('fahrzeug', $fz, 'Licht flackert');
        $zu = $this->reparatur('fahrzeug', $fz, 'Puffer lose');
        $this->pdo->exec("UPDATE reparatur SET status = 'erledigt' WHERE id = $zu");
        $anlRep = $this->reparatur('anlage', $anl, 'Weiche klemmt');
        $this->anmelden('mitglied');

        $b = $this->get("/fahrzeuge/$fz")->body;
        $this->assertMatchesRegularExpression('/id="tab-reparaturen"[^>]*>Reparaturen <span class="badge[^>]*>1<\/span>/', $b);
        $this->assertStringContainsString('id="pane-reparaturen"', $b);
        // Tab nach „Wartung“.
        $this->assertLessThan(strpos($b, 'id="tab-reparaturen"'), strpos($b, 'id="tab-wartung"'));
        $this->assertStringContainsString('href="/reparaturen/' . $offen . '"', $b);
        $this->assertStringContainsString('href="/reparaturen/' . $zu . '"', $b);
        $this->assertLessThan(strpos($b, 'Puffer lose'), strpos($b, 'Licht flackert'));
        $this->assertStringContainsString('Abgeschlossen', $b);

        $a = $this->get("/anlagen/$anl")->body;
        $this->assertStringContainsString('<section id="reparaturen"', $a);
        $this->assertStringContainsString('href="/reparaturen/' . $anlRep . '"', $a);
        $this->assertStringContainsString('Weiche klemmt', $a);

        // Ohne Reparaturen: Hinweis, Tab ohne Zahl.
        $leer = $this->fahrzeug('EMC-0013', 'Leer');
        $b = $this->get("/fahrzeuge/$leer")->body;
        $this->assertMatchesRegularExpression('/id="tab-reparaturen"[^>]*>Reparaturen<\/button>/', $b);
        $this->assertStringContainsString('Keine Reparaturen.', $b);

        $this->assertMatchesRegularExpression('/<a class="nav-link[^"]*" href="\/reparaturen">Reparaturen<\/a>/', $this->get('/')->body);
        $start = $this->get('/')->body;
        $this->assertLessThan(strpos($start, 'href="/reparaturen"'), strpos($start, 'href="/wartung"'));
    }

    public function test_meine_meldungen_verlinkt_die_reparatur(): void
    {
        $fz = $this->fahrzeug();
        $mitglied = $this->anmelden('mitglied');
        $rid = $this->reparatur('fahrzeug', $fz, 'Licht flackert', 'normal', $mitglied);
        $this->pdo->exec("UPDATE reparatur SET status = 'wartet_auf_teile' WHERE id = $rid");
        $b = $this->get('/meine-meldungen')->body;
        $this->assertStringContainsString('href="/reparaturen/' . $rid . '"', $b);
        $this->assertStringContainsString('>wartet auf Teile</span>', $b);
    }

    /**
     * Startet auf einer zweiten Verbindung eine Transaktion (REPEATABLE READ) und liest die Tabelle: Repositories auf dieser
     * Verbindung sehen danach den alten Stand. So lässt sich nachstellen, dass jemand anderes zwischen der Prüfung vor
     * der Transaktion und der Sperre speichert.
     */
    private function alterStand(string $tabelle): \PDO
    {
        $b = self::verbindung();
        $b->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $b->beginTransaction();
        $b->query("SELECT * FROM $tabelle")->fetchAll();
        return $b;
    }

    /** Recht wird unter der Sperre am aktuellen Objekt erneut geprüft: Eigentümer inzwischen gewechselt → 403, nichts gespeichert. */
    public function test_recht_unter_sperre_erneut_geprueft(): void
    {
        $mitglied = $this->anmelden('mitglied');
        $fz = $this->fahrzeug('EMC-0012', 'V 200', $this->personVon($mitglied));
        $rid = $this->reparatur('fahrzeug', $fz);
        $formular = $this->formular($rid, ['beschreibung' => 'Motor getauscht', 'status' => 'in_arbeit']);

        $b = $this->alterStand('fahrzeug');
        $neu = $this->person('Erika', 'Beispiel');
        $this->pdo->exec("UPDATE fahrzeug SET eigentuemer_person_id = $neu WHERE id = $fz");
        $app = $this->app();
        // Vor der Transaktion sieht der Controller noch das Mitglied als Eigentümer.
        $app->container()->set(\Emc\Fahrzeuge\FahrzeugRepository::class, fn() => new \Emc\Fahrzeuge\FahrzeugRepository($b));
        $r = $app->handle(new Request('POST', "/reparaturen/$rid", [], $formular + ['_csrf' => str_repeat('a', 64)]));
        $b->rollBack();

        $this->assertSame(403, $r->status);
        $this->assertSame('Motor brummt', $this->wert("SELECT beschreibung FROM reparatur WHERE id = $rid"));
        $this->assertSame('gemeldet', $this->wert("SELECT status FROM reparatur WHERE id = $rid"));
        $this->assertSame('einsatzbereit', $this->wert("SELECT status FROM fahrzeug WHERE id = $fz"));
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'reparatur' AND objekt_id = $rid"));
    }

    /** Zuständige Person zwischen Prüfung und Speichern gelöscht: 422 mit Hinweis statt Fehler 500 (FK 1452). */
    public function test_zustaendige_person_inzwischen_geloescht(): void
    {
        $fz = $this->fahrzeug();
        $rid = $this->reparatur('fahrzeug', $fz);
        $weg = $this->person('Erika', 'Beispiel');
        $this->anmelden('fahrzeugwart');
        $formular = $this->formular($rid, ['beschreibung' => 'Motor getauscht', 'zustaendig' => (string) $weg]);

        $b = $this->alterStand('person');
        $this->pdo->exec("DELETE FROM person WHERE id = $weg");
        $app = $this->app();
        // Die Prüfung vor der Transaktion kennt die Person noch.
        $app->container()->set(\Emc\Personen\PersonRepository::class, fn() => new \Emc\Personen\PersonRepository($b));
        $r = $app->handle(new Request('POST', "/reparaturen/$rid", [], $formular + ['_csrf' => str_repeat('a', 64)]));
        $b->rollBack();

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Bitte eine Person aus der Liste wählen.', $r->body);
        $this->assertStringContainsString('Motor getauscht', $r->body);
        $this->assertSame('Motor brummt', $this->wert("SELECT beschreibung FROM reparatur WHERE id = $rid"));
        $this->assertNull($this->wert("SELECT zustaendig_person_id FROM reparatur WHERE id = $rid"));
    }

    public function test_formular_hinweise_zu_ersatzteilen(): void
    {
        $fz = $this->fahrzeug();
        $rid = $this->reparatur('fahrzeug', $fz);
        $this->anmelden('fahrzeugwart');
        $b = $this->get("/reparaturen/$rid/bearbeiten")->body;
        $this->assertStringContainsString('placeholder="z. B. 12,50"', $b);
        $this->assertStringNotContainsString('placeholder="12,50"', $b);
        $this->assertStringContainsString('Wer eine Zeile leert, entfernt das Ersatzteil.', $b);
    }
}
