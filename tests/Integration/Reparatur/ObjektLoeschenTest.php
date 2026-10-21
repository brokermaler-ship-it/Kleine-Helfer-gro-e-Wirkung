<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Reparatur;

use Emc\Anlagen\AnlageRepository;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Session;
use Emc\Dateien\DokumentRepository;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturDienst;
use Emc\Reparatur\ReparaturFilter;
use Emc\Reparatur\ReparaturRepository;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Löschen eines Fahrzeugs bzw. einer Anlage und gleichzeitige Defektmeldungen: das Objekt wird zuerst gesperrt, eine
 * Meldung liegt also entweder vorher (und wird mitgelöscht) oder findet das Objekt nicht mehr (404). Reparaturen ohne
 * Objekt (aus älteren Versionen) zählen nirgends mit.
 */
final class ObjektLoeschenTest extends DbTestCase
{
    private string $speicher;
    private int $admin;
    private int $h0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher . '/2026/10', 0750, true);
        $this->admin = $this->legeBenutzerAn('admin@verein.de', rolle: 'admin');
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
    }

    private function objekt(string $typ): int
    {
        if ($typ === 'fahrzeug') {
            $this->pdo->prepare("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) VALUES ('EMC-0012', 'V 200', ?)")->execute([$this->h0]);
        } else {
            $this->pdo->prepare("INSERT INTO anlage (nummer, name, spurweite_id) VALUES (3, 'Clubanlage H0', ?)")->execute([$this->h0]);
        }
        return (int) $this->pdo->lastInsertId();
    }

    private function zaehle(string $sql, array $werte = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($werte);
        return (int) $st->fetchColumn();
    }

    /** @return iterable<string, array{string, string}> */
    public static function objekttypen(): iterable
    {
        yield 'Fahrzeug' => ['fahrzeug', 'fahrzeuge'];
        yield 'Anlage' => ['anlage', 'anlagen'];
    }

    /**
     * Eine Meldung hält die Sperre des Objekts (ReparaturDienst::inTransaktion), während ein zweiter Prozess das Objekt
     * löscht. Die Löschung wartet an der Sperre des Objekts, bevor sie Reparaturen aufräumt, und löscht danach auch die
     * eben festgeschriebene Meldung samt Ersatzteil – keine verwaiste Reparatur.
     */
    #[DataProvider('objekttypen')]
    public function test_gleichzeitige_meldung_wird_mitgeloescht(string $typ, string $pfad): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open fehlt');
        }
        $id = $this->objekt($typ);
        $beobachter = self::verbindung();
        // Wartet die Löschung gerade an einer Sperre auf dem Objekt? (PROCESSLIST: läuft seit mindestens 0,2 s.)
        $wartet = fn(): bool => (int) $beobachter->query(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND TIME_MS >= 200 AND INFO LIKE '%$typ%'"
        )->fetchColumn() > 0;

        $b = self::verbindung();
        $b->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $repB = new ReparaturRepository($b);
        $dienstB = new ReparaturDienst($b, $repB, new FahrzeugRepository($b), new AnlageRepository($b), new DokumentRepository($b, $this->speicher), new Aenderungsprotokoll($b));
        $prozess = null;
        $rohre = [];
        $neu = $dienstB->inTransaktion($typ, $id, null, function () use ($repB, $b, $typ, $id, $pfad, &$prozess, &$rohre, $wartet): int {
            $neu = $repB->anlegen($typ, $id, ['beschreibung' => 'Gleichzeitig gemeldet', 'prioritaet' => 'dringend'], null);
            $b->prepare("INSERT INTO ersatzteil (reparatur_id, bezeichnung) VALUES (?, 'Schleifer')")->execute([$neu]);
            $prozess = proc_open(
                [PHP_BINARY, __DIR__ . '/objekt_loeschen_prozess.php', $pfad, (string) $id, (string) $this->admin],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $rohre
            );
            $this->assertIsResource($prozess);
            $bis = microtime(true) + 15;
            while (!$wartet()) {
                $this->assertLessThan($bis, microtime(true), 'Die Löschung wartet nicht auf die Sperre des Objekts.');
                $this->assertTrue(proc_get_status($prozess)['running'], 'Die Löschung lief durch, ohne auf das Objekt zu warten.');
                usleep(20000);
            }
            return $neu;
        });
        $ausgabe = stream_get_contents($rohre[1]) . stream_get_contents($rohre[2]);
        $code = proc_close($prozess);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertSame('303', trim($ausgabe));
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM $typ"));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM reparatur WHERE id = ?', [$neu]));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM ersatzteil'));
    }

    /** Datensatz samt Datei (und Varianten) im Testspeicher, zugeordnet zu den angegebenen Objekten. */
    private function dokument(string $name, array $zuordnungen): int
    {
        $pfad = '2026/10/' . $name;
        $this->pdo->prepare(
            "INSERT INTO dokument (titel, kategorie, original_name, endung, mime, groesse, pfad) VALUES (?, 'foto', ?, 'jpg', 'image/jpeg', 3, ?)"
        )->execute([$name, $name, $pfad]);
        $id = (int) $this->pdo->lastInsertId();
        foreach (['', '.anzeige.jpg', '.vorschau.jpg'] as $endung) {
            file_put_contents($this->speicher . '/' . $pfad . $endung, 'abc');
        }
        $dok = new DokumentRepository($this->pdo, $this->speicher);
        foreach ($zuordnungen as [$typ, $objektId]) {
            $dok->zuordnen($id, $typ, $objektId);
        }
        return $id;
    }

    /**
     * Löschen in einer Transaktion unter der Sperre des Objekts: Reparaturen samt Fotos, Wartungen samt Fotos und
     * Dokumente des Objekts verschwinden (Dateien nach dem Commit), ein geteiltes Dokument verliert nur die Zuordnung.
     */
    #[DataProvider('objekttypen')]
    public function test_loeschen_raeumt_reparaturen_wartungen_und_dateien_auf(string $typ, string $pfad): void
    {
        $id = $this->objekt($typ);
        $this->pdo->prepare("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) VALUES ('EMC-0099', 'Andere Lok', ?)")->execute([$this->h0]);
        $andere = (int) $this->pdo->lastInsertId();
        $rep = (new ReparaturRepository($this->pdo))->anlegen($typ, $id, ['beschreibung' => 'Motor brummt', 'prioritaet' => 'normal'], null);
        $this->pdo->prepare("INSERT INTO ersatzteil (reparatur_id, bezeichnung) VALUES (?, 'Schleifer')")->execute([$rep]);
        $art = (int) $this->pdo->query("SELECT id FROM wartungsart ORDER BY id LIMIT 1")->fetchColumn();
        $wartung = (new \Emc\Wartung\WartungRepository($this->pdo))->eintragen($typ, $id, [
            'datum' => '2026-10-01', 'betriebsstunden' => null, 'stundenstand_bezug' => null, 'ausgefuehrt_von_person_id' => null, 'notiz' => null,
        ], [$art], null);
        $this->dokument('rep.jpg', [['reparatur', $rep]]);
        $this->dokument('wartung.jpg', [['wartung', $wartung]]);
        $this->dokument('eigen.jpg', [[$typ, $id]]);
        $geteilt = $this->dokument('geteilt.jpg', [[$typ, $id], ['fahrzeug', $andere]]);

        $sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung, $this->admin);
        $antwort = App::erzeuge(['speicher_pfad' => $this->speicher] + self::testConfig(), new Session($sitzung))
            ->handle(new Request('POST', "/$pfad/$id/loeschen", [], ['_csrf' => str_repeat('a', 64)]));

        $this->assertSame(303, $antwort->status);
        $this->assertSame(0, $this->zaehle("SELECT COUNT(*) FROM $typ WHERE id = ?", [$id]));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM reparatur'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM ersatzteil'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM wartung'));
        $this->assertSame([$geteilt], array_map('intval', $this->pdo->query('SELECT id FROM dokument')->fetchAll(\PDO::FETCH_COLUMN)));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM dokument_zuordnung'));
        foreach (['rep.jpg', 'wartung.jpg', 'eigen.jpg'] as $name) {
            $this->assertFileDoesNotExist($this->speicher . '/2026/10/' . $name);
            $this->assertFileDoesNotExist($this->speicher . '/2026/10/' . $name . '.vorschau.jpg');
        }
        $this->assertFileExists($this->speicher . '/2026/10/geteilt.jpg');
    }

    /** Nach dem Löschen findet eine Meldung das Objekt nicht mehr: 404, keine Reparatur. */
    #[DataProvider('objekttypen')]
    public function test_meldung_nach_dem_loeschen_ergibt_404(string $typ, string $pfad): void
    {
        $id = $this->objekt($typ);
        $sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung, $this->admin);
        $post = fn(string $p, array $d = []) => App::erzeuge(self::testConfig(), new Session($sitzung))
            ->handle(new Request('POST', $p, [], $d + ['_csrf' => str_repeat('a', 64)]));

        $this->assertSame(303, $post("/$pfad/$id/loeschen")->status);
        $dienst = new ReparaturDienst($this->pdo, new ReparaturRepository($this->pdo), new FahrzeugRepository($this->pdo), new AnlageRepository($this->pdo), new DokumentRepository($this->pdo, $this->speicher), new Aenderungsprotokoll($this->pdo));
        $this->assertNull($dienst->inTransaktion($typ, $id, null, fn(): int => 1));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM reparatur'));
    }

    /**
     * Reparaturen, deren Objekt es nicht mehr gibt (verwaist aus älteren Versionen), zählen weder als neue
     * Defektmeldung noch auf der Startseite, in der Liste „Reparaturen“ oder unter „Meine Meldungen“.
     */
    public function test_reparaturen_ohne_objekt_zaehlen_nicht(): void
    {
        $melder = $this->legeBenutzerAn('melder@verein.de');
        $rep = new ReparaturRepository($this->pdo);
        $fz = $this->objekt('fahrzeug');
        $anl = $this->objekt('anlage');
        $echt = $rep->anlegen('fahrzeug', $fz, ['beschreibung' => 'Motor brummt', 'prioritaet' => 'dringend'], $melder);
        $echtA = $rep->anlegen('anlage', $anl, ['beschreibung' => 'Weiche klemmt', 'prioritaet' => 'normal'], $melder);
        $rep->anlegen('fahrzeug', $fz + 1000, ['beschreibung' => 'Verwaist F', 'prioritaet' => 'dringend'], $melder);
        $rep->anlegen('anlage', $anl + 1000, ['beschreibung' => 'Verwaist A', 'prioritaet' => 'dringend'], $melder);

        $this->assertSame(2, $rep->anzahlNeu(true, true, $this->admin));
        $this->assertSame(1, $rep->anzahlNeu(true, false, $this->admin));
        $offen = $rep->offeneDefekte(5);
        $this->assertSame(2, $offen['anzahl']);
        $this->assertSame(1, $offen['dringend']);
        $ids = fn(array $zeilen): array => array_map(fn(array $z): int => (int) $z['id'], $zeilen);
        $this->assertSame([$echt, $echtA], $ids($offen['liste']));
        $this->assertSame([$echt, $echtA], $ids($rep->suche(new ReparaturFilter())));
        $this->assertSame([$echt, $echtA], $ids($rep->suche(new ReparaturFilter(ReparaturFilter::ALLE))));
        $this->assertEqualsCanonicalizing([$echt, $echtA], $ids($rep->vonBenutzer($melder)));

        // Auch auf den Seiten: Hinweis, Startseite, Reparaturen, Meine Meldungen.
        $sitzung = [];
        $this->sitzungAnmelden($sitzung, $this->admin);
        $get = fn(string $p, array &$s) => App::erzeuge(self::testConfig(), new Session($s))->handle(new Request('GET', $p));
        $start = $get('/', $sitzung)->body;
        $this->assertStringContainsString('2 neue Defektmeldungen', $start);
        $liste = $get('/reparaturen', $sitzung)->body;
        $this->assertStringNotContainsString('Verwaist', $liste);
        $this->assertStringNotContainsString('Verwaist', $start);
        $sitzungM = [];
        $this->sitzungAnmelden($sitzungM, $melder);
        $meine = $get('/meine-meldungen', $sitzungM)->body;
        $this->assertStringContainsString('Motor brummt', $meine);
        $this->assertStringNotContainsString('Verwaist', $meine);
    }

    /**
     * Sperrkonflikt beim Löschen (z. B. Verklemmung über ein geteiltes Dokument, 1213, oder Wartezeit überschritten,
     * 1205): nichts gelöscht, Meldung „Bitte erneut versuchen“ statt 500.
     */
    #[DataProvider('objekttypen')]
    public function test_sperrkonflikt_beim_loeschen_ergibt_meldung(string $typ, string $pfad): void
    {
        $id = $this->objekt($typ);
        $sitzung = ['_csrf' => str_repeat('a', 64)];
        $this->sitzungAnmelden($sitzung, $this->admin);
        $zweite = self::verbindung();
        $zweite->beginTransaction();
        $zweite->query("SELECT id FROM $typ WHERE id = $id FOR UPDATE")->fetchAll();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $app = App::erzeuge(['speicher_pfad' => $this->speicher] + self::testConfig(), new Session($sitzung));
            $app->container()->set('pdo', fn() => $this->pdo);
            $antwort = $app->handle(new Request('POST', "/$pfad/$id/loeschen", [], ['_csrf' => str_repeat('a', 64)]));
        } finally {
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 50');
            $zweite->rollBack();
        }
        $this->assertSame(303, $antwort->status);
        $this->assertSame(url("/$pfad/$id"), $antwort->headers['Location']);
        $texte = array_column(array_filter($sitzung['_meldungen'] ?? [], fn(array $m): bool => $m['typ'] === 'fehler'), 'text');
        $this->assertContains(($typ === 'fahrzeug' ? 'Das Fahrzeug' : 'Die Anlage') . ' konnte gerade nicht gelöscht werden. Bitte erneut versuchen.', $texte);
        $this->assertSame(1, $this->zaehle("SELECT COUNT(*) FROM $typ WHERE id = ?", [$id]));
        $this->assertFalse($this->pdo->inTransaction());
    }
}
