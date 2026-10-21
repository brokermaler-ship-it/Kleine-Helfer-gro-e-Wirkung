<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Reparatur;

use Emc\Anlagen\AnlageRepository;
use Emc\Dateien\DokumentRepository;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Fahrzeuge\KonfliktFehler;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturDienst;
use Emc\Reparatur\ReparaturRepository;
use Emc\Tests\Integration\DbTestCase;
use Emc\Tests\Integration\Dateien\UploadServiceTest;

final class ReparaturDienstTest extends DbTestCase
{
    private string $speicher;
    private ReparaturRepository $rep;
    private FahrzeugRepository $fahrzeuge;
    private AnlageRepository $anlagen;
    private DokumentRepository $dokumente;
    private ReparaturDienst $dienst;
    private int $benutzer;
    private int $h0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher . '/2026/10', 0750, true);
        $this->rep = new ReparaturRepository($this->pdo);
        $this->fahrzeuge = new FahrzeugRepository($this->pdo);
        $this->anlagen = new AnlageRepository($this->pdo);
        $this->dokumente = new DokumentRepository($this->pdo, $this->speicher);
        $this->dienst = new ReparaturDienst($this->pdo, $this->rep, $this->fahrzeuge, $this->anlagen, $this->dokumente, new Aenderungsprotokoll($this->pdo));
        $this->benutzer = $this->legeBenutzerAn('mitglied@verein.de');
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
    }

    private function fahrzeug(string $inventarnummer = 'EMC-0012'): int
    {
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) VALUES (?, ?, ?)')
            ->execute([$inventarnummer, 'V 200', $this->h0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function anlage(): int
    {
        $this->pdo->prepare('INSERT INTO anlage (nummer, name, spurweite_id) VALUES (?, ?, ?)')->execute([3, 'Clubanlage H0', $this->h0]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Meldet einen Defekt und wendet den automatischen Status an – in einer Transaktion wie beim Absenden. */
    private function melde(string $typ, int $objektId, string $prioritaet, string $beschreibung = 'Motor brummt'): int
    {
        $this->pdo->beginTransaction();
        $id = $this->rep->anlegen($typ, $objektId, ['beschreibung' => $beschreibung, 'wo_bauteil' => null, 'prioritaet' => $prioritaet], $this->benutzer);
        $this->dienst->wendeStatusAn($typ, $objektId, $this->benutzer);
        $this->pdo->commit();
        return $id;
    }

    /** Setzt den Status einer Reparatur (übrige Felder unverändert) und wendet den automatischen Status an. */
    private function setzeStatus(int $reparaturId, string $status, ?string $prioritaet = null): void
    {
        $r = $this->rep->finde($reparaturId);
        self::assertNotNull($r);
        $d = $this->daten($r, ['status' => $status, 'was_gemacht' => $status === 'erledigt' ? 'Motor getauscht' : $r['was_gemacht']]);
        if ($prioritaet !== null) {
            $d['prioritaet'] = $prioritaet;
        }
        $this->pdo->beginTransaction();
        $this->rep->aktualisieren($reparaturId, $d, $r['ersatzteile'], (string) $r['geaendert_am']);
        $this->dienst->wendeStatusAn((string) $r['objekt_typ'], (int) $r['objekt_id'], $this->benutzer);
        $this->pdo->commit();
    }

    /**
     * @param array<string,mixed> $r
     * @param array<string,mixed> $neu
     * @return array<string,mixed>
     */
    private function daten(array $r, array $neu = []): array
    {
        $d = [];
        foreach (['beschreibung', 'wo_bauteil', 'prioritaet', 'zustaendig_person_id', 'termin', 'status', 'notizen', 'was_gemacht'] as $f) {
            $d[$f] = $r[$f];
        }
        return $neu + $d;
    }

    /** @return array{0:string,1:int} Status und Kennzeichen */
    private function zustand(string $tabelle, int $id): array
    {
        $z = $this->pdo->query("SELECT status, status_automatisch FROM $tabelle WHERE id = $id")->fetch(\PDO::FETCH_NUM);
        return [(string) $z[0], (int) $z[1]];
    }

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    public function test_dringende_meldung_setzt_defekt_mit_protokoll(): void
    {
        $fz = $this->fahrzeug();
        $this->melde('fahrzeug', $fz, 'dringend');
        self::assertSame(['defekt', 1], $this->zustand('fahrzeug', $fz));
        $eintraege = $this->pdo->query("SELECT benutzer_id, aktion, aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND objekt_id = $fz")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $eintraege);
        self::assertSame($this->benutzer, (int) $eintraege[0]['benutzer_id']);
        self::assertSame('geaendert', $eintraege[0]['aktion']);
        self::assertSame(['status' => ['alt' => 'einsatzbereit', 'neu' => 'defekt (automatisch durch Reparatur)']], json_decode((string) $eintraege[0]['aenderungen'], true));
    }

    public function test_normale_meldung_aendert_nichts(): void
    {
        $fz = $this->fahrzeug();
        $this->melde('fahrzeug', $fz, 'normal');
        self::assertSame(['einsatzbereit', 0], $this->zustand('fahrzeug', $fz));
        self::assertSame(0, $this->zahl('SELECT COUNT(*) FROM aenderungsprotokoll'));
    }

    public function test_ablauf_defekt_in_wartung_einsatzbereit(): void
    {
        $fz = $this->fahrzeug();
        $erste = $this->melde('fahrzeug', $fz, 'dringend');
        $zweite = $this->melde('fahrzeug', $fz, 'normal', 'Licht flackert');
        self::assertSame(['defekt', 1], $this->zustand('fahrzeug', $fz));

        $this->setzeStatus($zweite, 'in_arbeit');
        self::assertSame(['defekt', 1], $this->zustand('fahrzeug', $fz), '„defekt“ hat Vorrang');

        $this->setzeStatus($erste, 'erledigt');
        self::assertSame(['in_wartung', 1], $this->zustand('fahrzeug', $fz));

        $this->setzeStatus($zweite, 'erledigt');
        self::assertSame(['einsatzbereit', 0], $this->zustand('fahrzeug', $fz));
        self::assertSame(3, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'fahrzeug' AND aenderungen LIKE '%(automatisch durch Reparatur)%'"));
    }

    public function test_verwerfen_und_herabstufen_setzen_zurueck(): void
    {
        $fz = $this->fahrzeug();
        $id = $this->melde('fahrzeug', $fz, 'dringend');
        $this->setzeStatus($id, 'gemeldet', 'normal');
        self::assertSame(['einsatzbereit', 0], $this->zustand('fahrzeug', $fz), 'herabgestuft');

        $this->setzeStatus($id, 'gemeldet', 'dringend');
        self::assertSame(['defekt', 1], $this->zustand('fahrzeug', $fz));
        $this->setzeStatus($id, 'verworfen');
        self::assertSame(['einsatzbereit', 0], $this->zustand('fahrzeug', $fz), 'verworfen');
    }

    public function test_von_hand_geaendert_bleibt(): void
    {
        $fz = $this->fahrzeug();
        $id = $this->melde('fahrzeug', $fz, 'dringend');
        $f = $this->fahrzeuge->finde($fz);
        $this->fahrzeuge->aktualisieren($fz, ['status' => 'ausgeliehen'] + $f, (string) $f['geaendert_am']);
        self::assertSame(['ausgeliehen', 0], $this->zustand('fahrzeug', $fz));

        $this->melde('fahrzeug', $fz, 'dringend', 'Noch ein Defekt');
        self::assertSame(['ausgeliehen', 0], $this->zustand('fahrzeug', $fz), 'ausgeliehen ist geschützt');

        foreach ($this->rep->idsFuerObjekt('fahrzeug', $fz) as $rid) {
            $this->setzeStatus($rid, 'erledigt');
        }
        self::assertSame(['ausgeliehen', 0], $this->zustand('fahrzeug', $fz));
        self::assertSame(2, count($this->rep->idsFuerObjekt('fahrzeug', $fz)));
        self::assertNotNull($this->rep->finde($id)['abgeschlossen_am']);
    }

    public function test_von_hand_defekt_bleibt_nach_erledigen(): void
    {
        $fz = $this->fahrzeug();
        $id = $this->melde('fahrzeug', $fz, 'dringend');
        // Von Hand auf „in Wartung“ und wieder auf „defekt“: jetzt von Hand gesetzt.
        foreach (['in_wartung', 'defekt'] as $status) {
            $f = $this->fahrzeuge->finde($fz);
            $this->fahrzeuge->aktualisieren($fz, ['status' => $status] + $f, (string) $f['geaendert_am']);
        }
        self::assertSame(['defekt', 0], $this->zustand('fahrzeug', $fz));
        $this->setzeStatus($id, 'erledigt');
        self::assertSame(['defekt', 0], $this->zustand('fahrzeug', $fz));
    }

    public function test_unveraendert_gespeichertes_formular_behaelt_kennzeichen(): void
    {
        $fz = $this->fahrzeug();
        $id = $this->melde('fahrzeug', $fz, 'dringend');
        $f = $this->fahrzeuge->finde($fz);
        $this->fahrzeuge->aktualisieren($fz, ['bezeichnung' => 'V 200 rot'] + $f, (string) $f['geaendert_am']);
        self::assertSame(['defekt', 1], $this->zustand('fahrzeug', $fz));
        $this->setzeStatus($id, 'erledigt');
        self::assertSame(['einsatzbereit', 0], $this->zustand('fahrzeug', $fz));
    }

    public function test_abstellgleis_loescht_kennzeichen(): void
    {
        $fz = $this->fahrzeug();
        $id = $this->melde('fahrzeug', $fz, 'dringend');
        $this->fahrzeuge->setzeAbgestellt($fz, true);
        self::assertSame(['ausgemustert', 0], $this->zustand('fahrzeug', $fz));
        $this->setzeStatus($id, 'erledigt');
        self::assertSame(['ausgemustert', 0], $this->zustand('fahrzeug', $fz));

        $zweiter = $this->fahrzeug('EMC-0013');
        $this->pdo->exec("UPDATE fahrzeug SET status = 'defekt', status_automatisch = 1 WHERE id = $zweiter");
        $this->fahrzeuge->setzeAbgestellt($zweiter, false);
        self::assertSame(['einsatzbereit', 0], $this->zustand('fahrzeug', $zweiter));
    }

    public function test_status_automatisch_aendert_stand(): void
    {
        $fz = $this->fahrzeug();
        $stand = (string) $this->fahrzeuge->finde($fz)['geaendert_am'];
        usleep(2000);
        $this->melde('fahrzeug', $fz, 'dringend');
        // Wer das Formular vor der Meldung geöffnet hat, bekommt beim Speichern den Konflikt (der Status hat sich geändert).
        $this->expectException(KonfliktFehler::class);
        $this->fahrzeuge->aktualisieren($fz, ['status' => 'einsatzbereit'] + $this->fahrzeuge->finde($fz), $stand);
    }

    public function test_anlage_defekt_in_arbeit_in_ordnung(): void
    {
        $a = $this->anlage();
        $id = $this->melde('anlage', $a, 'dringend');
        self::assertSame(['defekt', 1], $this->zustand('anlage', $a));
        $this->setzeStatus($id, 'in_arbeit', 'normal');
        self::assertSame(['in_arbeit', 1], $this->zustand('anlage', $a));
        $this->setzeStatus($id, 'erledigt');
        self::assertSame(['in_ordnung', 0], $this->zustand('anlage', $a));
        self::assertSame(3, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'anlage' AND objekt_id = $a"));
    }

    public function test_anlage_von_hand_geaendert_loescht_kennzeichen(): void
    {
        $a = $this->anlage();
        $id = $this->melde('anlage', $a, 'dringend');
        $anl = $this->anlagen->finde($a);
        $this->anlagen->aktualisieren($a, ['name' => 'Clubanlage'] + $anl, (string) $anl['geaendert_am']);
        self::assertSame(['defekt', 1], $this->zustand('anlage', $a), 'unverändert gespeichert');
        $anl = $this->anlagen->finde($a);
        $this->anlagen->aktualisieren($a, ['status' => 'in_arbeit'] + $anl, (string) $anl['geaendert_am']);
        self::assertSame(['in_arbeit', 0], $this->zustand('anlage', $a));
        $this->setzeStatus($id, 'erledigt');
        self::assertSame(['in_arbeit', 0], $this->zustand('anlage', $a));
    }

    public function test_repository_finde_ersatzteile_und_konflikt(): void
    {
        $fz = $this->fahrzeug();
        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('Erika', 'Beispiel')");
        $person = (int) $this->pdo->lastInsertId();
        $id = $this->melde('fahrzeug', $fz, 'normal');
        $r = $this->rep->finde($id);
        self::assertSame('gemeldet', $r['status']);
        self::assertSame('Max Muster', $r['gemeldet_von_name']);
        self::assertNull($r['zustaendig_name']);
        self::assertSame('0.00', $r['kosten_gesamt']);
        self::assertSame([], $r['ersatzteile']);
        self::assertNull($r['abgeschlossen_am']);
        self::assertNotNull($r['gemeldet_am']);

        $teile = [
            ['bezeichnung' => 'Haftreifen', 'artikelnummer' => '123', 'bezugsquelle' => 'Händler', 'kosten' => '12.50', 'bestellt' => 1],
            ['bezeichnung' => 'Schleifer', 'artikelnummer' => null, 'bezugsquelle' => null, 'kosten' => '7.25', 'bestellt' => 0],
            ['bezeichnung' => 'Schraube', 'artikelnummer' => null, 'bezugsquelle' => null, 'kosten' => null, 'bestellt' => 0],
        ];
        $this->rep->aktualisieren($id, $this->daten($r, ['zustaendig_person_id' => $person, 'termin' => '2026-11-01', 'status' => 'geplant']), $teile, (string) $r['geaendert_am']);
        $r2 = $this->rep->finde($id);
        self::assertSame('Erika Beispiel', $r2['zustaendig_name']);
        self::assertSame('19.75', $r2['kosten_gesamt']);
        self::assertSame(['Haftreifen', 'Schleifer', 'Schraube'], array_column($r2['ersatzteile'], 'bezeichnung'));
        self::assertSame('12.50', $r2['ersatzteile'][0]['kosten']);
        self::assertSame(1, (int) $r2['ersatzteile'][0]['bestellt']);
        self::assertNull($r2['abgeschlossen_am']);

        try {
            $this->rep->aktualisieren($id, $this->daten($r, ['beschreibung' => 'Zweiter']), [], (string) $r['geaendert_am']);
            self::fail('KonfliktFehler erwartet');
        } catch (KonfliktFehler) {
            $this->addToAssertionCount(1);
        }
        $r3 = $this->rep->finde($id);
        self::assertSame('Motor brummt', $r3['beschreibung']);
        self::assertCount(3, $r3['ersatzteile']);

        // Erledigen setzt abgeschlossen_am, Wiederöffnen leert es.
        $this->rep->aktualisieren($id, $this->daten($r3, ['status' => 'erledigt', 'was_gemacht' => 'Getauscht']), $r3['ersatzteile'], (string) $r3['geaendert_am']);
        $r4 = $this->rep->finde($id);
        self::assertNotNull($r4['abgeschlossen_am']);
        $this->rep->aktualisieren($id, $this->daten($r4, ['status' => 'in_arbeit']), $r4['ersatzteile'], (string) $r4['geaendert_am']);
        self::assertNull($this->rep->finde($id)['abgeschlossen_am']);

        // Bezüge fehlen (Konto bzw. Person gelöscht): Namen null.
        $this->pdo->exec("UPDATE reparatur SET gemeldet_von_benutzer_id = NULL, zustaendig_person_id = NULL WHERE id = $id");
        $r5 = $this->rep->finde($id);
        self::assertNull($r5['gemeldet_von_name']);
        self::assertNull($r5['zustaendig_name']);
        self::assertNull($this->rep->finde(999999));
    }

    public function test_repository_offene_und_fuer_objekt(): void
    {
        $fz = $this->fahrzeug();
        $alt = $this->melde('fahrzeug', $fz, 'normal', 'Alt');
        $this->setzeStatus($alt, 'erledigt');
        $dringend = $this->melde('fahrzeug', $fz, 'dringend', 'Dringend');
        $neu = $this->melde('fahrzeug', $fz, 'niedrig', 'Neu');
        $this->melde('anlage', $this->anlage(), 'dringend', 'Anlage');

        self::assertEqualsCanonicalizing(
            [['prioritaet' => 'dringend', 'status' => 'gemeldet'], ['prioritaet' => 'niedrig', 'status' => 'gemeldet']],
            $this->rep->offeneFuerObjekt('fahrzeug', $fz)
        );
        self::assertSame([$neu, $dringend, $alt], array_map(fn(array $z) => (int) $z['id'], $this->rep->fuerObjekt('fahrzeug', $fz)));
        self::assertSame([$alt, $dringend, $neu], $this->rep->idsFuerObjekt('fahrzeug', $fz));
    }

    public function test_entferne_fuer_objekt(): void
    {
        $fz = $this->fahrzeug();
        $anderes = $this->anlage();
        $id = $this->melde('fahrzeug', $fz, 'dringend');
        $bleibt = $this->melde('anlage', $anderes, 'normal');
        $r = $this->rep->finde($id);
        $this->rep->aktualisieren($id, $this->daten($r), [['bezeichnung' => 'Haftreifen', 'artikelnummer' => null, 'bezugsquelle' => null, 'kosten' => '4.90', 'bestellt' => 0]], (string) $r['geaendert_am']);
        $this->pdo->prepare('INSERT INTO reparatur_gesehen (benutzer_id, reparatur_id, gesehen_am) VALUES (?, ?, NOW())')->execute([$this->benutzer, $id]);
        $dok = $this->foto('defekt.jpg');
        $this->dokumente->zuordnen($dok, 'reparatur', $id);
        $geteilt = $this->foto('geteilt.jpg');
        $this->dokumente->zuordnen($geteilt, 'reparatur', $id);
        $this->dokumente->zuordnen($geteilt, 'fahrzeug', 999);

        $this->dienst->entferneFuerObjekt('fahrzeug', $fz);

        self::assertSame([$bleibt], array_map('intval', $this->pdo->query('SELECT id FROM reparatur')->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertSame(0, $this->zahl('SELECT COUNT(*) FROM ersatzteil'));
        self::assertSame(0, $this->zahl('SELECT COUNT(*) FROM reparatur_gesehen'));
        self::assertNull($this->dokumente->finde($dok));
        self::assertFalse(is_file($this->speicher . '/2026/10/defekt.jpg'));
        self::assertNotNull($this->dokumente->finde($geteilt), 'geteiltes Foto bleibt');
        self::assertSame(0, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'reparatur'"));
    }

    public function test_entferne_fuer_objekt_vor_migration_014(): void
    {
        $fz = $this->fahrzeug();
        $this->pdo->exec('DROP TABLE reparatur_gesehen');
        $this->pdo->exec('DROP TABLE ersatzteil');
        $this->pdo->exec('DROP TABLE reparatur');
        try {
            $this->dienst->entferneFuerObjekt('fahrzeug', $fz);
            $this->addToAssertionCount(1);
        } finally {
            self::setUpBeforeClass();
        }
    }

    private function foto(string $name): int
    {
        $pfad = '2026/10/' . $name;
        $this->pdo->prepare("INSERT INTO dokument (titel, kategorie, original_name, endung, mime, groesse, pfad) VALUES (?, 'foto', ?, 'jpg', 'image/jpeg', 3, ?)")
            ->execute([$name, $name, $pfad]);
        foreach (['', '.anzeige.jpg', '.vorschau.jpg'] as $endung) {
            file_put_contents($this->speicher . '/' . $pfad . $endung, 'abc');
        }
        return (int) $this->pdo->lastInsertId();
    }
}
