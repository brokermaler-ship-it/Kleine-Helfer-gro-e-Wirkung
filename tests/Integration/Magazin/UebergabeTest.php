<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Magazin;

use Emc\Core\App;
use Emc\Core\DomainFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\Transaktion;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\DbTestCase;

/** Übergabe eines ausgemusterten Vereinsfahrzeugs vom Abstellgleis ans Magazin. */
final class UebergabeTest extends DbTestCase
{
    private array $sitzung = [];
    private MagazinRepository $rep;
    private MagazinDienst $dienst;
    private int $h0;
    private ?\PDO $zweite = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sitzung = [];
        $this->rep = new MagazinRepository($this->pdo);
        $this->dienst = new MagazinDienst($this->pdo, $this->rep, new Aenderungsprotokoll($this->pdo));
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
    }

    protected function tearDown(): void
    {
        if ($this->zweite !== null && $this->zweite->inTransaction()) {
            $this->zweite->rollBack();
        }
        $this->zweite = null;
    }

    /** @return int Benutzer-ID */
    private function anmelden(string $rolle, bool $boersenwart = false): int
    {
        $email = $rolle . ($boersenwart ? '-bw' : '') . '@verein.de';
        $st = $this->pdo->prepare('SELECT id FROM benutzer WHERE email = ?');
        $st->execute([$email]);
        $id = (int) ($st->fetchColumn() ?: $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle));
        $this->pdo->prepare('UPDATE benutzer SET ist_boersenwart = ? WHERE id = ?')->execute([$boersenwart ? 1 : 0, $id]);
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        return $id;
    }

    private function get(string $pfad): Response
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung))->handle(new Request('GET', $pfad));
    }

    private function post(string $pfad, array $d = []): Response
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung))
            ->handle(new Request('POST', $pfad, [], $d + ['_csrf' => str_repeat('a', 64)]));
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

    private function fahrzeug(string $nummer = 'EMC-0042', string $status = 'ausgemustert', ?int $eigentuemerPerson = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO fahrzeug (inventarnummer, bezeichnung, hersteller, artikelnummer, spurweite_id, status, eigentuemer_person_id, ausgemustert_am) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$nummer, 'Köf II', 'Märklin', '3080', $this->h0, $status, $eigentuemerPerson, $status === 'ausgemustert' ? '2026-03-15' : null]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Foto (Datensatz ohne Dateien) am Fahrzeug, als Titelbild gesetzt. */
    private function titelbild(int $fz): int
    {
        $this->pdo->exec("INSERT INTO dokument (titel, kategorie, original_name, endung, mime, groesse, pfad, hat_anzeige) VALUES ('Köf', 'foto', 'koef.jpg', 'jpg', 'image/jpeg', 3, '2026/10/koef.jpg', 1)");
        $dok = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) VALUES ($dok, 'fahrzeug', $fz)");
        $this->pdo->exec("UPDATE fahrzeug SET titelbild_dokument_id = $dok WHERE id = $fz");
        return $dok;
    }

    private function fehlerVon(callable $f): ?string
    {
        try {
            $f();
        } catch (DomainFehler $e) {
            return $e->getMessage();
        }
        return null;
    }

    public function test_uebergabe_legt_artikel_an(): void
    {
        $benutzer = $this->legeBenutzerAn('greta@verein.de');
        $fz = $this->fahrzeug();
        $dok = $this->titelbild($fz);

        $id = $this->dienst->uebernimmFahrzeug($fz, 'Keller, Regal 7', $benutzer, new \DateTimeImmutable('2026-10-21 10:00'));

        $a = $this->rep->finde($id);
        $this->assertSame('fahrzeuge', $a['kategorie_schluessel']);
        $this->assertSame('EMC-0042 Köf II', $a['bezeichnung']);
        $this->assertSame('Märklin', $a['hersteller']);
        $this->assertSame('3080', $a['artikelnummer']);
        $this->assertSame($this->h0, (int) $a['spurweite_id']);
        $this->assertSame('gebraucht', $a['zustand']);
        $this->assertSame('Keller, Regal 7', $a['lagerort']);
        $this->assertSame($fz, (int) $a['fahrzeug_id']);
        $this->assertSame('EMC-0042', $a['fahrzeug_inventarnummer']);
        $this->assertSame(1, $a['bestand']);
        $this->assertSame($benutzer, (int) $a['angelegt_von_benutzer_id']);
        // Titelfoto: dasselbe Dokument, zusätzlich dem Artikel zugeordnet.
        $this->assertSame($dok, (int) $a['titelbild_dokument_id']);
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE dokument_id = $dok AND objekt_typ = 'magazin_artikel' AND objekt_id = $id"));
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE dokument_id = $dok AND objekt_typ = 'fahrzeug' AND objekt_id = $fz"));

        $eingang = $this->pdo->query("SELECT * FROM magazin_buchung WHERE artikel_id = $id AND art = 'eingang'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $eingang['menge']);
        $this->assertSame('sonstiges', $eingang['herkunft']);
        $this->assertSame('vom Abstellgleis', $eingang['freitext']);
        $this->assertSame('2026-10-21', $eingang['datum']);

        $this->assertSame('ausgemustert', $this->pdo->query("SELECT status FROM fahrzeug WHERE id = $fz")->fetchColumn());
        $protokoll = new Aenderungsprotokoll($this->pdo);
        $fzEintraege = $protokoll->fuerObjekt('fahrzeug', $fz);
        $this->assertSame(['alt' => null, 'neu' => 'ans Magazin übergeben (M' . $id . ')'], $fzEintraege[0]['aenderungen']['magazin']);
        $this->assertSame($benutzer, $this->zahl("SELECT benutzer_id FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz ORDER BY id DESC LIMIT 1"));
        $artikelEintraege = $protokoll->fuerObjekt('magazin_artikel', $id);
        $texte = json_encode(array_column($artikelEintraege, 'aenderungen'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('vom Abstellgleis: EMC-0042 Köf II', $texte);

        $this->assertSame(['id' => $id, 'code' => 'M' . $id, 'bestand' => 1, 'abgegeben_am' => null], $this->rep->fuerFahrzeug($fz));
        $this->assertNull($this->rep->fuerFahrzeug($fz + 1000));
    }

    public function test_ohne_titelbild_und_ohne_lagerort(): void
    {
        $fz = $this->fahrzeug();
        $id = $this->dienst->uebernimmFahrzeug($fz, '  ', null, new \DateTimeImmutable());
        $a = $this->rep->finde($id);
        $this->assertNull($a['titelbild_dokument_id']);
        $this->assertNull($a['lagerort']);
        $this->assertSame(1, $a['bestand']);
    }

    public function test_nicht_ausgemustert_privat_doppelt(): void
    {
        $jetzt = new \DateTimeImmutable();
        $aktiv = $this->fahrzeug('EMC-0001', 'einsatzbereit');
        $this->assertSame('Nur Fahrzeuge vom Abstellgleis können ans Magazin übergeben werden.', $this->fehlerVon(fn() => $this->dienst->uebernimmFahrzeug($aktiv, null, null, $jetzt)));

        $person = (int) $this->pdo->query('SELECT person_id FROM benutzer WHERE id = ' . $this->legeBenutzerAn('dieter@verein.de'))->fetchColumn();
        $privat = $this->fahrzeug('EMC-0002', 'ausgemustert', $person);
        $this->assertSame('Nur Vereinsfahrzeuge können ans Magazin übergeben werden.', $this->fehlerVon(fn() => $this->dienst->uebernimmFahrzeug($privat, null, null, $jetzt)));

        $verein = $this->fahrzeug('EMC-0003');
        $id = $this->dienst->uebernimmFahrzeug($verein, null, null, $jetzt);
        $this->assertSame('Das Fahrzeug ist schon im Magazin (M' . $id . ').', $this->fehlerVon(fn() => $this->dienst->uebernimmFahrzeug($verein, null, null, $jetzt)));
        // Auch nach dem Ausgang bleibt es dabei: ein Fahrzeug geht nur einmal ans Magazin.
        $this->dienst->buche($id, 'ausgang', ['datum' => date('Y-m-d'), 'menge' => 1, 'ausgang_art' => 'gegen_spende', 'freitext' => null, 'betrag' => null], null);
        $this->assertSame('Das Fahrzeug ist schon im Magazin (M' . $id . ').', $this->fehlerVon(fn() => $this->dienst->uebernimmFahrzeug($verein, null, null, $jetzt)));

        $this->assertSame('Dieses Fahrzeug gibt es nicht mehr.', $this->fehlerVon(fn() => $this->dienst->uebernimmFahrzeug(999999, null, null, $jetzt)));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function test_sperrt_zuerst_das_fahrzeug(): void
    {
        $fz = $this->fahrzeug();
        $this->zweite = self::verbindung();
        $this->zweite->beginTransaction();
        $this->zweite->query("SELECT id FROM fahrzeug WHERE id = $fz FOR UPDATE")->fetchAll();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->assertSame(Transaktion::SPERRKONFLIKT, $this->fehlerVon(fn() => $this->dienst->uebernimmFahrzeug($fz, null, null, new \DateTimeImmutable())));
        $this->zweite->rollBack();
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function test_ausgang_zeigt_abgegeben(): void
    {
        $fz = $this->fahrzeug();
        $this->anmelden('fahrzeugwart');
        $seite = $this->get("/fahrzeuge/$fz")->body;
        $this->assertStringContainsString('An Magazin übergeben', $seite);
        $this->assertStringContainsString(url("/fahrzeuge/$fz/an-magazin"), $seite);

        $r = $this->post("/fahrzeuge/$fz/an-magazin", ['lagerort' => 'Regal 3']);
        $id = $this->zahl('SELECT MAX(id) FROM magazin_artikel');
        $this->assertSame(303, $r->status);
        $this->assertSame(url("/magazin/$id"), $r->headers['Location']);
        $this->assertContains("Das Fahrzeug wurde als M$id ans Magazin übergeben.", $this->meldungen());
        $this->assertSame('Regal 3', $this->pdo->query("SELECT lagerort FROM magazin_artikel WHERE id = $id")->fetchColumn());

        $seite = $this->get("/fahrzeuge/$fz")->body;
        $this->assertStringContainsString('Im Magazin als', $seite);
        $this->assertStringContainsString('href="' . url("/magazin/$id") . '"', $seite);
        $this->assertStringNotContainsString(url("/fahrzeuge/$fz/an-magazin"), $seite);
        $this->assertStringNotContainsString('Zurück in Betrieb', $seite);
        $this->assertStringContainsString("M$id", $this->get('/abstellgleis')->body);

        $this->dienst->buche($id, 'ausgang', ['datum' => date('Y-m-d'), 'menge' => 1, 'ausgang_art' => 'gegen_spende', 'freitext' => 'an den Nachbarverein', 'betrag' => null], null);
        $seite = $this->get("/fahrzeuge/$fz")->body;
        $this->assertStringContainsString('abgegeben am ' . date('d.m.Y') . ' (Magazin M' . $id . ')', $seite);
        $this->assertStringNotContainsString('Im Magazin als', $seite);
        $this->assertSame(date('Y-m-d'), $this->rep->fuerFahrzeug($fz)['abgegeben_am']);
        $this->assertStringContainsString('abgegeben', $this->get('/abstellgleis')->body);

        // Zweite Übergabe über die Oberfläche: Meldung, kein zweiter Artikel.
        $this->sitzung['_meldungen'] = [];
        $r = $this->post("/fahrzeuge/$fz/an-magazin", ['lagerort' => '']);
        $this->assertSame(303, $r->status);
        $this->assertSame(url("/fahrzeuge/$fz"), $r->headers['Location']);
        $this->assertContains("Das Fahrzeug ist schon im Magazin (M$id).", $this->meldungen('fehler'));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
    }

    public function test_kein_block_bei_aktiven_und_privaten_fahrzeugen(): void
    {
        $aktiv = $this->fahrzeug('EMC-0001', 'einsatzbereit');
        $person = (int) $this->pdo->query('SELECT person_id FROM benutzer WHERE id = ' . $this->legeBenutzerAn('dieter@verein.de'))->fetchColumn();
        $privat = $this->fahrzeug('EMC-0002', 'ausgemustert', $person);
        $this->anmelden('admin');
        $this->assertStringNotContainsString('An Magazin übergeben', $this->get("/fahrzeuge/$aktiv")->body);
        $this->assertStringNotContainsString('An Magazin übergeben', $this->get("/fahrzeuge/$privat")->body);
        $this->post("/fahrzeuge/$aktiv/an-magazin");
        $this->assertContains('Nur Fahrzeuge vom Abstellgleis können ans Magazin übergeben werden.', $this->meldungen('fehler'));
        $this->assertSame(404, $this->post('/fahrzeuge/999999/an-magazin')->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
    }

    public function test_rechte(): void
    {
        $a = $this->fahrzeug('EMC-0001');
        $b = $this->fahrzeug('EMC-0002');
        $c = $this->fahrzeug('EMC-0003');

        $this->anmelden('mitglied');
        $this->assertStringNotContainsString('An Magazin übergeben', $this->get("/fahrzeuge/$a")->body);
        $this->assertSame(403, $this->post("/fahrzeuge/$a/an-magazin")->status);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));

        $this->anmelden('fahrzeugwart');
        $this->assertSame(303, $this->post("/fahrzeuge/$a/an-magazin")->status);
        $this->anmelden('mitglied', true);
        $this->assertStringContainsString('An Magazin übergeben', $this->get("/fahrzeuge/$b")->body);
        $this->assertSame(303, $this->post("/fahrzeuge/$b/an-magazin")->status);
        $this->anmelden('bearbeiter');
        $this->assertSame(303, $this->post("/fahrzeuge/$c/an-magazin")->status);
        $this->assertSame(3, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
    }

    public function test_zurueckholen_gesperrt(): void
    {
        $fz = $this->fahrzeug();
        $id = $this->dienst->uebernimmFahrzeug($fz, null, null, new \DateTimeImmutable());
        $this->anmelden('fahrzeugwart');
        $r = $this->post("/fahrzeuge/$fz/zurueckholen");
        $this->assertSame(303, $r->status);
        $this->assertContains("Das Fahrzeug wurde ans Magazin übergeben (M$id) und kann nicht zurückgeholt werden.", $this->meldungen('fehler'));
        $this->assertSame([], $this->meldungen());
        $this->assertSame('ausgemustert', $this->pdo->query("SELECT status FROM fahrzeug WHERE id = $fz")->fetchColumn());
        $this->assertNotNull($this->pdo->query("SELECT ausgemustert_am FROM fahrzeug WHERE id = $fz")->fetchColumn() ?: null);

        // Auch über das Formular lässt sich der Status nicht zurücksetzen.
        $stand = (string) $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = $fz")->fetchColumn();
        $r = $this->post("/fahrzeuge/$fz", [
            'bezeichnung' => 'Köf II', 'gattung' => 'lok', 'spurweite_id' => (string) $this->h0, 'stromsystem' => '2L',
            'status' => 'einsatzbereit', 'betriebsstunden' => '0', '_stand' => $stand,
        ]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString("Das Fahrzeug wurde ans Magazin übergeben (M$id) und bleibt auf dem Abstellgleis.", $r->body);
        $this->assertSame('ausgemustert', $this->pdo->query("SELECT status FROM fahrzeug WHERE id = $fz")->fetchColumn());
        $r = $this->post("/fahrzeuge/$fz", [
            'bezeichnung' => 'Köf II (Kö 4711)', 'gattung' => 'lok', 'spurweite_id' => (string) $this->h0, 'stromsystem' => '2L',
            'status' => 'ausgemustert', 'betriebsstunden' => '0', '_stand' => $stand,
        ]);
        $this->assertSame(303, $r->status);
    }

    public function test_fahrzeug_geloescht_artikel_bleibt(): void
    {
        $fz = $this->fahrzeug();
        $dok = $this->titelbild($fz);
        $id = $this->dienst->uebernimmFahrzeug($fz, null, null, new \DateTimeImmutable());
        $this->anmelden('admin');
        $this->assertSame(303, $this->post("/fahrzeuge/$fz/loeschen")->status);
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM fahrzeug WHERE id = $fz"));
        $a = $this->rep->finde($id);
        $this->assertNotNull($a);
        $this->assertNull($a['fahrzeug_id']);
        $this->assertSame(1, $a['bestand']);
        // Das geteilte Titelfoto bleibt am Artikel.
        $this->assertSame($dok, (int) $a['titelbild_dokument_id']);
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE dokument_id = $dok AND objekt_typ = 'magazin_artikel'"));
        $this->assertSame(200, $this->get("/magazin/$id")->status);
    }

    public function test_vor_dem_datenbank_update(): void
    {
        $fz = $this->fahrzeug();
        $this->anmelden('admin');
        $this->pdo->exec("DELETE FROM schema_migration WHERE version = '016_magazin'");
        try {
            $r = $this->post("/fahrzeuge/$fz/an-magazin");
            $this->assertSame(303, $r->status);
            $this->assertSame(url('/admin/update'), $r->headers['Location']);
            $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
        } finally {
            $this->pdo->exec("INSERT INTO schema_migration (version) VALUES ('016_magazin')");
        }

        // Fehlt die Tabelle noch, zeigt die Fahrzeugseite den Block einfach nicht.
        $this->pdo->exec('RENAME TABLE magazin_buchung TO magazin_buchung_weg');
        try {
            $r = $this->get("/fahrzeuge/$fz");
            $this->assertSame(200, $r->status);
            $this->assertStringNotContainsString('An Magazin übergeben', $r->body);
            $this->assertSame(200, $this->get('/abstellgleis')->status);
        } finally {
            $this->pdo->exec('RENAME TABLE magazin_buchung_weg TO magazin_buchung');
        }
    }

    /**
     * Die Übergabe ist eine Zustandsänderung des Fahrzeugs: ein vorher geöffnetes Formular ergibt 409. Danach bleibt
     * das Fahrzeug Eigentum des Vereins (kein Wechsel auf eine Person über das Formular).
     */
    public function test_uebergabe_aendert_stand_und_sperrt_eigentuemer(): void
    {
        $fz = $this->fahrzeug();
        $alterStand = (string) $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = $fz")->fetchColumn();
        usleep(2000);
        $id = $this->dienst->uebernimmFahrzeug($fz, null, null, new \DateTimeImmutable());
        $neuerStand = (string) $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE id = $fz")->fetchColumn();
        $this->assertNotSame($alterStand, $neuerStand);

        $this->anmelden('admin');
        $formular = [
            'bezeichnung' => 'Köf II (Kö 4711)', 'gattung' => 'lok', 'spurweite_id' => (string) $this->h0, 'stromsystem' => '2L',
            'status' => 'ausgemustert', 'betriebsstunden' => '0',
        ];
        $r = $this->post("/fahrzeuge/$fz", $formular + ['_stand' => $alterStand]);
        $this->assertSame(409, $r->status);
        $this->assertSame('Köf II', $this->pdo->query("SELECT bezeichnung FROM fahrzeug WHERE id = $fz")->fetchColumn());

        $person = (int) $this->pdo->query('SELECT person_id FROM benutzer WHERE id = ' . $this->legeBenutzerAn('dieter@verein.de'))->fetchColumn();
        $r = $this->post("/fahrzeuge/$fz", $formular + ['_stand' => $neuerStand, 'eigentuemer_person_id' => (string) $person]);
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString("Das Fahrzeug wurde ans Magazin übergeben (M$id) und bleibt Eigentum des Vereins.", $r->body);
        $this->assertNull($this->pdo->query("SELECT eigentuemer_person_id FROM fahrzeug WHERE id = $fz")->fetchColumn() ?: null);
    }

    /** Nur die verletzte UNIQUE-Regel (1062) wird zur Meldung „schon im Magazin“; andere Integritätsfehler nicht. */
    public function test_andere_integritaetsfehler_werden_nicht_zu_schon_im_magazin(): void
    {
        $fz = $this->fahrzeug();
        try {
            $this->dienst->uebernimmFahrzeug($fz, null, 999999, new \DateTimeImmutable()); // Benutzer gibt es nicht (FK 1452)
            $this->fail('PDOException erwartet');
        } catch (\PDOException $e) {
            $this->assertSame(1452, (int) ($e->errorInfo[1] ?? 0));
        }
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
    }

    /** Das Titelbild wird ohne INSERT IGNORE übernommen: doppelt ist harmlos, ein fehlendes Dokument ein Fehler. */
    public function test_titelbild_ohne_insert_ignore(): void
    {
        $fz = $this->fahrzeug();
        $dok = $this->titelbild($fz);
        $id = $this->dienst->uebernimmFahrzeug($fz, null, null, new \DateTimeImmutable());
        $this->rep->uebernimmTitelbild($id, $dok); // zweites Mal: keine Ausnahme, keine zweite Zuordnung
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE dokument_id = $dok AND objekt_typ = 'magazin_artikel'"));
        $this->expectException(\PDOException::class);
        $this->rep->uebernimmTitelbild($id, 999999);
    }

    /** Ein gegengebuchter Ausgang zählt nicht als „abgegeben am“. */
    public function test_abgegeben_ohne_gegengebuchten_ausgang(): void
    {
        $fz = $this->fahrzeug();
        $id = $this->dienst->uebernimmFahrzeug($fz, null, null, new \DateTimeImmutable());
        $ausgang = $this->dienst->buche($id, 'ausgang', ['datum' => '2026-05-01', 'menge' => 1, 'ausgang_art' => 'gegen_spende', 'freitext' => null, 'betrag' => null], null);
        $this->dienst->gegenbuchung($ausgang, null);
        $eingang = $this->zahl("SELECT id FROM magazin_buchung WHERE artikel_id = $id AND art = 'eingang'");
        $this->dienst->gegenbuchung($eingang, null);
        $info = $this->rep->fuerFahrzeug($fz);
        $this->assertSame(0, $info['bestand']);
        $this->assertNull($info['abgegeben_am']);
    }
}
