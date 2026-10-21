<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Boerse;

use Emc\Auth\Benutzer;
use Emc\Auth\Rolle;
use Emc\Boerse\AngebotDienst;
use Emc\Boerse\AngebotRepository;
use Emc\Boerse\BoerseRepository;
use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Transaktion;
use Emc\Fahrzeuge\KonfliktFehler;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\DbTestCase;

/** Angebote: Nummern je Jahr, Menge aus dem Magazin, Sammlungen, Freigabe-Rückfall, abgeschlossene Börse, Sperren. */
final class AngebotDienstTest extends DbTestCase
{
    private AngebotRepository $rep;
    private AngebotDienst $dienst;
    private MagazinDienst $magazin;
    private MagazinRepository $magazinRep;
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
        $this->dienst = new AngebotDienst($this->pdo, $this->rep, $this->magazinRep, $protokoll, new BoerseRepository($this->pdo));
        $this->magazin = new MagazinDienst($this->pdo, $this->magazinRep, $protokoll, $this->rep);
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
    }

    private function benutzer(string $email, Rolle $rolle, bool $boersenwart): Benutzer
    {
        $id = $this->legeBenutzerAn($email, 'geheim-passwort-1', $rolle->value);
        $person = (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = $id")->fetchColumn();
        return new Benutzer($id, $person, $email, $email, $rolle, false, '', $boersenwart);
    }

    private function kategorie(string $schluessel = 'sonstiges'): int
    {
        return (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = '$schluessel'")->fetchColumn();
    }

    private function artikel(int $menge): int
    {
        return $this->magazin->legeAnMitEingang(
            ['bezeichnung' => 'Märklin C-Gleis 24188', 'kategorie_id' => $this->kategorie()],
            ['datum' => '2026-10-01', 'menge' => $menge, 'herkunft' => 'spende', 'freitext' => null, 'betrag' => null],
            $this->wart->id
        );
    }

    private function boerse(string $status = 'in_vorbereitung'): int
    {
        $this->pdo->prepare("INSERT INTO boerse (name, datum, status) VALUES ('Herbstbörse', '2026-11-08', ?)")->execute([$status]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function daten(array $mehr = []): array
    {
        return $mehr + [
            'bezeichnung' => 'BR 50 Märklin', 'beschreibung' => 'gut erhalten', 'kategorie_id' => $this->kategorie(), 'spurweite_id' => null,
            'zustand' => 'gebraucht', 'wunschpreis' => '12.50', 'menge' => 1, 'herkunft' => 'mitglied', 'person_id' => $this->carla->personId,
            'erloes_spende' => 0, 'boerse_id' => null, 'notiz' => null,
        ];
    }

    private function fehlerVon(callable $f): string
    {
        try {
            $f();
        } catch (DomainFehler $e) {
            return $e->getMessage();
        }
        $this->fail('DomainFehler erwartet');
    }

    /** @return array<string,mixed> */
    private function angebot(int $id): array
    {
        return $this->rep->finde($id) ?? $this->fail('Angebot fehlt');
    }

    public function test_nummern_je_jahr(): void
    {
        $a = $this->dienst->anlegen($this->daten(), $this->carla, new \DateTimeImmutable('2026-10-21'));
        $b = $this->dienst->anlegen($this->daten(), $this->carla, new \DateTimeImmutable('2026-12-31 23:59'));
        $c = $this->dienst->anlegen($this->daten(), $this->carla, new \DateTimeImmutable('2027-01-01'));
        $this->assertSame(['B2026-1', 'B2026-2', 'B2027-1'], [$this->angebot($a)['nummer'], $this->angebot($b)['nummer'], $this->angebot($c)['nummer']]);
        $this->assertSame($b, (int) $this->rep->findeNachNummer(2026, 2)['id']);
        $this->assertSame('angelegt', $this->pdo->query("SELECT aktion FROM aenderungsprotokoll WHERE objekt_typ = 'boerse_angebot' AND objekt_id = $a")->fetchColumn());
    }

    public function test_nummer_bei_fehler_nicht_verbraucht(): void
    {
        $artikel = $this->artikel(1);
        $this->assertSame(AngebotDienst::freiImMagazin(1), $this->fehlerVon(fn() => $this->dienst->anlegen(
            $this->daten(['herkunft' => 'verein', 'person_id' => null, 'magazin_artikel_id' => $artikel, 'menge' => 2]), $this->wart, new \DateTimeImmutable('2026-10-21')
        )));
        $a = $this->dienst->anlegen($this->daten(), $this->carla, new \DateTimeImmutable('2026-10-21'));
        $this->assertSame('B2026-1', $this->angebot($a)['nummer'], 'Zähler zurückgerollt');
    }

    public function test_menge_aus_dem_magazin(): void
    {
        $artikel = $this->artikel(3);
        $jetzt = new \DateTimeImmutable('2026-10-21');
        $verein = fn(int $menge) => $this->daten(['herkunft' => 'verein', 'person_id' => null, 'magazin_artikel_id' => $artikel, 'menge' => $menge]);
        $erstes = $this->dienst->anlegen($verein(2), $this->wart, $jetzt);
        $this->assertSame('Im Magazin sind nur noch 1 Stück frei.', $this->fehlerVon(fn() => $this->dienst->anlegen($verein(2), $this->wart, $jetzt)));
        $zweites = $this->dienst->anlegen($verein(1), $this->wart, $jetzt);
        $this->assertSame(3, $this->rep->offeneMengeFuerArtikel($artikel));
        $this->assertSame('verein', $this->angebot($zweites)['herkunft']);
        $this->assertNull($this->angebot($zweites)['person_id']);
        $this->assertSame('M' . $artikel, $this->angebot($zweites)['magazin_code']);

        // Ein Ausgang von Hand darf die angebotene Menge nicht unterlaufen (Bestand 3, alles angeboten).
        $this->assertSame(
            'Der Bestand reicht nicht: verfügbar sind nur 0. 3 Stück sind in der Börse angeboten.',
            $this->fehlerVon(fn() => $this->magazin->buche($artikel, 'ausgang', ['datum' => '2026-10-21', 'menge' => 1, 'ausgang_art' => 'verschrottet'], $this->wart->id))
        );
        // Zurückgezogen gibt die Menge frei.
        $this->dienst->setzeStatus($erstes, 'zurueckziehen', $this->wart);
        $this->magazin->buche($artikel, 'ausgang', ['datum' => '2026-10-21', 'menge' => 2, 'ausgang_art' => 'verschrottet'], $this->wart->id);
        $this->assertSame(1, $this->magazinRep->bestand($artikel));
        // Wieder anbieten prüft die freie Menge erneut (Bestand 1, 1 Stück im zweiten Angebot).
        $this->assertSame('Im Magazin sind nur noch 0 Stück frei.', $this->fehlerVon(fn() => $this->dienst->setzeStatus($erstes, 'wieder_anbieten', $this->wart, 'dauerhaft')));
        // Menge erhöhen beim Bearbeiten ebenso.
        $a = $this->angebot($zweites);
        $this->assertSame('Im Magazin sind nur noch 1 Stück frei.', $this->fehlerVon(fn() => $this->dienst->aendern($zweites, $this->daten(['herkunft' => 'verein', 'person_id' => null, 'menge' => 2]), (string) $a['geaendert_am'], $this->wart)));
        // Die Kasse (Ausgang in ihrer Transaktion) zählt nur den Bestand.
        Transaktion::readCommitted($this->pdo, fn() => $this->magazin->ausgangInLaufenderTransaktion($artikel, 1, 'verkauft_boerse', '12.50', 'B2026-2', null, $this->wart->id, $jetzt));
        $this->assertSame(0, $this->magazinRep->bestand($artikel));
    }

    public function test_sammlung(): void
    {
        $a1 = $this->artikel(3);
        $a2 = $this->artikel(1);
        $einzeln = $this->artikel(2);
        $sammlung = $this->magazin->legeSammlungAn('Nachlass Märklin-Gleise, 3 Kisten', null, [$a1, $a2], $this->wart->id);
        $jetzt = new \DateTimeImmutable('2026-10-21');
        $daten = $this->daten(['herkunft' => 'verein', 'person_id' => null, 'magazin_sammlung_id' => $sammlung, 'menge' => 5]);
        $id = $this->dienst->anlegen($daten, $this->wart, $jetzt);
        $this->assertSame(1, $this->angebot($id)['menge'], 'Sammlung als Ganzes');
        $this->assertSame('Nachlass Märklin-Gleise, 3 Kisten', $this->angebot($id)['sammlung_name']);
        $this->assertSame(AngebotDienst::SAMMLUNG_SCHON, $this->fehlerVon(fn() => $this->dienst->anlegen($daten, $this->wart, $jetzt)));

        // Artikel der angebotenen Sammlung: kein Einzelangebot, kein Ausgang von Hand, keine Änderung der Sammlung.
        $this->assertSame(
            'M' . $a1 . ' gehört zur Sammlung „Nachlass Märklin-Gleise, 3 Kisten“, die schon im Angebot ist.',
            $this->fehlerVon(fn() => $this->dienst->anlegen($this->daten(['herkunft' => 'verein', 'person_id' => null, 'magazin_artikel_id' => $a1]), $this->wart, $jetzt))
        );
        $this->assertStringStartsWith(
            'Der Bestand reicht nicht: verfügbar sind nur 0. Der Artikel gehört zur Sammlung',
            $this->fehlerVon(fn() => $this->magazin->buche($a1, 'ausgang', ['datum' => '2026-10-21', 'menge' => 1, 'ausgang_art' => 'verschrottet'], $this->wart->id))
        );
        $stand = (string) $this->magazinRep->sammlung($sammlung)['geaendert_am'];
        $this->assertSame(MagazinDienst::SAMMLUNG_IM_ANGEBOT, $this->fehlerVon(fn() => $this->magazin->aktualisiereSammlung($sammlung, 'Nachlass', null, [$a1, $a2, $einzeln], $stand, $this->wart->id)));
        $this->magazin->aktualisiereSammlung($sammlung, 'Nachlass Märklin', null, [$a2, $a1], $stand, $this->wart->id); // nur Name

        // Eine Sammlung mit einem einzeln angebotenen Artikel lässt sich nicht anbieten.
        $andere = $this->magazin->legeSammlungAn('Kiste 2', null, [$einzeln], $this->wart->id);
        $this->dienst->anlegen($this->daten(['herkunft' => 'verein', 'person_id' => null, 'magazin_artikel_id' => $einzeln]), $this->wart, $jetzt);
        $this->assertSame('M' . $einzeln . ' ist schon einzeln im Angebot.', $this->fehlerVon(fn() => $this->dienst->anlegen(
            $this->daten(['herkunft' => 'verein', 'person_id' => null, 'magazin_sammlung_id' => $andere]), $this->wart, $jetzt
        )));
        $leer = $this->magazin->legeSammlungAn('Leer', null, [], $this->wart->id);
        $this->assertSame(MagazinDienst::SAMMLUNG_LEER, $this->fehlerVon(fn() => $this->dienst->anlegen(
            $this->daten(['herkunft' => 'verein', 'person_id' => null, 'magazin_sammlung_id' => $leer]), $this->wart, $jetzt
        )));
    }

    public function test_fotos_aus_dem_magazin(): void
    {
        $artikel = $this->artikel(1);
        $this->pdo->exec("INSERT INTO dokument (titel, original_name, pfad, endung, mime, groesse, kategorie, hat_anzeige) VALUES ('Gleis', 'gleis.jpg', 'x/gleis', 'jpg', 'image/jpeg', 10, 'foto', 1)");
        $foto = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO dokument (titel, original_name, pfad, endung, mime, groesse, kategorie, hat_anzeige) VALUES ('Rechnung', 'r.pdf', 'x/r', 'pdf', 'application/pdf', 10, 'rechnung', 0)");
        $pdf = (int) $this->pdo->lastInsertId();
        foreach ([$foto, $pdf] as $d) {
            $this->pdo->exec("INSERT INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) VALUES ($d, 'magazin_artikel', $artikel)");
        }
        $this->magazinRep->setzeTitelbild($artikel, $foto);
        $id = $this->dienst->anlegen($this->daten(['herkunft' => 'verein', 'person_id' => null, 'magazin_artikel_id' => $artikel]), $this->wart, new \DateTimeImmutable());
        $this->assertSame([$foto], array_map('intval', $this->pdo->query("SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = 'boerse_angebot' AND objekt_id = $id")->fetchAll(\PDO::FETCH_COLUMN)));
        $this->assertSame($foto, (int) $this->angebot($id)['titelbild_dokument_id']);
    }

    public function test_vereinsangebot_nur_boersenwart(): void
    {
        try {
            $this->dienst->anlegen($this->daten(['herkunft' => 'verein', 'person_id' => null]), $this->carla, new \DateTimeImmutable());
            $this->fail('403 erwartet');
        } catch (HttpFehler $e) {
            $this->assertSame(403, $e->status);
        }
        try {
            $this->dienst->anlegen($this->daten(['person_id' => $this->eva->personId]), $this->carla, new \DateTimeImmutable());
            $this->fail('fremde Person: 403 erwartet');
        } catch (HttpFehler $e) {
            $this->assertSame(403, $e->status);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM boerse_angebot')->fetchColumn());
    }

    public function test_freigabe_rueckfall(): void
    {
        $id = $this->dienst->anlegen($this->daten(['oeffentlich' => 'vorgeschlagen']), $this->carla, new \DateTimeImmutable());
        $this->assertSame('vorgeschlagen', $this->angebot($id)['oeffentlich']);
        try {
            $this->dienst->setzeOeffentlich($id, 'freigegeben', $this->carla);
            $this->fail('Mitglied gibt nicht frei');
        } catch (HttpFehler $e) {
            $this->assertSame(403, $e->status);
        }
        $this->dienst->setzeOeffentlich($id, 'freigegeben', $this->wart);
        $this->assertSame('freigegeben', $this->angebot($id)['oeffentlich']);

        // Nur die Notiz: bleibt freigegeben.
        $a = $this->angebot($id);
        $this->assertFalse($this->dienst->aendern($id, $this->daten(['notiz' => 'Kiste 3']), (string) $a['geaendert_am'], $this->carla));
        $this->assertSame('freigegeben', $this->angebot($id)['oeffentlich']);
        // Preis: Rückfall.
        $a = $this->angebot($id);
        $this->assertTrue($this->dienst->aendern($id, $this->daten(['wunschpreis' => '15.00']), (string) $a['geaendert_am'], $this->carla));
        $this->assertSame('vorgeschlagen', $this->angebot($id)['oeffentlich']);
        $this->assertSame('15.00', $this->angebot($id)['wunschpreis']);

        // Der Börsenwart ändert ohne Rückfall.
        $this->dienst->setzeOeffentlich($id, 'freigegeben', $this->wart);
        $a = $this->angebot($id);
        $this->assertFalse($this->dienst->aendern($id, $this->daten(['wunschpreis' => '14.00']), (string) $a['geaendert_am'], $this->wart));
        $this->assertSame('freigegeben', $this->angebot($id)['oeffentlich']);

        // Foto durch das Mitglied: Rückfall; durch den Börsenwart nicht.
        $this->assertFalse($this->dienst->fotoGeaendert($id, $this->wart));
        $this->assertTrue($this->dienst->fotoGeaendert($id, $this->carla));
        $this->assertSame('vorgeschlagen', $this->angebot($id)['oeffentlich']);
        $this->assertFalse($this->dienst->fotoGeaendert($id, $this->carla), 'schon vorgeschlagen');

        // Mitglied zieht den Vorschlag zurück.
        $this->dienst->setzeOeffentlich($id, 'nein', $this->carla);
        $this->assertSame('nein', $this->angebot($id)['oeffentlich']);
    }

    public function test_rechte_und_stand(): void
    {
        $id = $this->dienst->anlegen($this->daten(), $this->carla, new \DateTimeImmutable());
        $a = $this->angebot($id);
        try {
            $this->dienst->aendern($id, $this->daten(['person_id' => $this->eva->personId]), (string) $a['geaendert_am'], $this->eva);
            $this->fail('fremdes Angebot: 403');
        } catch (HttpFehler $e) {
            $this->assertSame(403, $e->status);
        }
        $this->dienst->aendern($id, $this->daten(['bezeichnung' => 'BR 50']), (string) $a['geaendert_am'], $this->carla);
        $this->expectException(KonfliktFehler::class);
        $this->dienst->aendern($id, $this->daten(['bezeichnung' => 'BR 50 neu']), (string) $a['geaendert_am'], $this->carla);
    }

    public function test_status(): void
    {
        $id = $this->dienst->anlegen($this->daten(), $this->carla, new \DateTimeImmutable());
        foreach (['reservieren', 'wieder_anbieten', 'reservierung_aufheben'] as $aktion) {
            try {
                $this->dienst->setzeStatus($id, $aktion, $this->carla);
                $this->fail($aktion . ': 403 erwartet');
            } catch (HttpFehler $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $this->assertSame(AngebotDienst::AKTION_UNBEKANNT, $this->fehlerVon(fn() => $this->dienst->setzeStatus($id, 'verkauft', $this->wart)));
        $this->dienst->setzeStatus($id, 'reservieren', $this->wart);
        $this->assertSame('reserviert', $this->angebot($id)['status']);
        try {
            $this->dienst->setzeStatus($id, 'zurueckziehen', $this->carla);
            $this->fail('reserviertes zieht nur der Börsenwart zurück');
        } catch (HttpFehler $e) {
            $this->assertSame(403, $e->status);
        }
        $this->dienst->setzeStatus($id, 'reservierung_aufheben', $this->wart);
        $this->dienst->setzeStatus($id, 'zurueckziehen', $this->carla);
        $this->assertSame('zurueck', $this->angebot($id)['status']);
        $boerse = $this->boerse();
        $this->dienst->setzeStatus($id, 'wieder_anbieten', $this->wart, (string) $boerse);
        $this->assertSame(['angeboten', $boerse], [$this->angebot($id)['status'], (int) $this->angebot($id)['boerse_id']]);
        $this->pdo->exec("UPDATE boerse_angebot SET status = 'verkauft' WHERE id = $id");
        $this->assertSame(AngebotDienst::VERKAUFT, $this->fehlerVon(fn() => $this->dienst->setzeStatus($id, 'zurueckziehen', $this->wart)));
        $a = $this->angebot($id);
        $this->assertSame(AngebotDienst::VERKAUFT, $this->fehlerVon(fn() => $this->dienst->aendern($id, $this->daten(), (string) $a['geaendert_am'], $this->wart)));
    }

    public function test_abgeschlossene_boerse(): void
    {
        $boerse = $this->boerse();
        $id = $this->dienst->anlegen($this->daten(['boerse_id' => $boerse]), $this->carla, new \DateTimeImmutable());
        $this->pdo->exec("UPDATE boerse SET status = 'abgeschlossen' WHERE id = $boerse");
        $a = $this->angebot($id);
        foreach ([$this->carla, $this->wart] as $b) {
            $this->assertSame(AngebotDienst::BOERSE_ABGESCHLOSSEN, $this->fehlerVon(fn() => $this->dienst->aendern($id, $this->daten(['boerse_id' => $boerse]), (string) $a['geaendert_am'], $b)));
        }
        $this->assertSame(AngebotDienst::BOERSE_ABGESCHLOSSEN, $this->fehlerVon(fn() => $this->dienst->setzeOeffentlich($id, 'freigegeben', $this->wart)));
        $this->assertSame(AngebotDienst::BOERSE_ABGESCHLOSSEN, $this->fehlerVon(fn() => $this->dienst->setzeStatus($id, 'zurueckziehen', $this->wart)));
        $this->assertSame(AngebotDienst::BOERSE_ABGESCHLOSSEN, $this->fehlerVon(fn() => $this->dienst->pruefeAenderbar($id, $this->wart)));
        $this->assertSame(AngebotDienst::BOERSE_ABGESCHLOSSEN, $this->fehlerVon(fn() => $this->dienst->anlegen($this->daten(['boerse_id' => $boerse]), $this->carla, new \DateTimeImmutable())));
        $this->assertSame('BR 50 Märklin', $this->angebot($id)['bezeichnung']);

        // Zurückgegebene Stücke bietet der Börsenwart in einer anderen Börse wieder an.
        $this->pdo->exec("UPDATE boerse_angebot SET status = 'zurueck' WHERE id = $id");
        $this->assertStringStartsWith('Die Börse ist abgeschlossen. Bitte eine offene Börse', $this->fehlerVon(fn() => $this->dienst->setzeStatus($id, 'wieder_anbieten', $this->wart)));
        $this->dienst->setzeStatus($id, 'wieder_anbieten', $this->wart, 'dauerhaft');
        $this->assertSame('angeboten', $this->angebot($id)['status']);
        $this->assertNull($this->angebot($id)['boerse_id']);
    }

    /** Hält eine andere Verbindung die Börse gesperrt (z. B. die Kasse), meldet die Änderung einen Sperrkonflikt. */
    public function test_gesperrte_boerse(): void
    {
        $boerse = $this->boerse('laeuft');
        $id = $this->dienst->anlegen($this->daten(['boerse_id' => $boerse]), $this->carla, new \DateTimeImmutable());
        $this->zweite = self::verbindung();
        $this->zweite->beginTransaction();
        $this->zweite->query("SELECT id FROM boerse WHERE id = $boerse FOR UPDATE")->fetchAll();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->assertSame(Transaktion::SPERRKONFLIKT, $this->fehlerVon(fn() => $this->dienst->setzeStatus($id, 'reservieren', $this->wart)));
        } finally {
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
        $this->assertSame('angeboten', $this->angebot($id)['status']);
        $this->assertFalse($this->pdo->inTransaction());
    }
}
