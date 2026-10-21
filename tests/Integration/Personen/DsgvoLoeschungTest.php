<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Personen;

use Emc\Core\App;
use Emc\Core\DomainFehler;
use Emc\Core\Session;
use Emc\Dateien\DokumentRepository;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\OneDrive\Auftraege;
use Emc\Personen\DsgvoLoeschung;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturRepository;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;
use Emc\Wartung\AusnahmeRepository;
use Emc\Wartung\WartungRepository;

final class DsgvoLoeschungTest extends DbTestCase
{
    private string $speicher;
    private DokumentRepository $dokumente;
    private Aenderungsprotokoll $protokoll;
    private DsgvoLoeschung $dienst;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher . '/2026/10', 0750, true);
        $this->dokumente = new DokumentRepository($this->pdo, $this->speicher, new Auftraege($this->pdo));
        $this->protokoll = new Aenderungsprotokoll($this->pdo);
        $this->dienst = new DsgvoLoeschung($this->pdo, $this->dokumente, $this->protokoll, new FahrzeugRepository($this->pdo), new Auftraege($this->pdo), null);
        $this->admin = $this->legeBenutzerAn('admin@verein.de', rolle: 'admin');
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
    }

    private function person(string $vorname = 'Carla', string $nachname = 'Beispiel', ?string $email = 'carla@privat.de', ?string $telefon = '0345 987654'): int
    {
        $this->pdo->prepare('INSERT INTO person (vorname, nachname, email, telefon) VALUES (?, ?, ?, ?)')
            ->execute([$vorname, $nachname, $email, $telefon]);
        return (int) $this->pdo->lastInsertId();
    }

    private function konto(int $personId, string $email = 'carla@verein.de', string $rolle = 'mitglied', bool $aktiv = true): int
    {
        $this->pdo->prepare("INSERT INTO benutzer (person_id, email, passwort_hash, rolle, aktiv) VALUES (?, ?, 'x', ?, ?)")
            ->execute([$personId, $email, $rolle, $aktiv ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function fahrzeug(string $nummer, ?int $eigentuemer, ?int $angelegtVon = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, eigentuemer_person_id, angelegt_von_benutzer_id) SELECT ?, ?, id, ?, ? FROM spurweite ORDER BY id LIMIT 1"
        )->execute([$nummer, 'Lok ' . $nummer, $eigentuemer, $angelegtVon]);
        return (int) $this->pdo->lastInsertId();
    }

    private function decoder(int $fahrzeugId): int
    {
        $this->pdo->prepare("INSERT INTO decoder (fahrzeug_id, bezeichnung, adresse) VALUES (?, 'Lokdecoder', 3)")->execute([$fahrzeugId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO funktion (decoder_id, taste, beschreibung) VALUES (?, 0, 'Licht')")->execute([$id]);
        $this->pdo->prepare('INSERT INTO cv_wert (decoder_id, cv, wert) VALUES (?, 1, 3)')->execute([$id]);
        return $id;
    }

    /** Datensatz samt Original, Anzeige- und Vorschaudatei auf dem Webspace. */
    private function dokument(string $name, array $fahrzeuge, string $ablage = 'lokal', ?string $itemId = null, ?int $hochgeladenVon = null): int
    {
        $pfad = '2026/10/' . $name;
        $this->pdo->prepare(
            "INSERT INTO dokument (titel, kategorie, original_name, endung, mime, groesse, pfad, ablage, onedrive_item_id, onedrive_drive_id, hochgeladen_von) VALUES (?, 'foto', ?, 'jpg', 'image/jpeg', 3, ?, ?, ?, ?, ?)"
        )->execute([$name, $name, $pfad, $ablage, $itemId, $itemId === null ? null : 'drive-1', $hochgeladenVon]);
        $id = (int) $this->pdo->lastInsertId();
        foreach (['', '.anzeige.jpg', '.vorschau.jpg'] as $endung) {
            file_put_contents($this->speicher . '/' . $pfad . $endung, 'abc');
        }
        foreach ($fahrzeuge as $fz) {
            $this->dokumente->zuordnen($id, 'fahrzeug', $fz);
        }
        return $id;
    }

    private function zaehle(string $sql, array $werte = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($werte);
        return (int) $st->fetchColumn();
    }

    /** Wartung (Kupplungen prüfen) an einem Objekt; optional mit Foto (nur dieser Wartung zugeordnet). */
    private function wartung(string $typ, int $objektId, ?int $personId, ?int $benutzerId, ?string $foto = null): int
    {
        $art = $this->zaehle("SELECT id FROM wartungsart WHERE name = 'Kupplungen prüfen'");
        $id = (new WartungRepository($this->pdo))->eintragen($typ, $objektId, [
            'datum' => '2026-10-01', 'betriebsstunden' => null, 'stundenstand_bezug' => null, 'ausgefuehrt_von_person_id' => $personId, 'notiz' => null,
        ], [$art], $benutzerId);
        if ($foto !== null) {
            $dok = $this->dokument($foto, []);
            $this->dokumente->zuordnen($dok, 'wartung', $id);
        }
        return $id;
    }

    /**
     * Reparatur an einem Objekt (gemeldet vom Konto, zuständig die Person), mit einem Ersatzteil, optional einem Foto
     * (nur dieser Reparatur zugeordnet) und einer Gesehen-Zeile des Admins.
     */
    private function reparatur(string $typ, int $objektId, ?int $benutzerId, ?int $personId, ?string $foto = null): int
    {
        $id = (new ReparaturRepository($this->pdo))->anlegen($typ, $objektId, ['beschreibung' => 'Motor brummt', 'prioritaet' => 'dringend'], $benutzerId);
        $this->pdo->prepare('UPDATE reparatur SET zustaendig_person_id = ? WHERE id = ?')->execute([$personId, $id]);
        $this->pdo->prepare("INSERT INTO ersatzteil (reparatur_id, bezeichnung, kosten) VALUES (?, 'Haftreifen', 4.90)")->execute([$id]);
        $this->pdo->prepare('INSERT INTO reparatur_gesehen (benutzer_id, reparatur_id, gesehen_am) VALUES (?, ?, NOW())')->execute([$this->admin, $id]);
        $this->protokoll->schreibe($this->admin, 'reparatur', $id, 'angelegt', ['beschreibung' => ['alt' => null, 'neu' => 'Motor brummt']]);
        if ($foto !== null) {
            $dok = $this->dokument($foto, []);
            $this->dokumente->zuordnen($dok, 'reparatur', $id);
        }
        return $id;
    }

    private function dateienDa(string $name): bool
    {
        foreach (['', '.anzeige.jpg', '.vorschau.jpg'] as $endung) {
            if (!is_file($this->speicher . '/2026/10/' . $name . $endung)) {
                return false;
            }
        }
        return true;
    }

    private function dateienWeg(string $name): bool
    {
        foreach (['', '.anzeige.jpg', '.vorschau.jpg'] as $endung) {
            if (is_file($this->speicher . '/2026/10/' . $name . $endung)) {
                return false;
            }
        }
        return true;
    }

    /** Person mit Konto, zwei eigenen Fahrzeugen (Foto lokal bzw. im OneDrive) und Anmeldeversuchen. */
    private function szenario(): array
    {
        $person = $this->person();
        $konto = $this->konto($person);
        $fz1 = $this->fahrzeug('EMC-0001', $person, $konto);
        $fz2 = $this->fahrzeug('EMC-0002', $person, $konto);
        $this->decoder($fz1);
        $this->decoder($fz2);
        $lokal = $this->dokument('lokal.jpg', [$fz1], hochgeladenVon: $konto);
        $this->dokumente->setzeTitelbild($fz1, $lokal);
        $this->dokument('wolke.jpg', [$fz2], 'onedrive', 'item-wolke', $konto);
        foreach (['carla@verein.de', 'CARLA@privat.de', 'jemand@verein.de'] as $mail) {
            $this->pdo->prepare("INSERT INTO login_versuch (email, ip) VALUES (?, '127.0.0.1')")->execute([$mail]);
        }
        return ['person' => $person, 'konto' => $konto, 'fz1' => $fz1, 'fz2' => $fz2];
    }

    public function test_vorschau_listet_eigene_fahrzeuge_und_konto(): void
    {
        $s = $this->szenario();
        $this->protokoll->schreibe($s['konto'], 'person', $s['person'], 'angelegt', ['vorname' => ['alt' => null, 'neu' => 'Carla']]);
        $this->fahrzeug('EMC-0003', null, $s['konto']); // Vereinsfahrzeug: gehört nicht dazu

        $v = $this->dienst->vorschau($s['person'], $this->admin);

        $this->assertSame('Beispiel', $v['person']['nachname']);
        $this->assertNotNull($v['benutzer']);
        $this->assertSame('carla@verein.de', $v['benutzer']['email']);
        $this->assertArrayNotHasKey('passwort_hash', $v['benutzer']);
        $this->assertSame([
            ['id' => $s['fz1'], 'inventarnummer' => 'EMC-0001', 'bezeichnung' => 'Lok EMC-0001', 'dokumente' => 1, 'rechnungen' => 0, 'wartungen' => 0, 'reparaturen' => 0],
            ['id' => $s['fz2'], 'inventarnummer' => 'EMC-0002', 'bezeichnung' => 'Lok EMC-0002', 'dokumente' => 1, 'rechnungen' => 0, 'wartungen' => 0, 'reparaturen' => 0],
        ], $v['fahrzeuge']);
        $this->assertFalse($v['onedrive_konto_der_person']);
        $this->assertSame(1, $v['protokolleintraege']);
        $this->assertNull($v['verweigert']);
        // Die Vorschau ändert nichts.
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM aenderungsprotokoll WHERE benutzer_id = ?', [$s['konto']]));
    }

    public function test_vorschau_nennt_rechnungen_und_onedrive_konto_der_person(): void
    {
        $s = $this->szenario();
        $rechnung = $this->dokument('kauf.pdf', [$s['fz1']]);
        $this->pdo->prepare("UPDATE dokument SET kategorie = 'rechnung' WHERE id = ?")->execute([$rechnung]);
        $this->pdo->prepare("INSERT INTO einstellung (schluessel, wert) VALUES ('onedrive_konto', 'Carla@Privat.de')")->execute();

        $v = $this->dienst->vorschau($s['person'], $this->admin);

        $this->assertSame(2, $v['fahrzeuge'][0]['dokumente']);
        $this->assertSame(1, $v['fahrzeuge'][0]['rechnungen']);
        $this->assertSame(0, $v['fahrzeuge'][1]['rechnungen']);
        $this->assertTrue($v['onedrive_konto_der_person']);
    }

    public function test_vorschau_ohne_konto_und_ohne_fahrzeuge(): void
    {
        $person = $this->person();

        $v = $this->dienst->vorschau($person, $this->admin);

        $this->assertNull($v['benutzer']);
        $this->assertSame([], $v['fahrzeuge']);
        $this->assertNull($v['verweigert']);
    }

    public function test_unbekannte_person_wirft(): void
    {
        $this->expectException(DomainFehler::class);
        $this->dienst->loesche(999, [], $this->admin);
    }

    public function test_loesche_entfernt_person_konto_eigene_fahrzeuge_und_dateien(): void
    {
        $s = $this->szenario();

        $this->dienst->loesche($s['person'], [], $this->admin);

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM person WHERE id = ?', [$s['person']]));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM benutzer WHERE id = ?', [$s['konto']]));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM fahrzeug'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM decoder'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM funktion'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM cv_wert'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM dokument'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM dokument_zuordnung'));
        $this->assertTrue($this->dateienWeg('lokal.jpg'));
        $this->assertTrue($this->dateienWeg('wolke.jpg'));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'loeschen'"));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'loeschen' AND item_id = 'item-wolke' AND drive_id = 'drive-1'"));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM login_versuch WHERE email IN ('carla@verein.de', 'carla@privat.de')"));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM login_versuch WHERE email = 'jemand@verein.de'"));
        // Der ausführende Admin bleibt unberührt.
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM benutzer WHERE id = ?', [$this->admin]));
    }

    public function test_loesche_entfernt_kennwort_tokens_anfragen_und_versuche(): void
    {
        $s = $this->szenario();
        $andere = $this->konto($this->person('Dora', 'Andere', 'dora@privat.de'), 'dora@verein.de');
        $salz = str_repeat('ab', 32);
        $this->pdo->prepare("INSERT INTO einstellung (schluessel, wert) VALUES ('kennwort_ip_salz', ?)")->execute([$salz]);
        $hash = fn(string $m): string => \Emc\Auth\KennwortVergessen::emailHash($m, $salz);
        foreach ([$s['konto'], $andere] as $konto) {
            $this->pdo->prepare('INSERT INTO kennwort_token (benutzer_id, token_hash, gueltig_bis) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)')
                ->execute([$konto, hash('sha256', 'token' . $konto)]);
            $this->pdo->prepare('INSERT INTO kennwort_anfrage (benutzer_id, erstellt_am) VALUES (?, NOW())')->execute([$konto]);
        }
        foreach (['carla@verein.de', 'carla@privat.de', 'dora@verein.de'] as $mail) {
            $this->pdo->prepare("INSERT INTO kennwort_versuch (email_hash, ip_hash, zeitpunkt) VALUES (?, ?, NOW())")
                ->execute([$hash($mail), str_repeat('1', 64)]);
        }

        $this->dienst->loesche($s['person'], [], $this->admin);

        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM kennwort_token WHERE benutzer_id = ?', [$s['konto']]));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM kennwort_anfrage WHERE benutzer_id = ?', [$s['konto']]));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM kennwort_versuch WHERE email_hash IN (?, ?)', [$hash('carla@verein.de'), $hash('carla@privat.de')]));
        // Daten anderer Personen bleiben.
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM kennwort_token WHERE benutzer_id = ?', [$andere]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM kennwort_anfrage WHERE benutzer_id = ?', [$andere]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM kennwort_versuch WHERE email_hash = ?', [$hash('dora@verein.de')]));
    }

    public function test_eigene_fahrzeuge_mit_wartungen_und_fotos_geloescht(): void
    {
        $s = $this->szenario();
        $this->wartung('fahrzeug', $s['fz1'], $s['person'], $s['konto'], 'wartung.jpg');
        $this->wartung('fahrzeug', $s['fz2'], null, null);
        (new AusnahmeRepository($this->pdo))->speichere('fahrzeug', $s['fz1'], $this->zaehle("SELECT id FROM wartungsart WHERE name = 'Kupplungen prüfen'"), 'aus', null, null);
        $this->assertTrue($this->dateienDa('wartung.jpg'));

        $this->dienst->loesche($s['person'], [], $this->admin);

        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM wartung'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM wartung_art'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM wartungsplan_ausnahme'));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'wartung'"));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM dokument'));
        $this->assertTrue($this->dateienWeg('wartung.jpg'));
    }

    public function test_wartung_am_vereinsfahrzeug_bleibt_bezug_geleert(): void
    {
        $s = $this->szenario();
        $verein = $this->fahrzeug('EMC-0010', null);
        $this->pdo->exec("INSERT INTO anlage (nummer, name, spurweite_id) SELECT 1, 'Clubanlage', id FROM spurweite ORDER BY id LIMIT 1");
        $anlage = (int) $this->pdo->lastInsertId();
        $w1 = $this->wartung('fahrzeug', $verein, $s['person'], $s['konto'], 'verein-wartung.jpg');
        $w2 = $this->wartung('anlage', $anlage, $s['person'], $s['konto']);

        $this->dienst->loesche($s['person'], [], $this->admin);

        foreach ([$w1, $w2] as $w) {
            $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM wartung WHERE id = ? AND ausgefuehrt_von_person_id IS NULL AND erfasst_von_benutzer_id IS NULL', [$w]));
        }
        $this->assertTrue($this->dateienDa('verein-wartung.jpg'));
        $verlauf = (new WartungRepository($this->pdo))->fuerObjekt('fahrzeug', $verein);
        $this->assertNull($verlauf[0]['ausgefuehrt_von']); // Anzeige „ehemaliges Mitglied“
    }

    public function test_uebergebenes_fahrzeug_behaelt_wartungen(): void
    {
        $s = $this->szenario();
        $w = $this->wartung('fahrzeug', $s['fz1'], $s['person'], $s['konto'], 'uebergeben.jpg');
        (new AusnahmeRepository($this->pdo))->speichere('fahrzeug', $s['fz1'], $this->zaehle("SELECT id FROM wartungsart WHERE name = 'Kupplungen prüfen'"), 'eigen', 6, null);

        $this->dienst->loesche($s['person'], [$s['fz1']], $this->admin);

        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM wartung WHERE id = ? AND ausgefuehrt_von_person_id IS NULL', [$w]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM wartung_art WHERE wartung_id = ?', [$w]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM wartungsplan_ausnahme'));
        $this->assertTrue($this->dateienDa('uebergeben.jpg'));
    }

    public function test_vorschau_zaehlt_wartungen(): void
    {
        $s = $this->szenario();
        $this->wartung('fahrzeug', $s['fz1'], null, null);
        $this->wartung('fahrzeug', $s['fz1'], $s['person'], null);
        $verein = $this->fahrzeug('EMC-0010', null);
        $this->wartung('fahrzeug', $verein, $s['person'], null);
        $this->wartung('fahrzeug', $verein, null, $s['konto']);
        $this->wartung('fahrzeug', $verein, null, null); // nicht von ihr

        $v = $this->dienst->vorschau($s['person'], $this->admin);

        $this->assertSame(2, $v['fahrzeuge'][0]['wartungen']);
        $this->assertSame(0, $v['fahrzeuge'][1]['wartungen']);
        $this->assertSame(2, $v['wartungen_in_vereinsdaten']);
    }

    public function test_uebergabe_an_verein_behaelt_fahrzeug(): void
    {
        $s = $this->szenario();

        $this->dienst->loesche($s['person'], [$s['fz1']], $this->admin);

        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM fahrzeug WHERE id = ? AND eigentuemer_person_id IS NULL AND angelegt_von_benutzer_id IS NULL', [$s['fz1']]));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM fahrzeug WHERE id = ?', [$s['fz2']]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM decoder WHERE fahrzeug_id = ?', [$s['fz1']]));
        $this->assertTrue($this->dateienDa('lokal.jpg'), 'Fotos übergebener Fahrzeuge bleiben');
        $this->assertTrue($this->dateienWeg('wolke.jpg'));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM dokument WHERE hochgeladen_von IS NULL'));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM fahrzeug WHERE id = ? AND titelbild_dokument_id IS NOT NULL', [$s['fz1']]));

        $verlauf = $this->protokoll->fuerObjekt('fahrzeug', $s['fz1']);
        $this->assertCount(1, $verlauf);
        $this->assertSame('geaendert', $verlauf[0]['aktion']);
        $this->assertSame('Max Muster', $verlauf[0]['anzeigename']);
        $this->assertSame(['eigentuemer' => ['alt' => 'ehemaliges Mitglied', 'neu' => 'Verein']], $verlauf[0]['aenderungen']);
    }

    public function test_vereinsfahrzeug_bleibt_bezug_wird_geleert(): void
    {
        $s = $this->szenario();
        $verein = $this->fahrzeug('EMC-0010', null, $s['konto']);
        $this->dokument('verein.jpg', [$verein], hochgeladenVon: $s['konto']);
        $fremd = $this->fahrzeug('EMC-0011', $this->person('Bernd', 'Anders', 'bernd@privat.de', null), $s['konto']);

        $this->dienst->loesche($s['person'], [], $this->admin);

        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM fahrzeug WHERE id = ? AND angelegt_von_benutzer_id IS NULL', [$verein]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM fahrzeug WHERE id = ? AND angelegt_von_benutzer_id IS NULL', [$fremd]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM dokument WHERE hochgeladen_von IS NULL'));
        $this->assertTrue($this->dateienDa('verein.jpg'));
    }

    public function test_geteiltes_dokument_verliert_nur_die_zuordnung(): void
    {
        $s = $this->szenario();
        $verein = $this->fahrzeug('EMC-0010', null);
        $geteilt = $this->dokument('geteilt.jpg', [$s['fz2'], $verein], 'onedrive', 'item-geteilt');

        $this->dienst->loesche($s['person'], [], $this->admin);

        $this->assertNotNull($this->dokumente->finde($geteilt));
        $this->assertSame([['objekt_typ' => 'fahrzeug', 'objekt_id' => $verein]], $this->dokumente->zuordnungen($geteilt));
        $this->assertTrue($this->dateienDa('geteilt.jpg'));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM onedrive_auftrag WHERE item_id = 'item-geteilt'"));
    }

    public function test_protokoll_enthaelt_keine_personendaten_mehr(): void
    {
        $s = $this->szenario();
        $andere = $this->person('Carla', 'Schmidt', 'schmidt@privat.de', null);
        $verein = $this->fahrzeug('EMC-0010', null);
        $p = $this->protokoll;
        $p->schreibe($this->admin, 'person', $s['person'], 'angelegt', ['nachname' => ['alt' => null, 'neu' => 'Beispiel']]);
        $p->schreibe($this->admin, 'benutzer', $s['konto'], 'angelegt', ['email' => ['alt' => null, 'neu' => 'carla@verein.de']]);
        $p->schreibe($s['konto'], 'fahrzeug', $s['fz1'], 'angelegt', ['bezeichnung' => ['alt' => null, 'neu' => 'Lok']]);
        $p->schreibe($s['konto'], 'fahrzeug', $verein, 'geaendert', ['notiz' => ['alt' => null, 'neu' => 'Ausgeliehen an Carla Beispiel, Tel. 0345 987654']]);
        $p->schreibe($this->admin, 'fahrzeug', $verein, 'geaendert', ['notiz' => ['alt' => 'Beispiel, Carla', 'neu' => 'Mail an CARLA@privat.de']]);
        $p->schreibe($this->admin, 'person', $andere, 'geaendert', ['vorname' => ['alt' => 'Carla', 'neu' => 'Carla Schmidt']]);

        $this->dienst->loesche($s['person'], [], $this->admin);

        foreach (['Carla Beispiel', 'Beispiel, Carla', 'Beispiel Carla', 'carla@privat.de', 'carla@verein.de', '0345 987654', 'Beispiel'] as $begriff) {
            $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM aenderungsprotokoll WHERE aenderungen LIKE ?', ['%' . $begriff . '%']), $begriff);
        }
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM aenderungsprotokoll WHERE benutzer_id = ?', [$s['konto']]));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'benutzer' AND objekt_id = ?", [$s['konto']]));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = ?", [$s['fz1']]));
        // Der Vorname allein und gleichnamige Mitglieder bleiben.
        $this->assertSame(['vorname' => ['alt' => 'Carla', 'neu' => 'Carla Schmidt']], $p->fuerObjekt('person', $andere)[0]['aenderungen']);

        $nachweis = $p->fuerObjekt('person', $s['person']);
        $this->assertCount(1, $nachweis);
        $this->assertSame('geloescht', $nachweis[0]['aktion']);
        $this->assertSame('Max Muster', $nachweis[0]['anzeigename']);
        $this->assertSame(['dsgvo' => ['alt' => null, 'neu' => 'nach DSGVO gelöscht']], $nachweis[0]['aenderungen']);
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM aenderungsprotokoll WHERE aenderungen LIKE '%nach DSGVO gelöscht%'"));
    }

    public function test_namensbegriffe_nur_mit_vor_und_nachname(): void
    {
        $person = $this->person('', 'Weber', null, null);
        $verein = $this->fahrzeug('EMC-0010', null);
        $this->protokoll->schreibe($this->admin, 'fahrzeug', $verein, 'geaendert', ['notiz' => ['alt' => null, 'neu' => 'Hans Weber hat geölt']]);

        $this->dienst->loesche($person, [], $this->admin);

        $this->assertSame(['notiz' => ['alt' => null, 'neu' => 'Hans Weber hat geölt']], $this->protokoll->fuerObjekt('fahrzeug', $verein)[0]['aenderungen']);
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM person WHERE id = ?', [$person]));
    }

    public function test_fehler_mitten_drin_laesst_alles_unveraendert(): void
    {
        $s = $this->szenario();
        $this->protokoll->schreibe($s['konto'], 'person', $s['person'], 'angelegt', ['nachname' => ['alt' => null, 'neu' => 'Beispiel']]);
        $wartung = $this->wartung('fahrzeug', $s['fz2'], $s['person'], $s['konto'], 'rollback-wartung.jpg');
        (new AusnahmeRepository($this->pdo))->speichere('fahrzeug', $s['fz2'], $this->zaehle("SELECT id FROM wartungsart WHERE name = 'Kupplungen prüfen'"), 'aus', null, null);
        $reparatur = $this->reparatur('fahrzeug', $s['fz2'], $s['konto'], $s['person'], 'rollback-reparatur.jpg');
        $vereinsReparatur = $this->reparatur('fahrzeug', $this->fahrzeug('EMC-0010', null), $s['konto'], $s['person']);
        $boerse = $this->boersenDienste();
        $angebot = $this->mitgliedsAngebot($boerse, $s['person'], $this->boerseLaeuft(), '5.00', 'rollback-angebot.jpg');
        $vorher = $this->zaehle('SELECT COUNT(*) FROM aenderungsprotokoll');
        // Scheitert im letzten Schritt (Person löschen) – alles davor muss zurückgerollt werden.
        $this->pdo->exec("CREATE TRIGGER test_person_sperre BEFORE DELETE ON person FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gesperrt'");
        try {
            try {
                $this->dienst->loesche($s['person'], [$s['fz1']], $this->admin);
                $this->fail('Ausnahme erwartet');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('gesperrt', $e->getMessage());
            }
        } finally {
            $this->pdo->exec('DROP TRIGGER IF EXISTS test_person_sperre');
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM person WHERE id = ?', [$s['person']]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM benutzer WHERE id = ?', [$s['konto']]));
        $this->assertSame(2, $this->zaehle('SELECT COUNT(*) FROM fahrzeug WHERE eigentuemer_person_id = ?', [$s['person']]));
        $this->assertSame(2, $this->zaehle('SELECT COUNT(*) FROM decoder'));
        $this->assertSame(2, $this->zaehle('SELECT COUNT(*) FROM dokument WHERE hochgeladen_von = ?', [$s['konto']]));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM onedrive_auftrag'));
        $this->assertSame(3, $this->zaehle('SELECT COUNT(*) FROM login_versuch'));
        $this->assertSame($vorher, $this->zaehle('SELECT COUNT(*) FROM aenderungsprotokoll'));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM aenderungsprotokoll WHERE benutzer_id = ?', [$s['konto']]));
        $this->assertTrue($this->dateienDa('lokal.jpg'));
        $this->assertTrue($this->dateienDa('wolke.jpg'));
        // Wartungen, Fotos und Ausnahmen unverändert.
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM wartung WHERE id = ? AND ausgefuehrt_von_person_id = ? AND erfasst_von_benutzer_id = ?', [$wartung, $s['person'], $s['konto']]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM wartung_art WHERE wartung_id = ?', [$wartung]));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'wartung' AND objekt_id = ?", [$wartung]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM wartungsplan_ausnahme'));
        $this->assertTrue($this->dateienDa('rollback-wartung.jpg'));
        // Reparaturen samt Ersatzteilen, Gesehen-Zeilen, Fotos und Bezügen unverändert.
        foreach ([$reparatur, $vereinsReparatur] as $r) {
            $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM reparatur WHERE id = ? AND gemeldet_von_benutzer_id = ? AND zustaendig_person_id = ?', [$r, $s['konto'], $s['person']]));
            $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM ersatzteil WHERE reparatur_id = ?', [$r]));
            $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM reparatur_gesehen WHERE reparatur_id = ?', [$r]));
        }
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'reparatur' AND objekt_id = ?", [$reparatur]));
        $this->assertTrue($this->dateienDa('rollback-reparatur.jpg'));
        // Börsenangebot samt Foto unverändert.
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM boerse_angebot WHERE id = ? AND person_id = ? AND notiz IS NOT NULL', [$angebot, $s['person']]));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'boerse_angebot' AND objekt_id = ?", [$angebot]));
        $this->assertTrue($this->dateienDa('rollback-angebot.jpg'));
    }

    public function test_eigene_person_und_letzter_admin_verweigert(): void
    {
        // Eigene Person des ausführenden Admins.
        $eigenePerson = $this->zaehle('SELECT person_id FROM benutzer WHERE id = ?', [$this->admin]);
        $this->assertNotNull($this->dienst->vorschau($eigenePerson, $this->admin)['verweigert']);
        try {
            $this->dienst->loesche($eigenePerson, [], $this->admin);
            $this->fail('Verweigerung erwartet');
        } catch (DomainFehler $e) {
            $this->assertStringContainsString('eigene', $e->getMessage());
        }

        // Der ausführende Zugang muss (in der Transaktion geprüft) ein aktiver Admin sein – sonst wäre die Zielperson
        // hier zugleich der letzte aktive Admin.
        $bearbeiter = $this->konto($this->person('Erik', 'Helfer', null, null), 'erik@verein.de', 'bearbeiter');
        $this->assertSame(DsgvoLoeschung::KEIN_ADMIN, $this->dienst->vorschau($this->person('Fritz', 'Ziel', null, null), $bearbeiter)['verweigert']);
        $this->pdo->prepare('UPDATE benutzer SET aktiv = 0 WHERE id = ?')->execute([$this->admin]);
        $chefin = $this->person('Dora', 'Vorstand', 'dora@privat.de', null);
        $this->konto($chefin, 'dora@verein.de', 'admin');
        $this->assertNotNull($this->dienst->vorschau($chefin, $this->admin)['verweigert']);
        try {
            $this->dienst->loesche($chefin, [], $this->admin);
            $this->fail('Verweigerung erwartet');
        } catch (DomainFehler $e) {
            $this->assertStringContainsString('Administrator', $e->getMessage());
        }
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM person WHERE id = ?', [$chefin]));
        $this->assertFalse($this->pdo->inTransaction());

        // Mit einem weiteren aktiven Admin geht es.
        $this->pdo->prepare('UPDATE benutzer SET aktiv = 1 WHERE id = ?')->execute([$this->admin]);
        $this->assertNull($this->dienst->vorschau($chefin, $this->admin)['verweigert']);
        $this->dienst->loesche($chefin, [], $this->admin);
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM person WHERE id = ?', [$chefin]));
    }

    public function test_fremde_fahrzeug_id_bei_uebergabe_wirft(): void
    {
        $s = $this->szenario();
        $fremd = $this->fahrzeug('EMC-0010', $this->person('Bernd', 'Anders', null, null));

        try {
            $this->dienst->loesche($s['person'], [$s['fz1'], $fremd], $this->admin);
            $this->fail('Ausnahme erwartet');
        } catch (DomainFehler) {
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM person WHERE id = ?', [$s['person']]));
        $this->assertSame(3, $this->zaehle('SELECT COUNT(*) FROM fahrzeug'));
        $this->assertTrue($this->dateienDa('lokal.jpg'));
    }

    public function test_dienst_im_container(): void
    {
        $sitzung = [];
        $app = App::erzeuge(self::testConfig(), new Session($sitzung));
        $this->assertInstanceOf(DsgvoLoeschung::class, $app->container()->get(DsgvoLoeschung::class));
    }

    public function test_onedrive_loeschauftrag_auch_ohne_onedrive_konfiguration(): void
    {
        // Ohne OneDrive-Konfiguration bekommt DokumentRepository keine Aufträge (siehe App).
        $ohneAuftraege = new DokumentRepository($this->pdo, $this->speicher);
        $dienst = new DsgvoLoeschung($this->pdo, $ohneAuftraege, $this->protokoll, new FahrzeugRepository($this->pdo), new Auftraege($this->pdo), null);
        $s = $this->szenario();

        $dienst->loesche($s['person'], [], $this->admin);

        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'loeschen'"));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'loeschen' AND status = 'wartet' AND item_id = 'item-wolke' AND drive_id = 'drive-1'"));
        $this->assertTrue($this->dateienWeg('wolke.jpg'));
    }

    public function test_fruehere_kennungen_werden_entfernt(): void
    {
        $person = $this->person('Carla', 'Beispiel', 'carla@privat.de', null);
        $andere = $this->person('Bernd', 'Anders', null, null);
        $verein = $this->fahrzeug('EMC-0010', null);
        $p = $this->protokoll;
        $p->schreibe($this->admin, 'person', $person, 'angelegt', [
            'vorname' => ['alt' => null, 'neu' => 'Carla'], 'nachname' => ['alt' => null, 'neu' => 'Alt'],
            'email' => ['alt' => null, 'neu' => 'carla.alt@privat.de'], 'telefon' => ['alt' => null, 'neu' => '0170 111222'],
        ]);
        $p->schreibe($this->admin, 'person', $person, 'geaendert', [
            'nachname' => ['alt' => 'Alt', 'neu' => 'Beispiel'], 'email' => ['alt' => 'carla.alt@privat.de', 'neu' => 'carla@privat.de'],
            'telefon' => ['alt' => '0170 111222', 'neu' => null],
        ]);
        // Früherer, inzwischen gelöschter Zugang Nr. 77 der Person.
        $p->schreibe($this->admin, 'benutzer', 77, 'angelegt', [
            'email' => ['alt' => null, 'neu' => 'alt@verein.de'], 'rolle' => ['alt' => null, 'neu' => 'mitglied'],
            'aktiv' => ['alt' => null, 'neu' => 1], 'person_id' => ['alt' => null, 'neu' => $person],
        ]);
        $p->schreibe(77, 'fahrzeug', $verein, 'geaendert', ['notiz' => ['alt' => null, 'neu' => 'Carla Alt, Tel. 0170 111222, carla.alt@privat.de, alt@verein.de']]);
        $p->schreibe($this->admin, 'benutzer', 77, 'geloescht', ['email' => ['alt' => 'alt@verein.de', 'neu' => null], 'rolle' => ['alt' => 'mitglied', 'neu' => null]]);
        // Zugang einer anderen Person bleibt.
        $p->schreibe($this->admin, 'benutzer', 78, 'angelegt', ['email' => ['alt' => null, 'neu' => 'bernd@verein.de'], 'person_id' => ['alt' => null, 'neu' => $andere]]);
        foreach (['alt@verein.de', 'bernd@verein.de'] as $mail) {
            $this->pdo->prepare("INSERT INTO login_versuch (email, ip) VALUES (?, '127.0.0.1')")->execute([$mail]);
        }

        $this->assertSame(5, $this->dienst->vorschau($person, $this->admin)['protokolleintraege']);
        $this->dienst->loesche($person, [], $this->admin);

        foreach (['Carla Alt', 'carla.alt@privat.de', '0170 111222', 'alt@verein.de'] as $begriff) {
            $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM aenderungsprotokoll WHERE aenderungen LIKE ?', ['%' . $begriff . '%']), $begriff);
        }
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'benutzer' AND objekt_id = 77"));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM aenderungsprotokoll WHERE benutzer_id = 77'));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'benutzer' AND objekt_id = 78"));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM login_versuch WHERE email = 'alt@verein.de'"));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM login_versuch WHERE email = 'bernd@verein.de'"));
    }

    public function test_hochlade_auftraege_geloeschter_dokumente_werden_entfernt(): void
    {
        $s = $this->szenario();
        $verein = $this->fahrzeug('EMC-0010', null);
        $vereinsDok = $this->dokument('verein.jpg', [$verein]);
        $eigenes = (int) $this->pdo->query("SELECT id FROM dokument WHERE pfad = '2026/10/lokal.jpg'")->fetchColumn();
        $auftraege = new Auftraege($this->pdo);
        $auftraege->neu('hochladen', $eigenes, null);
        $fehler = $auftraege->neu('hochladen', $eigenes, null);
        $this->pdo->prepare("UPDATE onedrive_auftrag SET status = 'fehler', letzter_fehler = 'lokal.jpg' WHERE id = ?")->execute([$fehler]);
        $auftraege->neu('hochladen', $vereinsDok, null);

        $this->dienst->loesche($s['person'], [], $this->admin);

        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'hochladen' AND dokument_id = ?", [$eigenes]));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'hochladen' AND dokument_id = ?", [$vereinsDok]));
    }

    public function test_dokument_an_zwei_eigenen_fahrzeugen_wird_geloescht(): void
    {
        $s = $this->szenario();
        $beide = $this->dokument('beide.jpg', [$s['fz1'], $s['fz2']], 'onedrive', 'item-beide');

        $this->dienst->loesche($s['person'], [], $this->admin);

        $this->assertNull($this->dokumente->finde($beide));
        $this->assertTrue($this->dateienWeg('beide.jpg'));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM onedrive_auftrag WHERE art = 'loeschen' AND item_id = 'item-beide'"));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM dokument_zuordnung'));
    }

    public function test_fruehere_mails_anderer_personen_und_konten_bleiben(): void
    {
        $person = $this->person('Carla', 'Beispiel', 'carla@privat.de', null);
        $partner = $this->person('Bernd', 'Beispiel', 'familie@privat.de', null);
        $kollege = $this->person('Frank', 'Fremd', null, null);
        $this->konto($kollege, 'geteilt@verein.de');
        $verein = $this->fahrzeug('EMC-0011', null);
        $p = $this->protokoll;
        $p->schreibe($this->admin, 'person', $person, 'geaendert', [
            'email' => ['alt' => 'familie@privat.de', 'neu' => 'carla@privat.de'],
        ]);
        $p->schreibe($this->admin, 'benutzer', 77, 'angelegt', [
            'email' => ['alt' => null, 'neu' => 'geteilt@verein.de'], 'person_id' => ['alt' => null, 'neu' => $person],
        ]);
        $p->schreibe($this->admin, 'benutzer', 77, 'geloescht', ['email' => ['alt' => 'geteilt@verein.de', 'neu' => null]]);
        $p->schreibe($this->admin, 'person', $partner, 'angelegt', ['email' => ['alt' => null, 'neu' => 'familie@privat.de']]);
        $p->schreibe($this->admin, 'fahrzeug', $verein, 'geaendert', ['notiz' => ['alt' => null, 'neu' => 'Fragen an GETEILT@verein.de oder carla@privat.de']]);
        foreach (['familie@privat.de', 'Geteilt@verein.de', 'carla@privat.de'] as $mail) {
            $this->pdo->prepare("INSERT INTO login_versuch (email, ip) VALUES (?, '127.0.0.1')")->execute([$mail]);
        }

        $this->dienst->loesche($person, [], $this->admin);

        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM login_versuch WHERE email = 'familie@privat.de'"));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM login_versuch WHERE email = 'geteilt@verein.de'"));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM login_versuch WHERE email = 'carla@privat.de'"));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'person' AND objekt_id = ? AND aenderungen LIKE '%familie@privat.de%'", [$partner]));
        $notiz = (string) $this->pdo->query("SELECT aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $verein")->fetchColumn();
        $this->assertStringContainsString('GETEILT@verein.de', $notiz);
        $this->assertStringNotContainsString('carla@privat.de', $notiz);
        // Das frühere Konto 77 der Person verschwindet trotzdem aus dem Protokoll.
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'benutzer' AND objekt_id = 77"));
    }

    public function test_eigene_fahrzeuge_mit_reparaturen_geloescht(): void
    {
        $s = $this->szenario();
        $r1 = $this->reparatur('fahrzeug', $s['fz1'], $s['konto'], $s['person'], 'reparatur.jpg');
        $r2 = $this->reparatur('fahrzeug', $s['fz2'], null, null);
        // Foto, das auch an einem Vereinsfahrzeug hängt: verliert nur die Zuordnung zur Reparatur.
        $verein = $this->fahrzeug('EMC-0010', null);
        $geteilt = $this->dokument('geteilt.jpg', [$verein]);
        $this->dokumente->zuordnen($geteilt, 'reparatur', $r2);
        $this->assertTrue($this->dateienDa('reparatur.jpg'));

        $this->dienst->loesche($s['person'], [], $this->admin);

        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM reparatur'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM ersatzteil'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM reparatur_gesehen'));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'reparatur'"));
        $this->assertTrue($this->dateienWeg('reparatur.jpg'));
        $this->assertTrue($this->dateienDa('geteilt.jpg'));
        $this->assertNotNull($this->dokumente->finde($geteilt));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'reparatur' AND objekt_id IN (?, ?)", [$r1, $r2]));
    }

    public function test_reparatur_am_vereinsfahrzeug_bleibt_bezuege_geleert(): void
    {
        $s = $this->szenario();
        $verein = $this->fahrzeug('EMC-0010', null);
        $this->pdo->exec("INSERT INTO anlage (nummer, name, spurweite_id) SELECT 1, 'Clubanlage', id FROM spurweite ORDER BY id LIMIT 1");
        $anlage = (int) $this->pdo->lastInsertId();
        $r1 = $this->reparatur('fahrzeug', $verein, $s['konto'], $s['person'], 'verein-reparatur.jpg');
        $r2 = $this->reparatur('anlage', $anlage, $s['konto'], $s['person']);
        $this->pdo->prepare('INSERT INTO reparatur_gesehen (benutzer_id, reparatur_id, gesehen_am) VALUES (?, ?, NOW())')->execute([$s['konto'], $r1]);

        $this->dienst->loesche($s['person'], [], $this->admin);

        foreach ([$r1, $r2] as $r) {
            $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM reparatur WHERE id = ? AND gemeldet_von_benutzer_id IS NULL AND zustaendig_person_id IS NULL', [$r]));
            $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM ersatzteil WHERE reparatur_id = ?', [$r]));
        }
        // Gesehen-Zeile des gelöschten Kontos weg, die des Admins bleibt.
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM reparatur_gesehen WHERE benutzer_id = ?', [$s['konto']]));
        $this->assertSame(2, $this->zaehle('SELECT COUNT(*) FROM reparatur_gesehen WHERE benutzer_id = ?', [$this->admin]));
        $this->assertTrue($this->dateienDa('verein-reparatur.jpg'));

        // Detailseite: „ehemaliges Mitglied“ bei gemeldet von und zuständig.
        $sitzung = [];
        $this->sitzungAnmelden($sitzung, $this->admin);
        $body = App::erzeuge(self::testConfig(), new Session($sitzung))->handle(new \Emc\Core\Request('GET', "/reparaturen/$r1"))->body;
        // „gemeldet von“ zeigt „ehemaliges Mitglied“; „zuständig“ ist nach dem Leeren nicht von „niemand“ zu unterscheiden.
        $this->assertStringContainsString('von ' . Aenderungsprotokoll::EHEMALIGES_MITGLIED, $body);
        $this->assertStringNotContainsString('Beispiel', $body);
    }

    public function test_uebergebenes_fahrzeug_behaelt_reparaturen(): void
    {
        $s = $this->szenario();
        $r = $this->reparatur('fahrzeug', $s['fz1'], $s['konto'], $s['person'], 'uebergeben-reparatur.jpg');

        $this->dienst->loesche($s['person'], [$s['fz1']], $this->admin);

        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM reparatur WHERE id = ? AND gemeldet_von_benutzer_id IS NULL AND zustaendig_person_id IS NULL', [$r]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM ersatzteil WHERE reparatur_id = ?', [$r]));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'reparatur' AND objekt_id = ?", [$r]));
        $this->assertTrue($this->dateienDa('uebergeben-reparatur.jpg'));
    }

    public function test_vorschau_zaehlt_reparaturen(): void
    {
        $s = $this->szenario();
        $this->reparatur('fahrzeug', $s['fz1'], null, null);
        $this->reparatur('fahrzeug', $s['fz1'], $s['konto'], null);
        $this->reparatur('fahrzeug', $s['fz1'], null, null);
        $verein = $this->fahrzeug('EMC-0010', null);
        $this->reparatur('fahrzeug', $verein, $s['konto'], null);
        $this->reparatur('fahrzeug', $verein, null, $s['person']);
        $this->reparatur('fahrzeug', $verein, $s['konto'], $s['person']);
        $this->reparatur('fahrzeug', $verein, null, null); // nicht von ihr

        $v = $this->dienst->vorschau($s['person'], $this->admin);

        $this->assertSame(3, $v['fahrzeuge'][0]['reparaturen']);
        $this->assertSame(0, $v['fahrzeuge'][1]['reparaturen']);
        $this->assertSame(3, $v['reparaturen_in_vereinsdaten']);

        // Die Vorschau-Seite nennt beide Zahlen.
        $sitzung = [];
        $this->sitzungAnmelden($sitzung, $this->admin);
        $body = App::erzeuge(self::testConfig(), new Session($sitzung))->handle(new \Emc\Core\Request('GET', '/personen/' . $s['person'] . '/dsgvo'))->body;
        $this->assertStringContainsString('3 Reparaturen', $body);
        $this->assertMatchesRegularExpression('~Reparaturen an Vereinsfahrzeugen[^<]*\(3\)~', $body);
    }

    /**
     * Gleichzeitig speichert jemand eine Reparatur am eigenen Fahrzeug der Person (ReparaturDienst::inTransaktion: Objekt
     * gesperrt, neue Meldung, dann die Person als zuständig gesperrt). Die DSGVO-Löschung läuft in einem zweiten Prozess:
     * Sie sperrt zuerst die eigenen Fahrzeuge und wartet; sie hält bis dahin nichts, worauf das Speichern wartet (kein
     * Deadlock), und löscht danach auch die eben festgeschriebene Meldung (keine verwaiste Reparatur).
     */
    public function test_gleichzeitiges_speichern_keine_verwaiste_reparatur_kein_deadlock(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open fehlt');
        }
        $s = $this->szenario();
        $alt = $this->reparatur('fahrzeug', $s['fz1'], null, null);
        $beobachter = self::verbindung();
        // Wartet die Löschung gerade an der sperrenden Lesung der eigenen Fahrzeuge? (PROCESSLIST: läuft seit mindestens
        // 0,2 s; INNODB_TRX ist nicht überall lesbar.)
        $wartetAmFahrzeug = fn(): bool => (int) $beobachter->query(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND TIME_MS >= 200 "
            . "AND INFO LIKE 'SELECT id, inventarnummer, bezeichnung FROM fahrzeug WHERE eigentuemer_person_id = % FOR UPDATE'"
        )->fetchColumn() > 0;

        $b = self::verbindung();
        $b->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $repB = new ReparaturRepository($b);
        $dienstB = new \Emc\Reparatur\ReparaturDienst($b, $repB, new FahrzeugRepository($b), new \Emc\Anlagen\AnlageRepository($b), new DokumentRepository($b, $this->speicher), new Aenderungsprotokoll($b));
        $prozess = null;
        $rohre = [];
        $neu = $dienstB->inTransaktion('fahrzeug', $s['fz1'], null, function () use ($repB, $b, $s, $alt, &$prozess, &$rohre, $wartetAmFahrzeug): int {
            $neu = $repB->anlegen('fahrzeug', $s['fz1'], ['beschreibung' => 'Gleichzeitig gemeldet', 'prioritaet' => 'dringend'], null);
            $b->prepare("INSERT INTO ersatzteil (reparatur_id, bezeichnung) VALUES (?, 'Schleifer')")->execute([$neu]);
            $prozess = proc_open(
                [PHP_BINARY, __DIR__ . '/dsgvo_loeschung_prozess.php', (string) $s['person'], (string) $this->admin, $this->speicher],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $rohre
            );
            $this->assertIsResource($prozess);
            // Warten, bis die Löschung an der Sperre des Fahrzeugs steht.
            $bis = microtime(true) + 15;
            while (!$wartetAmFahrzeug()) {
                $this->assertLessThan($bis, microtime(true), 'Die Löschung wartet nicht auf die Sperre des Fahrzeugs.');
                $this->assertTrue(proc_get_status($prozess)['running'], 'Die Löschung lief durch, ohne auf das Fahrzeug zu warten.');
                usleep(20000);
            }
            // Erst jetzt die Person (zuständig) – hielte die Löschung sie schon, gäbe es hier einen Deadlock.
            $this->assertTrue($repB->sperrePerson($s['person']));
            $b->prepare('UPDATE reparatur SET zustaendig_person_id = ? WHERE id = ?')->execute([$s['person'], $alt]);
            return $neu;
        });
        $ausgabe = stream_get_contents($rohre[1]) . stream_get_contents($rohre[2]);
        $code = proc_close($prozess);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertSame('OK', trim($ausgabe));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM reparatur WHERE id IN (?, ?)', [$alt, $neu]));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM ersatzteil'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM fahrzeug'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM person WHERE id = ?', [$s['person']]));
    }

    /**
     * Wie oben, aber an einem Vereinsfahrzeug, das die Person angelegt hat und an dem sie für eine Reparatur zuständig ist
     * (2c-Review M1): Das Speichern hält das Fahrzeug und sperrt danach die Person. Die Löschung sperrt solche
     * Vereinsobjekte ebenfalls zuerst, wartet also am Fahrzeug, ohne die Person zu halten – sonst warteten beide
     * aufeinander (Löschen des Kontos leert angelegt_von am gesperrten Fahrzeug) und einer bekäme einen Deadlock.
     */
    public function test_gleichzeitiges_speichern_am_vereinsfahrzeug_kein_deadlock(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open fehlt');
        }
        $s = $this->szenario();
        $verein = $this->fahrzeug('EMC-0010', null, $s['konto']);
        $alt = $this->reparatur('fahrzeug', $verein, null, $s['person']);
        $beobachter = self::verbindung();
        // Wartet die Löschung gerade an einer Sperre? (PROCESSLIST: eine Anweisung läuft seit mindestens 0,2 s.)
        $wartet = fn(): bool => (int) $beobachter->query(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND TIME_MS >= 200 "
            . "AND INFO IS NOT NULL AND INFO NOT LIKE '%PROCESSLIST%'"
        )->fetchColumn() > 0;

        $b = self::verbindung();
        $b->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $repB = new ReparaturRepository($b);
        $dienstB = new \Emc\Reparatur\ReparaturDienst($b, $repB, new FahrzeugRepository($b), new \Emc\Anlagen\AnlageRepository($b), new DokumentRepository($b, $this->speicher), new Aenderungsprotokoll($b));
        $prozess = null;
        $rohre = [];
        $dienstB->inTransaktion('fahrzeug', $verein, null, function () use ($repB, $b, $s, $alt, &$prozess, &$rohre, $wartet): void {
            $prozess = proc_open(
                [PHP_BINARY, __DIR__ . '/dsgvo_loeschung_prozess.php', (string) $s['person'], (string) $this->admin, $this->speicher],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $rohre
            );
            $this->assertIsResource($prozess);
            $bis = microtime(true) + 15;
            while (!$wartet()) {
                $this->assertLessThan($bis, microtime(true), 'Die Löschung wartet nicht auf eine Sperre.');
                $this->assertTrue(proc_get_status($prozess)['running'], 'Die Löschung lief durch, ohne auf das Vereinsfahrzeug zu warten.');
                usleep(20000);
            }
            // Die Löschung steht an der Sperre des Vereinsfahrzeugs und hält die Person noch nicht.
            $this->assertTrue($repB->sperrePerson($s['person']));
            $b->prepare("UPDATE reparatur SET beschreibung = 'Motor brummt laut' WHERE id = ?")->execute([$alt]);
        });
        $ausgabe = stream_get_contents($rohre[1]) . stream_get_contents($rohre[2]);
        $code = proc_close($prozess);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertSame('OK', trim($ausgabe));
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM reparatur WHERE id = ? AND beschreibung = 'Motor brummt laut' AND zustaendig_person_id IS NULL", [$alt]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM fahrzeug WHERE id = ? AND angelegt_von_benutzer_id IS NULL', [$verein]));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM person WHERE id = ?', [$s['person']]));
    }

    // ---- Börse und Magazin ---------------------------------------------------------------------------------------

    /** @return array{angebote:\Emc\Boerse\AngebotDienst, kasse:\Emc\Boerse\KasseDienst, boersen:\Emc\Boerse\BoerseDienst, magazin:\Emc\Magazin\MagazinDienst, wart:\Emc\Auth\Benutzer} */
    private function boersenDienste(): array
    {
        $rep = new \Emc\Boerse\AngebotRepository($this->pdo);
        $magazinRep = new \Emc\Magazin\MagazinRepository($this->pdo);
        $magazin = new \Emc\Magazin\MagazinDienst($this->pdo, $magazinRep, $this->protokoll, $rep);
        $person = (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = {$this->admin}")->fetchColumn();
        return [
            'angebote' => new \Emc\Boerse\AngebotDienst($this->pdo, $rep, $magazinRep, $this->protokoll, new \Emc\Boerse\BoerseRepository($this->pdo)),
            'kasse' => new \Emc\Boerse\KasseDienst($this->pdo, $rep, $magazin, $this->protokoll),
            'boersen' => new \Emc\Boerse\BoerseDienst($this->pdo, new \Emc\Boerse\BoerseRepository($this->pdo), $rep, $this->protokoll),
            'magazin' => $magazin,
            'wart' => new \Emc\Auth\Benutzer($this->admin, $person, 'admin@verein.de', 'admin@verein.de', \Emc\Auth\Rolle::Admin, false, '', true),
        ];
    }

    private function boerseLaeuft(): int
    {
        $this->pdo->exec("INSERT INTO boerse (name, datum, status, provision_prozent) VALUES ('Herbstbörse', CURDATE(), 'laeuft', '10.00')");
        return (int) $this->pdo->lastInsertId();
    }

    private function mitgliedsAngebot(array $d, int $person, int $boerse, string $preis = '12.50', ?string $foto = null): int
    {
        $kat = $this->zaehle("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'");
        $id = $d['angebote']->anlegen([
            'bezeichnung' => 'BR 50 Märklin', 'beschreibung' => 'Aus der Sammlung von Carla Beispiel', 'kategorie_id' => $kat, 'spurweite_id' => null,
            'zustand' => 'gebraucht', 'wunschpreis' => $preis, 'menge' => 1, 'herkunft' => 'mitglied', 'person_id' => $person,
            'erloes_spende' => 0, 'boerse_id' => $boerse, 'notiz' => 'Carla ruft an, Tel. 0345 987654',
        ], $d['wart'], new \DateTimeImmutable());
        if ($foto !== null) {
            $dok = $this->dokument($foto, []);
            $this->dokumente->zuordnen($dok, 'boerse_angebot', $id);
            $this->pdo->exec("UPDATE boerse_angebot SET titelbild_dokument_id = $dok WHERE id = $id");
        }
        return $id;
    }

    /**
     * Börsenangebote der Person: nicht verkaufte werden samt Fotos und Protokoll gelöscht; verkaufte bleiben ohne
     * Personenbezug in der Abrechnung („ehemaliges Mitglied“, Auszahlungsbetrag unverändert), die vermerkte Auszahlung
     * ebenso. Ein Angebot mit storniertem Verkauf (Position verweist darauf, RESTRICT) bleibt zurückgezogen ohne
     * Fotos, Beschreibung und Notiz. Name in Freitexten des Magazins wird ersetzt.
     */
    public function test_angebote_der_person(): void
    {
        $d = $this->boersenDienste();
        $carla = $this->person();
        $this->konto($carla);
        $andere = $this->person('Eva', 'Exempel', 'eva@privat.de', null);
        $b = $this->boerseLaeuft();
        $offen = $this->mitgliedsAngebot($d, $carla, $b, '5.00', 'angebot-offen.jpg');
        $verkauft = $this->mitgliedsAngebot($d, $carla, $b, '12.50', 'angebot-verkauft.jpg');
        $storno = $this->mitgliedsAngebot($d, $carla, $b, '3.00', 'angebot-storno.jpg');
        $fremd = $this->mitgliedsAngebot($d, $andere, $b, '7.00');
        $d['kasse']->abschliessen($b, [['angebot_id' => $verkauft, 'preis' => '12.50']], 'bar', $this->admin, new \DateTimeImmutable());
        $v2 = $d['kasse']->abschliessen($b, [['angebot_id' => $storno, 'preis' => '3.00']], 'bar', $this->admin, new \DateTimeImmutable());
        $d['kasse']->storniere($v2, 'vertippt', $this->admin, new \DateTimeImmutable());
        $d['boersen']->vermerkeAuszahlung($b, $carla, date('Y-m-d'), $this->admin, new \DateTimeImmutable());
        // Kaufanfrage zum offenen Angebot (öffentlicher Bereich, ohne Mitgliedsbezug)
        $this->pdo->exec("INSERT INTO boerse_anfrage (art, angebot_id, name, email, nachricht, einwilligung_am, eingegangen_am) VALUES ('kaufanfrage', $offen, 'Rudi', 'rudi@test.emc78.de', 'Noch da?', NOW(), NOW())");
        $anfrage = (int) $this->pdo->lastInsertId();
        $artikel = $d['magazin']->legeAnMitEingang(['bezeichnung' => 'Fahrpult', 'kategorie_id' => $this->zaehle("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'")],
            ['datum' => date('Y-m-d'), 'menge' => 1, 'herkunft' => 'spende', 'freitext' => 'Spende von Carla Beispiel', 'betrag' => null], $this->admin);
        $vorher = (new \Emc\Boerse\AbrechnungRepository($this->pdo))->positionen($b);
        $auszahlungVorher = \Emc\Boerse\Abrechnung::berechne($vorher)['mitglieder'][0]['auszahlung'];
        $this->assertSame(1125, $auszahlungVorher);

        $this->dienst->loesche($carla, [], $this->admin);

        // nicht verkauft → gelöscht samt Foto (Datensatz, Dateien) und Protokoll
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM boerse_angebot WHERE id = ?', [$offen]));
        $this->assertFalse($this->dateienDa('angebot-offen.jpg'));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'boerse_angebot' AND objekt_id = ?", [$offen]));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'boerse_angebot' AND objekt_id = ?", [$offen]));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM boerse_anfrage WHERE id = ? AND angebot_id IS NULL', [$anfrage]));
        // verkauft → bleibt ohne Person, Abrechnung „ehemaliges Mitglied“ mit unverändertem Betrag
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM boerse_angebot WHERE id = ? AND person_id IS NULL AND status = 'verkauft'", [$verkauft]));
        $this->assertTrue($this->dateienDa('angebot-verkauft.jpg'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM boerse_verkauf_position WHERE person_id IS NOT NULL'));
        $r = \Emc\Boerse\Abrechnung::berechne((new \Emc\Boerse\AbrechnungRepository($this->pdo))->positionen($b));
        $this->assertSame([Aenderungsprotokoll::EHEMALIGES_MITGLIED], array_column($r['mitglieder'], 'name'));
        $this->assertSame($auszahlungVorher, $r['mitglieder'][0]['auszahlung']);
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM boerse_auszahlung WHERE boerse_id = ? AND person_id IS NULL AND betrag = '11.25'", [$b]));
        // stornierter Verkauf → Angebot bleibt (Position verweist darauf), zurückgezogen, ohne Fotos, Beschreibung, Notiz
        $z = $this->pdo->query("SELECT * FROM boerse_angebot WHERE id = $storno")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($z);
        $this->assertEquals(['person_id' => null, 'status' => 'zurueck', 'oeffentlich' => 'nein', 'beschreibung' => null, 'notiz' => null, 'titelbild_dokument_id' => null],
            array_intersect_key($z, array_flip(['person_id', 'status', 'oeffentlich', 'beschreibung', 'notiz', 'titelbild_dokument_id'])));
        $this->assertFalse($this->dateienDa('angebot-storno.jpg'));
        // fremdes Angebot unberührt
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM boerse_angebot WHERE id = ? AND person_id = ? AND status = 'angeboten'", [$fremd, $andere]));
        // Freitext im Magazin ohne Namen, Bestand unverändert
        $this->assertSame('Spende von ' . Aenderungsprotokoll::EHEMALIGES_MITGLIED, (string) $this->pdo->query("SELECT freitext FROM magazin_buchung WHERE artikel_id = $artikel AND art = 'eingang'")->fetchColumn());
        $this->assertSame(1, $this->zaehle('SELECT SUM(menge) FROM magazin_buchung WHERE artikel_id = ?', [$artikel]));
        // nirgends mehr Name oder Telefonnummer in Börse und Magazin
        foreach (['boerse_angebot' => ['bezeichnung', 'beschreibung', 'notiz'], 'magazin_buchung' => ['freitext'], 'boerse_verkauf' => ['storno_grund']] as $tabelle => $spalten) {
            foreach ($spalten as $spalte) {
                $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM $tabelle WHERE $spalte LIKE '%Carla Beispiel%' OR $spalte LIKE '%0345 987654%'"), "$tabelle.$spalte");
            }
        }
    }

    public function test_vorschau_zaehlt_angebote(): void
    {
        $d = $this->boersenDienste();
        $carla = $this->person();
        $b = $this->boerseLaeuft();
        $this->mitgliedsAngebot($d, $carla, $b);
        $this->mitgliedsAngebot($d, $carla, $b, '1.00');
        $verkauft = $this->mitgliedsAngebot($d, $carla, $b, '2.00');
        $storno = $this->mitgliedsAngebot($d, $carla, $b, '3.00');
        $d['kasse']->abschliessen($b, [['angebot_id' => $verkauft, 'preis' => '2.00']], 'bar', null, new \DateTimeImmutable());
        $v = $d['kasse']->abschliessen($b, [['angebot_id' => $storno, 'preis' => '3.00']], 'bar', null, new \DateTimeImmutable());
        $d['kasse']->storniere($v, 'vertippt', null, new \DateTimeImmutable());
        $d['magazin']->legeAnMitEingang(['bezeichnung' => 'Gleise', 'kategorie_id' => $this->zaehle("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'")],
            ['datum' => date('Y-m-d'), 'menge' => 2, 'herkunft' => 'nachlass', 'freitext' => 'Nachlass Beispiel, Carla', 'betrag' => null], null);

        $v = $this->dienst->vorschau($carla, $this->admin);

        $this->assertSame(2, $v['angebote_geloescht']);
        $this->assertSame(1, $v['angebote_verkauft']);
        $this->assertSame(1, $v['angebote_storniert']);
        $this->assertSame(9, $v['freitexte'], 'Buchung „Nachlass Beispiel, Carla“ sowie Beschreibung und Notiz der vier Angebote');
        $this->assertSame(4, $this->zaehle('SELECT COUNT(*) FROM boerse_angebot'), 'Vorschau ändert nichts');

        $sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung, $this->admin);
        $html = App::erzeuge(self::testConfig(), new Session($sitzung))->handle(new \Emc\Core\Request('GET', "/personen/$carla/dsgvo"))->body;
        $this->assertStringContainsString('2 nicht verkaufte Börsenangebote werden gelöscht, 1 verkaufte bleiben ohne Namen in der Abrechnung.', $html);
    }

    /** Vor Migration 017 gibt es keine Angebote: Vorschau und Löschung laufen trotzdem. */
    public function test_ohne_boersentabellen(): void
    {
        $carla = $this->person();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('RENAME TABLE boerse_angebot TO boerse_angebot_weg');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        try {
            $v = $this->dienst->vorschau($carla, $this->admin);
            $this->assertSame(0, $v['angebote_geloescht']);
            $this->dienst->loesche($carla, [], $this->admin);
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->pdo->exec('RENAME TABLE boerse_angebot_weg TO boerse_angebot');
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM person WHERE id = ?', [$carla]));
    }
}
