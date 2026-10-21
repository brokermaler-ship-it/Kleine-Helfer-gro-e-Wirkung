<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Boerse;

use Emc\Auth\Benutzer;
use Emc\Auth\Rolle;
use Emc\Boerse\AngebotDienst;
use Emc\Boerse\AngebotRepository;
use Emc\Boerse\BoerseRepository;
use Emc\Boerse\KasseDienst;
use Emc\Boerse\KasseFehler;
use Emc\Core\DomainFehler;
use Emc\Core\Transaktion;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\DbTestCase;

/** Kasse: Verkauf mit Magazinbuchung, Sammlung, kein Doppelverkauf (auch zwei Prozesse gleichzeitig), Storno am selben Tag. */
final class KasseDienstTest extends DbTestCase
{
    private const TAG = '2026-11-08 10:15:00';

    private AngebotRepository $rep;
    private AngebotDienst $angebote;
    private MagazinDienst $magazin;
    private MagazinRepository $magazinRep;
    private KasseDienst $kasse;
    private Benutzer $wart;
    private Benutzer $carla;
    private Benutzer $eva;
    private ?\PDO $zweite = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = new AngebotRepository($this->pdo);
        $this->magazinRep = new MagazinRepository($this->pdo);
        $protokoll = new Aenderungsprotokoll($this->pdo);
        $this->angebote = new AngebotDienst($this->pdo, $this->rep, $this->magazinRep, $protokoll, new BoerseRepository($this->pdo));
        $this->magazin = new MagazinDienst($this->pdo, $this->magazinRep, $protokoll, $this->rep);
        $this->kasse = new KasseDienst($this->pdo, $this->rep, $this->magazin, $protokoll);
        $this->wart = $this->benutzer('greta@verein.de', Rolle::Fahrzeugwart, true);
        $this->carla = $this->benutzer('carla@verein.de', Rolle::Mitglied, false);
        $this->eva = $this->benutzer('eva@verein.de', Rolle::Mitglied, false);
    }

    protected function tearDown(): void
    {
        if ($this->zweite !== null && $this->zweite->inTransaction()) {
            $this->zweite->rollBack();
        }
        $this->zweite = null;
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
    }

    private function benutzer(string $email, Rolle $rolle, bool $boersenwart): Benutzer
    {
        $id = $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle->value);
        $person = (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = $id")->fetchColumn();
        return new Benutzer($id, $person, $email, $email, $rolle, false, '', $boersenwart);
    }

    private function jetzt(string $zeit = self::TAG): \DateTimeImmutable
    {
        return new \DateTimeImmutable($zeit);
    }

    private function kategorie(): int
    {
        return (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'")->fetchColumn();
    }

    private function artikel(int $menge, string $name = 'Märklin C-Gleis 24188'): int
    {
        return $this->magazin->legeAnMitEingang(
            ['bezeichnung' => $name, 'kategorie_id' => $this->kategorie()],
            ['datum' => '2026-10-01', 'menge' => $menge, 'herkunft' => 'spende', 'freitext' => null, 'betrag' => null],
            $this->wart->id
        );
    }

    private function boerse(string $status = 'laeuft', string $provision = '10.00', string $datum = '2026-11-08'): int
    {
        $this->pdo->prepare("INSERT INTO boerse (name, datum, status, provision_prozent) VALUES ('Herbstbörse', ?, ?, ?)")->execute([$datum, $status, $provision]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Angebot über den AngebotDienst (Jahr 2026). */
    private function angebot(?int $boerseId, array $mehr = [], ?Benutzer $von = null): int
    {
        $von ??= $this->carla;
        $d = $mehr + [
            'bezeichnung' => 'BR 50 Märklin', 'beschreibung' => null, 'kategorie_id' => $this->kategorie(), 'spurweite_id' => null,
            'zustand' => 'gebraucht', 'wunschpreis' => '12.50', 'menge' => 1, 'herkunft' => 'mitglied', 'person_id' => $von->personId,
            'erloes_spende' => 0, 'boerse_id' => $boerseId, 'notiz' => null,
        ];
        return $this->angebote->anlegen($d, Rolle::Mitglied === $von->rolle && $d['herkunft'] === 'mitglied' ? $von : $this->wart, $this->jetzt('2026-10-20'));
    }

    private function vereinsAngebot(?int $boerseId, int $artikelId, string $preis = '20.00', int $menge = 1): int
    {
        return $this->angebot($boerseId, ['magazin_artikel_id' => $artikelId, 'herkunft' => 'verein', 'person_id' => null, 'wunschpreis' => $preis, 'menge' => $menge], $this->wart);
    }

    private function nummer(int $id): string
    {
        return (string) $this->rep->finde($id)['nummer'];
    }

    private function angebotStatus(int $id): string
    {
        return (string) $this->pdo->query("SELECT status FROM boerse_angebot WHERE id = $id")->fetchColumn();
    }

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function kassenFehler(callable $f): KasseFehler
    {
        try {
            $f();
        } catch (KasseFehler $e) {
            return $e;
        }
        $this->fail('KasseFehler erwartet');
    }

    public function test_verkauf_setzt_verkauft_und_bucht_magazin(): void
    {
        $b = $this->boerse();
        $artikel = $this->artikel(1);
        $verein = $this->vereinsAngebot($b, $artikel, '20.00');
        $mitglied = $this->angebot($b, ['wunschpreis' => '12.50']);
        $spende = $this->angebot($b, ['wunschpreis' => '8.00', 'erloes_spende' => 1, 'person_id' => $this->eva->personId], $this->eva);

        $verkauf = $this->kasse->abschliessen($b, [
            ['angebot_id' => $mitglied, 'preis' => '12.50'],
            ['angebot_id' => $verein, 'preis' => '20.00'],
            ['angebot_id' => $spende, 'preis' => '8.00'],
        ], 'bar', $this->wart->id, $this->jetzt());

        foreach ([$verein, $mitglied, $spende] as $id) {
            $this->assertSame('verkauft', $this->angebotStatus($id));
        }
        $v = $this->pdo->query("SELECT * FROM boerse_verkauf WHERE id = $verkauf")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['40.50', 'bar', self::TAG, (string) $this->wart->id, null], [$v['summe'], $v['zahlart'], $v['verkauft_am'], (string) $v['kassiert_von_benutzer_id'], $v['storniert_am']]);
        $pos = $this->pdo->query("SELECT angebot_id, preis, provision, herkunft, person_id, erloes_spende FROM boerse_verkauf_position WHERE verkauf_id = $verkauf ORDER BY angebot_id")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([
            [(string) $verein, '20.00', '0.00', 'verein', null, '0'],
            [(string) $mitglied, '12.50', '1.25', 'mitglied', (string) $this->carla->personId, '0'],
            [(string) $spende, '8.00', '0.00', 'mitglied', (string) $this->eva->personId, '1'],
        ], array_map(fn(array $z) => array_map(fn($w) => $w === null ? null : (string) $w, array_values($z)), $pos));

        $this->assertSame(0, $this->magazinRep->bestand($artikel));
        $buchung = $this->pdo->query("SELECT * FROM magazin_buchung WHERE artikel_id = $artikel AND art = 'ausgang'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['-1', 'verkauft_boerse', '20.00', $this->nummer($verein), '2026-11-08'], [(string) $buchung['menge'], $buchung['ausgang_art'], $buchung['betrag'], $buchung['freitext'], $buchung['datum']]);
        $this->assertSame($this->zahl("SELECT id FROM boerse_verkauf_position WHERE angebot_id = $verein"), (int) $buchung['verkauf_position_id']);
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'boerse_angebot' AND objekt_id = $mitglied AND aenderungen LIKE '%verkauft%'"));
    }

    public function test_provision_wird_festgeschrieben(): void
    {
        $b = $this->boerse('laeuft', '15.00');
        $a = $this->angebot($b, ['wunschpreis' => '3.35']);
        $v = $this->kasse->abschliessen($b, [['angebot_id' => $a, 'preis' => '3.35']], 'karte_ueberweisung', null, $this->jetzt());
        $this->pdo->exec("UPDATE boerse SET provision_prozent = 50 WHERE id = $b");
        $this->assertSame('0.50', $this->pdo->query("SELECT provision FROM boerse_verkauf_position WHERE verkauf_id = $v")->fetchColumn());
        $this->assertSame('karte_ueberweisung', $this->pdo->query("SELECT zahlart FROM boerse_verkauf WHERE id = $v")->fetchColumn());
    }

    public function test_sammlung_verkauf_bucht_alle_artikel(): void
    {
        $b = $this->boerse();
        $eins = $this->artikel(3, 'Gleise');
        $zwei = $this->artikel(1, 'Weichen');
        $sammlung = $this->magazin->legeSammlungAn('Nachlass', null, [$zwei, $eins], $this->wart->id);
        $angebot = $this->angebot($b, ['magazin_sammlung_id' => $sammlung, 'herkunft' => 'verein', 'person_id' => null, 'wunschpreis' => '45.00'], $this->wart);

        $this->kasse->abschliessen($b, [['angebot_id' => $angebot, 'preis' => '40.00']], 'bar', $this->wart->id, $this->jetzt());

        $this->assertSame('verkauft', $this->angebotStatus($angebot));
        $zeilen = $this->pdo->query("SELECT artikel_id, menge, betrag, ausgang_art FROM magazin_buchung WHERE art = 'ausgang' ORDER BY artikel_id")->fetchAll(\PDO::FETCH_NUM);
        $this->assertSame([[(string) $eins, '-3', '40.00', 'verkauft_boerse'], [(string) $zwei, '-1', '0.00', 'verkauft_boerse']], array_map(fn(array $z) => array_map('strval', $z), $zeilen));
        $this->assertSame([0, 0], [$this->magazinRep->bestand($eins), $this->magazinRep->bestand($zwei)]);
    }

    public function test_bereits_verkauft_nichts_gebucht(): void
    {
        $b = $this->boerse();
        $artikel = $this->artikel(2);
        $gleich = $this->angebot($b);
        $weiteres = $this->vereinsAngebot($b, $artikel, '5.00');
        $this->kasse->abschliessen($b, [['angebot_id' => $gleich, 'preis' => '12.50']], 'bar', null, $this->jetzt());

        $e = $this->kassenFehler(fn() => $this->kasse->abschliessen($b, [
            ['angebot_id' => $weiteres, 'preis' => '5.00'],
            ['angebot_id' => $gleich, 'preis' => '12.50'],
        ], 'bar', null, $this->jetzt()));

        $this->assertSame([$this->nummer($gleich) . ' ist bereits verkauft.'], $e->meldungen);
        $this->assertSame([$gleich], $e->angebotIds);
        $this->assertSame('angeboten', $this->angebotStatus($weiteres));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM boerse_verkauf'));
        $this->assertSame(2, $this->magazinRep->bestand($artikel));
    }

    public function test_alle_fehler_gesammelt(): void
    {
        $b = $this->boerse();
        $andere = $this->boerse();
        $fremd = $this->angebot($andere);
        $zurueck = $this->angebot($b);
        $this->angebote->setzeStatus($zurueck, 'zurueckziehen', $this->carla);
        $ok = $this->angebot($b);
        $e = $this->kassenFehler(fn() => $this->kasse->abschliessen($b, [
            ['angebot_id' => $ok, 'preis' => '1.00'],
            ['angebot_id' => $zurueck, 'preis' => '1.00'],
            ['angebot_id' => $fremd, 'preis' => '1.00'],
        ], 'bar', null, $this->jetzt()));
        $this->assertSame([$this->nummer($fremd) . ' gehört nicht zu dieser Börse.', $this->nummer($zurueck) . ' ist zurückgegeben.'], $e->meldungen);
        $this->assertSame([$fremd, $zurueck], $e->angebotIds);
        $this->assertSame('angeboten', $this->angebotStatus($ok));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_verkauf'));
    }

    public function test_fremde_boerse_und_nicht_laufend(): void
    {
        $b = $this->boerse();
        $zweite = $this->boerse();
        $vorbereitung = $this->boerse('in_vorbereitung');
        $fremd = $this->angebot($zweite);
        $dauerhaft = $this->angebot(null, ['wunschpreis' => '3.00']);
        $hier = $this->angebot($b);
        $lauf = (int) $this->rep->finde($hier)['laufnummer'];

        // pruefeHinzu: Kurznummer im Jahr des Börsendatums
        $this->assertSame($hier, (int) $this->kasse->pruefeHinzu($b, (string) $lauf)['id']);
        $this->assertSame($hier, (int) $this->kasse->pruefeHinzu($b, ' b2026-' . $lauf)['id']);
        $this->assertSame(['Kein Angebot mit der Nummer 999.'], $this->kassenFehler(fn() => $this->kasse->pruefeHinzu($b, '999'))->meldungen);
        $this->assertSame(['Kein Angebot mit der Nummer x1.'], $this->kassenFehler(fn() => $this->kasse->pruefeHinzu($b, 'x1'))->meldungen);
        $this->assertSame([$this->nummer($fremd) . ' gehört nicht zu dieser Börse.'], $this->kassenFehler(fn() => $this->kasse->pruefeHinzu($b, $this->nummer($fremd)))->meldungen);
        $this->assertSame([KasseDienst::LAEUFT_NICHT], $this->kassenFehler(fn() => $this->kasse->pruefeHinzu($vorbereitung, (string) $lauf))->meldungen);
        $this->assertSame('Die Börse läuft nicht – Verkauf nicht möglich.', KasseDienst::LAEUFT_NICHT);

        $this->assertSame([KasseDienst::LAEUFT_NICHT], $this->kassenFehler(fn() => $this->kasse->abschliessen($vorbereitung, [['angebot_id' => $dauerhaft, 'preis' => '3.00']], 'bar', null, $this->jetzt()))->meldungen);
        $this->assertSame([$this->nummer($fremd) . ' gehört nicht zu dieser Börse.'], $this->kassenFehler(fn() => $this->kasse->abschliessen($b, [['angebot_id' => $fremd, 'preis' => '1.00']], 'bar', null, $this->jetzt()))->meldungen);

        // Dauerhafte Angebote gehören zu jeder laufenden Börse.
        $this->assertSame($dauerhaft, (int) $this->kasse->pruefeHinzu($zweite, $this->nummer($dauerhaft))['id']);
        $v = $this->kasse->abschliessen($zweite, [['angebot_id' => $dauerhaft, 'preis' => '3.00']], 'bar', null, $this->jetzt());
        $this->assertSame((string) $zweite, (string) $this->pdo->query("SELECT boerse_id FROM boerse_verkauf WHERE id = $v")->fetchColumn());
        $this->assertSame([$this->nummer($dauerhaft) . ' ist bereits verkauft.'], $this->kassenFehler(fn() => $this->kasse->pruefeHinzu($b, $this->nummer($dauerhaft)))->meldungen);
    }

    public function test_leerer_warenkorb_und_zahlart(): void
    {
        $b = $this->boerse();
        $a = $this->angebot($b);
        $this->assertSame([KasseDienst::LEER], $this->kassenFehler(fn() => $this->kasse->abschliessen($b, [], 'bar', null, $this->jetzt()))->meldungen);
        $this->assertSame('Der Warenkorb ist leer.', KasseDienst::LEER);
        $this->assertSame(['Bitte die Zahlart wählen.'], $this->kassenFehler(fn() => $this->kasse->abschliessen($b, [['angebot_id' => $a, 'preis' => '1.00']], 'scheck', null, $this->jetzt()))->meldungen);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_verkauf'));
    }

    public function test_bestand_reicht_nicht(): void
    {
        $b = $this->boerse();
        $artikel = $this->artikel(1);
        $angebot = $this->vereinsAngebot($b, $artikel);
        $mitglied = $this->angebot($b);
        // Bestand an allen Diensten vorbei auf 0 (nur Test): die Kasse prüft nach der Sperre selbst.
        $this->pdo->exec("INSERT INTO magazin_buchung (artikel_id, art, datum, menge, ausgang_art, erstellt_am) VALUES ($artikel, 'ausgang', '2026-10-02', -1, 'sonstiges', NOW())");
        $e = $this->kassenFehler(fn() => $this->kasse->abschliessen($b, [['angebot_id' => $mitglied, 'preis' => '1.00'], ['angebot_id' => $angebot, 'preis' => '20.00']], 'bar', null, $this->jetzt()));
        $this->assertSame([$this->nummer($angebot) . ': Der Bestand im Magazin reicht nicht.'], $e->meldungen);
        $this->assertSame(['angeboten', 'angeboten'], [$this->angebotStatus($angebot), $this->angebotStatus($mitglied)]);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_verkauf'));
    }

    public function test_reserviert_ist_verkaufbar(): void
    {
        $b = $this->boerse();
        $a = $this->angebot($b);
        $this->angebote->setzeStatus($a, 'reservieren', $this->wart);
        $this->assertSame('reserviert', (string) $this->kasse->pruefeHinzu($b, $this->nummer($a))['status']);
        $this->kasse->abschliessen($b, [['angebot_id' => $a, 'preis' => '12.50']], 'bar', null, $this->jetzt());
        $this->assertSame('verkauft', $this->angebotStatus($a));
    }

    public function test_gesperrte_boerse(): void
    {
        $b = $this->boerse();
        $a = $this->angebot($b);
        $this->zweite = self::verbindung();
        $this->zweite->beginTransaction();
        $this->zweite->query("SELECT id FROM boerse WHERE id = $b FOR UPDATE")->fetchAll();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->kasse->abschliessen($b, [['angebot_id' => $a, 'preis' => '12.50']], 'bar', null, $this->jetzt());
            $this->fail('Sperrkonflikt erwartet');
        } catch (DomainFehler $e) {
            $this->assertNotInstanceOf(KasseFehler::class, $e);
            $this->assertSame(Transaktion::SPERRKONFLIKT, $e->getMessage());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame('angeboten', $this->angebotStatus($a));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_verkauf'));
    }

    /**
     * Zwei Kassen (zwei PHP-Prozesse mit eigener Verbindung) schließen denselben Warenkorb gleichzeitig ab: Eine dritte
     * Verbindung hält die Börse, bis der Hilfsprozess an ihrer Sperre wartet; dann gibt sie frei und der Test schließt
     * sofort selbst ab. Genau ein Verkauf, eine Magazinbuchung, die andere Kasse meldet „bereits verkauft“.
     */
    public function test_gleichzeitige_kassen_prozess(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open fehlt');
        }
        $b = $this->boerse();
        $artikel = $this->artikel(1);
        $verein = $this->vereinsAngebot($b, $artikel, '20.00');
        $mitglied = $this->angebot($b);
        $korb = [['angebot_id' => $verein, 'preis' => '20.00'], ['angebot_id' => $mitglied, 'preis' => '12.50']];

        $beobachter = self::verbindung();
        $wartet = fn(): bool => (int) $beobachter->query(
            "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND TIME_MS >= 100 "
            . "AND INFO LIKE 'SELECT * FROM boerse WHERE id = % FOR UPDATE'"
        )->fetchColumn() > 0;
        $this->zweite = self::verbindung();
        $this->zweite->beginTransaction();
        $this->zweite->query("SELECT id FROM boerse WHERE id = $b FOR UPDATE")->fetchAll();

        $rohre = [];
        $prozess = proc_open(
            [PHP_BINARY, __DIR__ . '/kasse_abschluss_prozess.php', (string) $b, (string) $this->wart->id, 'bar', $verein . ':20.00', $mitglied . ':12.50'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $rohre
        );
        $this->assertIsResource($prozess);
        $bis = microtime(true) + 15;
        while (!$wartet()) {
            $this->assertLessThan($bis, microtime(true), 'Der Hilfsprozess wartet nicht an der Sperre der Börse.');
            $this->assertTrue(proc_get_status($prozess)['running'], 'Der Hilfsprozess lief durch, ohne auf die Börse zu warten.');
            usleep(20000);
        }
        $this->zweite->rollBack(); // beide Kassen laufen jetzt gleichzeitig los
        $hier = 'OK';
        try {
            $this->kasse->abschliessen($b, $korb, 'bar', $this->wart->id, $this->jetzt());
        } catch (KasseFehler $e) {
            $hier = 'KASSE ' . implode(' ', $e->meldungen);
        }
        $ausgabe = trim(stream_get_contents($rohre[1]) . stream_get_contents($rohre[2]));
        $this->assertSame(0, proc_close($prozess), $ausgabe);

        $ergebnisse = [$hier, preg_replace('/^OK \d+$/', 'OK', $ausgabe)];
        sort($ergebnisse);
        $this->assertSame(['KASSE ' . $this->nummer($verein) . ' ist bereits verkauft. ' . $this->nummer($mitglied) . ' ist bereits verkauft.', 'OK'], $ergebnisse);
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM boerse_verkauf'));
        $this->assertSame(2, $this->zahl('SELECT COUNT(*) FROM boerse_verkauf_position'));
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM magazin_buchung WHERE art = 'ausgang'"));
        $this->assertSame(0, $this->magazinRep->bestand($artikel));
    }

    public function test_storno_selber_tag(): void
    {
        $b = $this->boerse();
        $artikel = $this->artikel(1);
        $verein = $this->vereinsAngebot($b, $artikel);
        $mitglied = $this->angebot($b);
        $v = $this->kasse->abschliessen($b, [['angebot_id' => $verein, 'preis' => '20.00'], ['angebot_id' => $mitglied, 'preis' => '12.50']], 'bar', null, $this->jetzt());

        $fehler = function (callable $f): string {
            try {
                $f();
            } catch (DomainFehler $e) {
                return $e->getMessage();
            }
            $this->fail('DomainFehler erwartet');
        };
        $this->assertSame('Bitte einen Grund für das Storno angeben.', $fehler(fn() => $this->kasse->storniere($v, '   ', $this->wart->id, $this->jetzt('2026-11-08 18:00'))));
        $this->assertSame('Stornieren geht nur am Tag des Verkaufs.', $fehler(fn() => $this->kasse->storniere($v, 'Falsch getippt', $this->wart->id, $this->jetzt('2026-11-09 08:00'))));
        $this->pdo->exec("INSERT INTO boerse_auszahlung (boerse_id, person_id, betrag, ausgezahlt_am) VALUES ($b, {$this->carla->personId}, '11.25', '2026-11-08')");
        $this->assertSame('Für ein Mitglied in diesem Verkauf ist schon eine Auszahlung vermerkt. Bitte den Haken zuerst entfernen.', $fehler(fn() => $this->kasse->storniere($v, 'Falsch getippt', $this->wart->id, $this->jetzt('2026-11-08 18:00'))));
        $this->pdo->exec('DELETE FROM boerse_auszahlung');
        $this->assertSame(['verkauft', 'verkauft'], [$this->angebotStatus($verein), $this->angebotStatus($mitglied)]);

        $this->kasse->storniere($v, 'Käufer hat es sich anders überlegt', $this->wart->id, $this->jetzt('2026-11-08 23:59'));

        $this->assertSame(['angeboten', 'angeboten'], [$this->angebotStatus($verein), $this->angebotStatus($mitglied)]);
        $this->assertSame(1, $this->magazinRep->bestand($artikel));
        $gegen = $this->pdo->query("SELECT * FROM magazin_buchung WHERE gegenbuchung_zu_id IS NOT NULL")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['ausgang', '1', 'verkauft_boerse'], [$gegen['art'], (string) $gegen['menge'], $gegen['ausgang_art']]);
        $z = $this->pdo->query("SELECT storniert_am, storno_grund, storniert_von_benutzer_id FROM boerse_verkauf WHERE id = $v")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['2026-11-08 23:59:00', 'Käufer hat es sich anders überlegt', (string) $this->wart->id], [$z['storniert_am'], $z['storno_grund'], (string) $z['storniert_von_benutzer_id']]);
        $this->assertSame('Der Verkauf ist schon storniert.', $fehler(fn() => $this->kasse->storniere($v, 'nochmal', $this->wart->id, $this->jetzt())));

        // Danach wieder verkaufbar.
        $this->kasse->abschliessen($b, [['angebot_id' => $verein, 'preis' => '18.00']], 'bar', null, $this->jetzt());
        $this->assertSame(0, $this->magazinRep->bestand($artikel));
    }

    public function test_storno_sammlung(): void
    {
        $b = $this->boerse();
        $eins = $this->artikel(3, 'Gleise');
        $zwei = $this->artikel(1, 'Weichen');
        $sammlung = $this->magazin->legeSammlungAn('Nachlass', null, [$eins, $zwei], $this->wart->id);
        $angebot = $this->angebot($b, ['magazin_sammlung_id' => $sammlung, 'herkunft' => 'verein', 'person_id' => null], $this->wart);
        $v = $this->kasse->abschliessen($b, [['angebot_id' => $angebot, 'preis' => '40.00']], 'bar', null, $this->jetzt());
        $this->kasse->storniere($v, 'Irrtum', null, $this->jetzt());
        $this->assertSame([3, 1], [$this->magazinRep->bestand($eins), $this->magazinRep->bestand($zwei)]);
        $this->assertSame('angeboten', $this->angebotStatus($angebot));
    }

    public function test_aufsteigend(): void
    {
        $this->assertSame([1, 3, 7], KasseDienst::aufsteigend([7, 3, 1, 3]));
        $this->assertSame([], KasseDienst::aufsteigend([]));
    }
}
