<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Boerse;

use Emc\Auth\Benutzer;
use Emc\Auth\Rolle;
use Emc\Boerse\Abrechnung;
use Emc\Boerse\AbrechnungRepository;
use Emc\Boerse\AngebotDienst;
use Emc\Boerse\AngebotRepository;
use Emc\Boerse\BoerseDienst;
use Emc\Boerse\BoerseRepository;
use Emc\Boerse\KasseDienst;
use Emc\Boerse\KasseFehler;
use Emc\Core\DomainFehler;
use Emc\Core\Transaktion;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\DbTestCase;

/** Abschluss und Wiederöffnen einer Börse, Schreibschutz danach, Auszahlungen unter der Börsensperre, Abrechnung. */
final class BoerseAbschlussTest extends DbTestCase
{
    private const TAG = '2026-11-08 10:15:00';

    private AngebotRepository $rep;
    private AngebotDienst $angebote;
    private MagazinDienst $magazin;
    private MagazinRepository $magazinRep;
    private KasseDienst $kasse;
    private BoerseDienst $boersen;
    private AbrechnungRepository $abrechnung;
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
        $this->boersen = new BoerseDienst($this->pdo, new BoerseRepository($this->pdo), $this->rep, $protokoll);
        $this->abrechnung = new AbrechnungRepository($this->pdo);
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

    private function artikel(int $menge): int
    {
        return $this->magazin->legeAnMitEingang(
            ['bezeichnung' => 'Märklin C-Gleis 24188', 'kategorie_id' => $this->kategorie()],
            ['datum' => '2026-10-01', 'menge' => $menge, 'herkunft' => 'spende', 'freitext' => null, 'betrag' => null],
            $this->wart->id
        );
    }

    private function boerse(string $status = 'laeuft', string $provision = '10.00'): int
    {
        $this->pdo->prepare("INSERT INTO boerse (name, datum, status, provision_prozent) VALUES ('Herbstbörse', '2026-11-08', ?, ?)")->execute([$status, $provision]);
        return (int) $this->pdo->lastInsertId();
    }

    private function angebot(?int $boerseId, array $mehr = [], ?Benutzer $von = null): int
    {
        $von ??= $this->carla;
        $d = $mehr + [
            'bezeichnung' => 'BR 50 Märklin', 'beschreibung' => null, 'kategorie_id' => $this->kategorie(), 'spurweite_id' => null,
            'zustand' => 'gebraucht', 'wunschpreis' => '12.50', 'menge' => 1, 'herkunft' => 'mitglied', 'person_id' => $von->personId,
            'erloes_spende' => 0, 'boerse_id' => $boerseId, 'notiz' => null,
        ];
        return $this->angebote->anlegen($d, $d['herkunft'] === 'mitglied' && $von->rolle === Rolle::Mitglied ? $von : $this->wart, $this->jetzt('2026-10-20'));
    }

    private function vereinsAngebot(?int $boerseId, int $artikelId, string $preis = '20.00'): int
    {
        return $this->angebot($boerseId, ['magazin_artikel_id' => $artikelId, 'herkunft' => 'verein', 'person_id' => null, 'wunschpreis' => $preis], $this->wart);
    }

    private function angebotStatus(int $angebotId): string
    {
        return (string) $this->pdo->query("SELECT status FROM boerse_angebot WHERE id = $angebotId")->fetchColumn();
    }

    private function boerseZeile(int $id): array
    {
        return $this->pdo->query("SELECT status, abgeschlossen_am FROM boerse WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
    }

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function fehler(callable $f): DomainFehler
    {
        try {
            $f();
        } catch (DomainFehler $e) {
            return $e;
        }
        $this->fail('DomainFehler erwartet');
    }

    public function test_abschluss_gibt_zurueck(): void
    {
        $b = $this->boerse();
        $andere = $this->boerse();
        $artikel = $this->artikel(3);
        $verein = $this->vereinsAngebot($b, $artikel);
        $reserviert = $this->angebot($b);
        $this->angebote->setzeStatus($reserviert, 'reservieren', $this->wart);
        $verkauft = $this->angebot($b, ['wunschpreis' => '5.00']);
        $dauerhaft = $this->angebot(null);
        $fremd = $this->angebot($andere);
        $this->kasse->abschliessen($b, [['angebot_id' => $verkauft, 'preis' => '5.00']], 'bar', null, $this->jetzt());
        $buchungen = $this->zahl('SELECT COUNT(*) FROM magazin_buchung');

        $n = $this->boersen->abschliessen($b, $this->wart->id, $this->jetzt('2026-11-08 17:30:00'));

        $this->assertSame(2, $n);
        $this->assertSame(['zurueck', 'zurueck', 'verkauft', 'angeboten', 'angeboten'], array_map($this->angebotStatus(...), [$verein, $reserviert, $verkauft, $dauerhaft, $fremd]));
        $this->assertSame(['status' => 'abgeschlossen', 'abgeschlossen_am' => '2026-11-08 17:30:00'], $this->boerseZeile($b));
        $this->assertSame(3, $this->magazinRep->bestand($artikel), 'Magazinbestand unverändert');
        $this->assertSame($buchungen, $this->zahl('SELECT COUNT(*) FROM magazin_buchung'), 'keine Buchung beim Abschluss');
        $this->assertSame(0, $this->rep->offeneMengeFuerArtikel($artikel), 'das Stück ist im Magazin wieder frei');
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'boerse' AND objekt_id = $b AND aenderungen LIKE '%abgeschlossen%'"));
        $this->assertSame(1, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'boerse_angebot' AND objekt_id = $verein AND aenderungen LIKE '%zurueck%'"));

        $e = $this->fehler(fn() => $this->boersen->abschliessen($b, null, $this->jetzt()));
        $this->assertSame(BoerseDienst::NUR_LAUFENDE, $e->getMessage());
        $this->assertSame('Nur eine laufende Börse lässt sich abschließen.', BoerseDienst::NUR_LAUFENDE);
        $this->assertSame(BoerseDienst::NUR_LAUFENDE, $this->fehler(fn() => $this->boersen->abschliessen($this->boerse('in_vorbereitung'), null, $this->jetzt()))->getMessage());
    }

    public function test_nach_abschluss_gesperrt(): void
    {
        $b = $this->boerse();
        $verkauft = $this->angebot($b);
        $offen = $this->angebot($b, ['wunschpreis' => '3.00']);
        $verkauf = $this->kasse->abschliessen($b, [['angebot_id' => $verkauft, 'preis' => '12.50']], 'bar', null, $this->jetzt());
        $this->boersen->abschliessen($b, null, $this->jetzt());

        // Kasse: ein alter Warenkorb dieser Börse
        try {
            $this->kasse->abschliessen($b, [['angebot_id' => $offen, 'preis' => '3.00']], 'bar', null, $this->jetzt());
            $this->fail('KasseFehler erwartet');
        } catch (KasseFehler $e) {
            $this->assertSame([KasseDienst::LAEUFT_NICHT], $e->meldungen);
        }
        $this->assertSame(AngebotDienst::BOERSE_ABGESCHLOSSEN, $this->fehler(fn() => $this->kasse->storniere($verkauf, 'Vertippt', null, $this->jetzt()))->getMessage());
        $a = $this->rep->finde($offen);
        $d = ['bezeichnung' => 'Neu', 'beschreibung' => null, 'kategorie_id' => $this->kategorie(), 'spurweite_id' => null, 'zustand' => 'gebraucht',
            'wunschpreis' => '4.00', 'menge' => 1, 'herkunft' => 'mitglied', 'person_id' => $this->carla->personId, 'erloes_spende' => 0, 'boerse_id' => $b, 'notiz' => null];
        $this->assertSame(AngebotDienst::BOERSE_ABGESCHLOSSEN, $this->fehler(fn() => $this->angebote->aendern($offen, $d, (string) $a['geaendert_am'], $this->wart))->getMessage());
        $this->assertSame(AngebotDienst::BOERSE_ABGESCHLOSSEN, $this->fehler(fn() => $this->boersen->aendern($b, ['name' => 'X', 'datum' => '2026-11-08', 'ort' => null, 'provision_prozent' => '10.00'], (string) $this->pdo->query("SELECT geaendert_am FROM boerse WHERE id = $b")->fetchColumn(), null))->getMessage());

        $this->assertSame(['verkauft', 'zurueck'], [$this->angebotStatus($verkauft), $this->angebotStatus($offen)]);
        $this->assertSame('BR 50 Märklin', $this->rep->finde($offen)['bezeichnung']);
        $this->assertNull($this->pdo->query("SELECT storniert_am FROM boerse_verkauf WHERE id = $verkauf")->fetchColumn());
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM boerse_verkauf'));

        // Auszahlung vermerken geht weiter
        $this->boersen->vermerkeAuszahlung($b, $this->carla->personId, '2026-11-10', $this->wart->id);
        $this->assertSame(['11.25', '2026-11-10'], array_values($this->pdo->query("SELECT betrag, ausgezahlt_am FROM boerse_auszahlung WHERE boerse_id = $b")->fetch(\PDO::FETCH_ASSOC)));
    }

    public function test_abschluss_wartet_auf_kasse(): void
    {
        $b = $this->boerse();
        $a = $this->angebot($b);
        $this->zweite = self::verbindung();
        $this->zweite->beginTransaction();
        $this->zweite->query("SELECT id FROM boerse WHERE id = $b FOR UPDATE")->fetchAll();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $e = $this->fehler(fn() => $this->boersen->abschliessen($b, null, $this->jetzt()));

        $this->assertSame(Transaktion::SPERRKONFLIKT, $e->getMessage());
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame('laeuft', $this->boerseZeile($b)['status']);
        $this->assertSame('angeboten', $this->angebotStatus($a));
    }

    public function test_wieder_oeffnen(): void
    {
        $b = $this->boerse();
        $a = $this->angebot($b);
        $this->assertSame(BoerseDienst::NUR_ABGESCHLOSSENE, $this->fehler(fn() => $this->boersen->wiederOeffnen($b, null))->getMessage());
        $this->boersen->abschliessen($b, null, $this->jetzt());

        $this->boersen->wiederOeffnen($b, $this->wart->id);

        $this->assertSame(['status' => 'laeuft', 'abgeschlossen_am' => null], $this->boerseZeile($b));
        $this->assertSame('zurueck', $this->angebotStatus($a), 'Angebote bleiben zurück');
        $this->assertSame(2, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'boerse' AND objekt_id = $b AND aenderungen LIKE '%abgeschlossen%'"), 'Abschluss und Wiederöffnen im Protokoll');
        // danach wieder anbieten und verkaufen
        $this->angebote->setzeStatus($a, 'wieder_anbieten', $this->wart);
        $this->kasse->abschliessen($b, [['angebot_id' => $a, 'preis' => '12.50']], 'bar', null, $this->jetzt());
        $this->assertSame('verkauft', $this->angebotStatus($a));
    }

    public function test_stornierte_zaehlen_nicht(): void
    {
        $b = $this->boerse();
        $artikel = $this->artikel(1);
        $verein = $this->vereinsAngebot($b, $artikel, '20.00');
        $mitglied = $this->angebot($b);
        $bleibt = $this->angebot($b, ['wunschpreis' => '0.05']);
        $storno = $this->kasse->abschliessen($b, [['angebot_id' => $verein, 'preis' => '20.00'], ['angebot_id' => $mitglied, 'preis' => '12.50']], 'bar', null, $this->jetzt());
        $this->kasse->abschliessen($b, [['angebot_id' => $bleibt, 'preis' => '0.05']], 'karte_ueberweisung', null, $this->jetzt());
        $this->kasse->storniere($storno, 'Käufer hat es sich anders überlegt', null, $this->jetzt('2026-11-08 11:00:00'));

        $positionen = $this->abrechnung->positionen($b);
        $this->assertCount(1, $positionen);
        $this->assertSame(['preis' => '0.05', 'provision' => '0.01', 'herkunft' => 'mitglied', 'person_id' => $this->carla->personId, 'erloes_spende' => false, 'zahlart' => 'karte_ueberweisung'],
            array_intersect_key($positionen[0], array_flip(['preis', 'provision', 'herkunft', 'person_id', 'erloes_spende', 'zahlart'])));
        $r = Abrechnung::berechne($positionen);
        $this->assertSame(['vereinsstuecke' => 0, 'provisionen' => 1, 'spenden' => 0, 'gesamt' => 1], $r['verein'], 'Kassen-Ausgang und Gegenbuchung im Magazin zählen nicht');
        $this->assertSame(['bar' => 0, 'karte_ueberweisung' => 5], $r['zahlarten']);
        $this->assertSame(4, $r['mitglieder'][0]['auszahlung']);
        $this->assertTrue($r['kontrolle']);
    }

    public function test_auszahlung_vermerken_und_entfernen(): void
    {
        $b = $this->boerse();
        $a1 = $this->angebot($b);
        $a2 = $this->angebot($b, ['wunschpreis' => '0.05']);
        $spende = $this->angebot($b, ['wunschpreis' => '8.00', 'erloes_spende' => 1, 'person_id' => $this->eva->personId], $this->eva);
        $verkauf = $this->kasse->abschliessen($b, [['angebot_id' => $a1, 'preis' => '12.50'], ['angebot_id' => $a2, 'preis' => '0.05'], ['angebot_id' => $spende, 'preis' => '8.00']], 'bar', null, $this->jetzt());
        $this->assertSame([], $this->abrechnung->auszahlungen($b));

        $this->boersen->vermerkeAuszahlung($b, $this->carla->personId, '2026-11-08', $this->wart->id);

        $this->assertSame([$this->carla->personId => ['betrag' => '11.29', 'ausgezahlt_am' => '2026-11-08']], $this->abrechnung->auszahlungen($b));
        $this->assertSame((string) $this->wart->id, (string) $this->pdo->query('SELECT vermerkt_von_benutzer_id FROM boerse_auszahlung')->fetchColumn());
        $this->assertSame(BoerseDienst::SCHON_VERMERKT, $this->fehler(fn() => $this->boersen->vermerkeAuszahlung($b, $this->carla->personId, '2026-11-09', null))->getMessage());
        $this->assertSame(BoerseDienst::NICHTS_AUSZUZAHLEN, $this->fehler(fn() => $this->boersen->vermerkeAuszahlung($b, $this->eva->personId, '2026-11-08', null))->getMessage(), 'Spende: Auszahlung 0');
        $this->assertSame(BoerseDienst::NICHTS_AUSZUZAHLEN, $this->fehler(fn() => $this->boersen->vermerkeAuszahlung($b, $this->wart->personId, '2026-11-08', null))->getMessage());
        $this->assertSame(BoerseDienst::DATUM_UNGUELTIG, $this->fehler(fn() => $this->boersen->vermerkeAuszahlung($b, $this->carla->personId, '2026-02-30', null))->getMessage());
        // Storno gesperrt, solange der Haken gesetzt ist
        $this->assertSame(KasseDienst::AUSZAHLUNG_VERMERKT, $this->fehler(fn() => $this->kasse->storniere($verkauf, 'Vertippt', null, $this->jetzt()))->getMessage());

        $this->boersen->entferneAuszahlung($b, $this->carla->personId, $this->wart->id);
        $this->assertSame([], $this->abrechnung->auszahlungen($b));
        $this->assertSame(BoerseDienst::KEINE_AUSZAHLUNG, $this->fehler(fn() => $this->boersen->entferneAuszahlung($b, $this->carla->personId, null))->getMessage());
        $this->assertSame(2, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'boerse' AND objekt_id = $b AND aenderungen LIKE '%auszahlung%'"));
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'boerse' AND aenderungen LIKE '%carla%'"), 'kein Name im Protokoll der Börse');
        $this->kasse->storniere($verkauf, 'Vertippt', null, $this->jetzt());
        $this->assertSame(BoerseDienst::NICHTS_AUSZUZAHLEN, $this->fehler(fn() => $this->boersen->vermerkeAuszahlung($b, $this->carla->personId, '2026-11-08', null))->getMessage(), 'nach Storno nichts mehr auszuzahlen');
    }

    /** M1: Auszahlung sperrt zuerst die Börse – eine laufende Kasse/ein Storno wird abgewartet, der Betrag erst danach berechnet. */
    public function test_auszahlung_wartet_auf_boersensperre(): void
    {
        $b = $this->boerse();
        $a = $this->angebot($b);
        $this->kasse->abschliessen($b, [['angebot_id' => $a, 'preis' => '12.50']], 'bar', null, $this->jetzt());
        $this->zweite = self::verbindung();
        $this->zweite->beginTransaction();
        $this->zweite->query("SELECT id FROM boerse WHERE id = $b FOR UPDATE")->fetchAll();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->assertSame(Transaktion::SPERRKONFLIKT, $this->fehler(fn() => $this->boersen->vermerkeAuszahlung($b, $this->carla->personId, '2026-11-08', null))->getMessage());
        $this->assertSame(Transaktion::SPERRKONFLIKT, $this->fehler(fn() => $this->boersen->entferneAuszahlung($b, $this->carla->personId, null))->getMessage());
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_auszahlung'));
    }

    public function test_unbekannte_boerse(): void
    {
        $this->assertSame(BoerseDienst::NICHT_GEFUNDEN, $this->fehler(fn() => $this->boersen->abschliessen(999999, null, $this->jetzt()))->getMessage());
        $this->assertSame(BoerseDienst::NICHT_GEFUNDEN, $this->fehler(fn() => $this->boersen->wiederOeffnen(999999, null))->getMessage());
        $this->assertSame(BoerseDienst::NICHT_GEFUNDEN, $this->fehler(fn() => $this->boersen->vermerkeAuszahlung(999999, $this->carla->personId, '2026-11-08', null))->getMessage());
    }
}
