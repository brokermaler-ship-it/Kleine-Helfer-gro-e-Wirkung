<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Magazin;

use Emc\Core\DomainFehler;
use Emc\Core\Transaktion;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\DbTestCase;

/** Bestand nur aus Buchungen, nie negativ; Gegenbuchung, Umlagerung, Sammlungen und Buchungen aus fremden Transaktionen. */
final class MagazinDienstTest extends DbTestCase
{
    private MagazinRepository $rep;
    private MagazinDienst $dienst;
    private int $benutzer;
    private ?\PDO $zweite = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = new MagazinRepository($this->pdo);
        $this->dienst = new MagazinDienst($this->pdo, $this->rep, new Aenderungsprotokoll($this->pdo));
        $this->benutzer = $this->legeBenutzerAn('greta@verein.de');
    }

    protected function tearDown(): void
    {
        if ($this->zweite !== null && $this->zweite->inTransaction()) {
            $this->zweite->rollBack();
        }
        $this->zweite = null;
    }

    private function kategorie(string $schluessel = 'sonstiges'): int
    {
        return (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = '$schluessel'")->fetchColumn();
    }

    private function artikel(int $menge = 5, string $bezeichnung = 'Märklin C-Gleis 24188', ?string $lagerort = 'Regal 1'): int
    {
        return $this->dienst->legeAnMitEingang(
            ['bezeichnung' => $bezeichnung, 'kategorie_id' => $this->kategorie(), 'lagerort' => $lagerort],
            ['datum' => '2026-10-01', 'menge' => $menge, 'herkunft' => 'spende', 'freitext' => 'Spende von Familie Beispiel', 'betrag' => null],
            $this->benutzer
        );
    }

    /** @return array<string,mixed> */
    private function ausgang(int $menge, string $art = 'verschrottet'): array
    {
        return ['datum' => '2026-10-02', 'menge' => $menge, 'ausgang_art' => $art, 'freitext' => null, 'betrag' => null];
    }

    private function anzahlBuchungen(int $artikel): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM magazin_buchung WHERE artikel_id = $artikel")->fetchColumn();
    }

    /** @return list<int> IDs der Buchungen, älteste zuerst */
    private function buchungsIds(int $artikel): array
    {
        return array_map('intval', $this->pdo->query("SELECT id FROM magazin_buchung WHERE artikel_id = $artikel ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
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

    public function test_anlegen_mit_eingang(): void
    {
        $id = $this->artikel(5);
        $a = $this->rep->finde($id);
        $this->assertSame('M' . $id, $a['code']);
        $this->assertSame(5, $a['bestand']);
        $this->assertSame('Sonstiges', $a['kategorie_name']);
        $this->assertSame('sonstiges', $a['kategorie_schluessel']);
        $this->assertSame('gebraucht', $a['zustand']);
        $this->assertSame($this->benutzer, (int) $a['angelegt_von_benutzer_id']);
        $b = $this->rep->buchungen($id);
        $this->assertCount(1, $b);
        $this->assertSame('eingang', $b[0]['art']);
        $this->assertSame(5, (int) $b[0]['menge']);
        $this->assertSame('spende', $b[0]['herkunft']);
        $this->assertSame('Max Muster', $b[0]['benutzer_name']);
        $protokoll = (new Aenderungsprotokoll($this->pdo))->fuerObjekt('magazin_artikel', $id);
        $this->assertNotEmpty($protokoll);
        $this->assertSame('angelegt', $protokoll[count($protokoll) - 1]['aktion']);
    }

    public function test_bestand_aus_buchungen(): void
    {
        $id = $this->artikel(5);
        $this->dienst->buche($id, 'ausgang', $this->ausgang(3), $this->benutzer);
        $this->assertSame(2, $this->rep->bestand($id));
        $this->assertSame(-3, (int) $this->pdo->query("SELECT menge FROM magazin_buchung WHERE artikel_id = $id AND art = 'ausgang'")->fetchColumn());

        $this->assertSame(
            'Der Bestand reicht nicht: verfügbar sind nur 2.',
            $this->fehlerVon(fn() => $this->dienst->buche($id, 'ausgang', $this->ausgang(3), $this->benutzer))
        );
        $this->assertSame(2, $this->anzahlBuchungen($id));
        $this->assertSame(2, $this->rep->bestand($id));
        $this->assertFalse($this->pdo->inTransaction());

        $this->dienst->buche($id, 'eingang', ['datum' => '2026-10-03', 'menge' => 4, 'herkunft' => 'kauf', 'freitext' => null, 'betrag' => '7.50'], $this->benutzer);
        $this->assertSame(6, $this->rep->bestand($id));
        $this->assertSame('7.50', $this->pdo->query("SELECT betrag FROM magazin_buchung WHERE artikel_id = $id AND herkunft = 'kauf'")->fetchColumn());
    }

    public function test_verkauf_boerse_nur_ueber_die_kasse(): void
    {
        $id = $this->artikel(5);
        $this->assertNotNull($this->fehlerVon(fn() => $this->dienst->buche($id, 'ausgang', $this->ausgang(1, 'verkauft_boerse'), $this->benutzer)));
        $this->assertSame(1, $this->anzahlBuchungen($id));
    }

    public function test_gegenbuchung(): void
    {
        $id = $this->artikel(5);
        $this->dienst->buche($id, 'ausgang', $this->ausgang(3), $this->benutzer);
        [$eingang, $ausgang] = $this->buchungsIds($id);

        // Den Eingang über 5 zurückzunehmen würde den Bestand von 2 negativ machen.
        $this->assertSame('Der Bestand reicht nicht: verfügbar sind nur 2.', $this->fehlerVon(fn() => $this->dienst->gegenbuchung($eingang, $this->benutzer)));
        $this->assertSame(2, $this->anzahlBuchungen($id));

        $gegen = $this->dienst->gegenbuchung($ausgang, $this->benutzer);
        $this->assertSame(5, $this->rep->bestand($id));
        $z = $this->pdo->query("SELECT * FROM magazin_buchung WHERE id = $gegen")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('ausgang', $z['art']);
        $this->assertSame(3, (int) $z['menge']);
        $this->assertSame('verschrottet', $z['ausgang_art']);
        $this->assertSame($ausgang, (int) $z['gegenbuchung_zu_id']);
        $this->assertSame('Gegenbuchung zu Buchung Nr. ' . $ausgang, $z['freitext']);
        $this->assertSame(date('Y-m-d'), $z['datum']);

        $nicht = 'Diese Buchung lässt sich nicht gegenbuchen.';
        $this->assertSame($nicht, $this->fehlerVon(fn() => $this->dienst->gegenbuchung($ausgang, $this->benutzer)));
        // Auch eine Gegenbuchung selbst wird nicht noch einmal gegengebucht.
        $this->assertSame($nicht, $this->fehlerVon(fn() => $this->dienst->gegenbuchung($gegen, $this->benutzer)));
        $this->assertSame(3, $this->anzahlBuchungen($id));

        // Jetzt reicht der Bestand für die Rücknahme des Eingangs.
        $this->dienst->gegenbuchung($eingang, $this->benutzer);
        $this->assertSame(0, $this->rep->bestand($id));

        $this->dienst->buche($id, 'umlagerung', ['datum' => '2026-10-04', 'lagerort_neu' => 'Regal 2'], $this->benutzer);
        $umlagerung = $this->buchungsIds($id)[4];
        $this->assertSame($nicht, $this->fehlerVon(fn() => $this->dienst->gegenbuchung($umlagerung, $this->benutzer)));
        $this->assertSame($nicht, $this->fehlerVon(fn() => $this->dienst->gegenbuchung(999999, $this->benutzer)));
    }

    public function test_gegenbuchung_einer_kassenbuchung_nur_in_der_kasse(): void
    {
        $id = $this->artikel(2);
        $buchung = Transaktion::readCommitted($this->pdo, fn() => $this->dienst->ausgangInLaufenderTransaktion(
            $id, 1, 'verkauft_boerse', '12.50', 'B2026-1', 77, $this->benutzer, new \DateTimeImmutable('2026-10-21 10:00')
        ));
        $z = $this->pdo->query("SELECT * FROM magazin_buchung WHERE id = $buchung")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(-1, (int) $z['menge']);
        $this->assertSame('verkauft_boerse', $z['ausgang_art']);
        $this->assertSame('12.50', $z['betrag']);
        $this->assertSame(77, (int) $z['verkauf_position_id']);
        $this->assertSame('2026-10-21', $z['datum']);
        $this->assertSame(1, $this->rep->bestand($id));

        $this->assertSame('Diese Buchung lässt sich nicht gegenbuchen.', $this->fehlerVon(fn() => $this->dienst->gegenbuchung($buchung, $this->benutzer)));
        $gegen = Transaktion::readCommitted($this->pdo, fn() => $this->dienst->gegenbuchungInLaufenderTransaktion($buchung, $this->benutzer));
        $this->assertSame(2, $this->rep->bestand($id));
        $this->assertSame($buchung, (int) $this->pdo->query("SELECT gegenbuchung_zu_id FROM magazin_buchung WHERE id = $gegen")->fetchColumn());
        $this->assertSame(
            'Diese Buchung lässt sich nicht gegenbuchen.',
            $this->fehlerVon(fn() => Transaktion::readCommitted($this->pdo, fn() => $this->dienst->gegenbuchungInLaufenderTransaktion($buchung, $this->benutzer)))
        );

        // Über den Bestand hinaus auch nicht aus der Kasse.
        $this->assertSame(
            'Der Bestand reicht nicht: verfügbar sind nur 2.',
            $this->fehlerVon(fn() => Transaktion::readCommitted($this->pdo, fn() => $this->dienst->ausgangInLaufenderTransaktion(
                $id, 3, 'verkauft_boerse', '1.00', null, null, $this->benutzer, new \DateTimeImmutable()
            )))
        );
    }

    public function test_ohne_laufende_transaktion(): void
    {
        $id = $this->artikel(2);
        try {
            $this->dienst->ausgangInLaufenderTransaktion($id, 1, 'verkauft_boerse', '1.00', null, null, $this->benutzer, new \DateTimeImmutable());
            $this->fail('LogicException erwartet');
        } catch (\LogicException) {
        }
        try {
            $this->dienst->gegenbuchungInLaufenderTransaktion($this->buchungsIds($id)[0], $this->benutzer);
            $this->fail('LogicException erwartet');
        } catch (\LogicException) {
        }
        $this->assertSame(1, $this->anzahlBuchungen($id));
    }

    public function test_umlagerung(): void
    {
        $id = $this->artikel(5);
        $this->dienst->buche($id, 'umlagerung', ['datum' => '2026-10-04', 'lagerort_neu' => 'Keller, Regal 7'], $this->benutzer);
        $a = $this->rep->finde($id);
        $this->assertSame('Keller, Regal 7', $a['lagerort']);
        $this->assertSame(5, $a['bestand']);
        $z = $this->pdo->query("SELECT * FROM magazin_buchung WHERE artikel_id = $id AND art = 'umlagerung'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $z['menge']);
        $this->assertSame('Keller, Regal 7', $z['lagerort_neu']);
        $protokoll = (new Aenderungsprotokoll($this->pdo))->fuerObjekt('magazin_artikel', $id);
        $this->assertSame(['alt' => 'Regal 1', 'neu' => 'Keller, Regal 7'], $protokoll[0]['aenderungen']['lagerort']);
    }

    public function test_sammlung_umlagern(): void
    {
        $a = $this->artikel(3, 'Gleise Kiste 1');
        $b = $this->artikel(2, 'Gleise Kiste 2', null);
        $andere = $this->artikel(1, 'Fahrpult', 'Schrank');
        $s = $this->rep->sammlungAnlegen('Nachlass Märklin-Gleise, 3 Kisten', 'aus dem Nachlass');
        $stand = (string) $this->pdo->query("SELECT geaendert_am FROM magazin_sammlung WHERE id = $s")->fetchColumn();
        $this->dienst->aktualisiereSammlung($s, 'Nachlass Märklin-Gleise, 3 Kisten', 'aus dem Nachlass', [$b, $a], $stand, $this->benutzer);

        $this->assertSame(2, $this->dienst->umlagereSammlung($s, 'Dachboden', $this->benutzer));
        $this->assertSame('Dachboden', $this->rep->finde($a)['lagerort']);
        $this->assertSame('Dachboden', $this->rep->finde($b)['lagerort']);
        $this->assertSame('Schrank', $this->rep->finde($andere)['lagerort']);
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM magazin_buchung WHERE art = 'umlagerung' AND lagerort_neu = 'Dachboden'")->fetchColumn());
        $this->assertSame(3, $this->rep->bestand($a));

        $sammlung = $this->rep->sammlung($s);
        $this->assertSame([$a, $b], array_map(fn(array $x): int => (int) $x['id'], $sammlung['artikel']));
        $this->assertSame(3, $sammlung['artikel'][0]['bestand']);
        $this->assertSame($s, (int) $this->rep->finde($a)['sammlung_id']);
        $this->assertSame('Nachlass Märklin-Gleise, 3 Kisten', $this->rep->finde($a)['sammlung_name']);
        $liste = $this->rep->sammlungen();
        $this->assertSame(2, (int) $liste[0]['artikel_anzahl']);
    }

    public function test_sammlung_artikel_nur_einmal_und_stand(): void
    {
        $a = $this->artikel(1, 'Kiste 1');
        $s1 = $this->rep->sammlungAnlegen('Erste', null);
        $s2 = $this->rep->sammlungAnlegen('Zweite', null);
        $stand = fn(int $s): string => (string) $this->pdo->query("SELECT geaendert_am FROM magazin_sammlung WHERE id = $s")->fetchColumn();
        $alt = $stand($s1);
        $this->dienst->aktualisiereSammlung($s1, 'Erste', null, [$a], $alt, $this->benutzer);
        $this->assertSame(
            'M' . $a . ' gehört schon zur Sammlung „Erste“.',
            $this->fehlerVon(fn() => $this->dienst->aktualisiereSammlung($s2, 'Zweite', null, [$a], $stand($s2), $this->benutzer))
        );
        $this->assertSame($s1, (int) $this->rep->finde($a)['sammlung_id']);
        // Veralteter Stand: Konflikt, nichts geändert.
        $this->assertNotNull($this->fehlerVon(fn() => $this->dienst->aktualisiereSammlung($s1, 'Umbenannt', null, [], $alt, $this->benutzer)));
        $this->assertSame('Erste', $this->rep->sammlung($s1)['name']);
        $this->assertCount(1, $this->rep->sammlung($s1)['artikel']);
    }

    public function test_gleichzeitiger_ausgang(): void
    {
        $id = $this->artikel(1);
        // Eine zweite Kasse bzw. ein zweiter Börsenwart sperrt den Artikel, bucht den letzten Bestand aus und schreibt fest.
        $this->zweite = self::verbindung();
        $this->zweite->beginTransaction();
        $this->zweite->query("SELECT id FROM magazin_artikel WHERE id = $id FOR UPDATE")->fetchAll();
        $this->zweite->exec("INSERT INTO magazin_buchung (artikel_id, art, datum, menge, ausgang_art, erstellt_am) VALUES ($id, 'ausgang', CURDATE(), -1, 'verschrottet', NOW())");
        $this->zweite->commit();

        // READ COMMITTED nach der Sperre: die fremde Buchung ist sichtbar, der Bestand reicht nicht mehr.
        $this->assertSame('Der Bestand reicht nicht: verfügbar sind nur 0.', $this->fehlerVon(fn() => $this->dienst->buche($id, 'ausgang', $this->ausgang(1), $this->benutzer)));
        $this->assertSame(0, $this->rep->bestand($id));

        // Hält die zweite Verbindung die Sperre, wartet die Buchung höchstens eine Sekunde: Sperrkonflikt, nichts gebucht.
        $this->dienst->buche($id, 'eingang', ['datum' => '2026-10-05', 'menge' => 1, 'herkunft' => 'fund', 'freitext' => null, 'betrag' => null], $this->benutzer);
        $this->zweite->beginTransaction();
        $this->zweite->query("SELECT id FROM magazin_artikel WHERE id = $id FOR UPDATE")->fetchAll();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->assertSame(Transaktion::SPERRKONFLIKT, $this->fehlerVon(fn() => $this->dienst->buche($id, 'ausgang', $this->ausgang(1), $this->benutzer)));
        $this->assertFalse($this->pdo->inTransaction());
        $this->zweite->rollBack();
        $this->assertSame(1, $this->rep->bestand($id));
        $this->assertSame(3, $this->anzahlBuchungen($id));
    }

    public function test_suche_und_filter(): void
    {
        $gleis = $this->artikel(5, 'Märklin C-Gleis 24188', 'Regal 1');
        $leer = $this->artikel(1, 'Fahrpult 6647', 'Schrank');
        $this->dienst->buche($leer, 'ausgang', $this->ausgang(1), $this->benutzer);
        $buch = $this->dienst->legeAnMitEingang(
            ['bezeichnung' => 'Dampflokomotiven der Deutschen Bundesbahn', 'kategorie_id' => $this->kategorie('literatur'), 'autor_verlag' => 'Beispielverlag', 'jahr' => 1987, 'isbn' => '978-3-00-000000-0'],
            ['datum' => '2026-10-01', 'menge' => 1, 'herkunft' => 'spende', 'freitext' => null, 'betrag' => null],
            null
        );
        $ids = fn(array $liste): array => array_map(fn(array $a): int => (int) $a['id'], $liste);
        $f = fn(array $p): \Emc\Magazin\MagazinFilter => new \Emc\Magazin\MagazinFilter(...$p);

        $this->assertEqualsCanonicalizing([$gleis, $buch], $ids($this->rep->suche($f([]))));
        $this->assertEqualsCanonicalizing([$gleis, $leer, $buch], $ids($this->rep->suche($f(['nurMitBestand' => false])))) ;
        $this->assertSame([$gleis], $ids($this->rep->suche($f(['text' => 'c-gleis']))));
        $this->assertSame([$gleis], $ids($this->rep->suche($f(['text' => 'm' . $gleis]))));
        $this->assertSame([$buch], $ids($this->rep->suche($f(['text' => '978-3-00']))));
        $this->assertSame([$buch], $ids($this->rep->suche($f(['text' => 'Beispielverlag']))));
        $this->assertSame([$buch], $ids($this->rep->suche($f(['kategorieId' => $this->kategorie('literatur')]))));
        $this->assertSame([$gleis], $ids($this->rep->suche($f(['lagerort' => 'Regal 1']))));
        $this->assertSame([], $ids($this->rep->suche($f(['text' => '100%_']))));
        $treffer = $this->rep->suche($f(['text' => 'c-gleis']))[0];
        $this->assertSame(5, $treffer['bestand']);
        $this->assertSame('M' . $gleis, $treffer['code']);
        $this->assertArrayHasKey('titelbild_dokument_id', $treffer);
        $this->assertSame(['Regal 1', 'Schrank'], $this->rep->lagerorte());
    }

    public function test_kategorien(): void
    {
        $namen = array_column($this->rep->kategorien(), 'name');
        $this->assertSame('Gleise und Weichen', $namen[0]);
        $this->assertSame('Sonstiges', $namen[8]);
        $this->assertSame('Literatur', $this->rep->kategorieNachSchluessel('literatur')['name']);
        $neu = $this->rep->kategorieSpeichern(null, 'Möbel', 95);
        $this->assertTrue($this->rep->kategorieLoeschen($neu));
        $this->assertFalse($this->rep->kategorieLoeschen($this->kategorie('literatur')));
        $gleise = (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE name = 'Gleise und Weichen'")->fetchColumn();
        $this->dienst->legeAnMitEingang(['bezeichnung' => 'Weiche', 'kategorie_id' => $gleise], ['datum' => '2026-10-01', 'menge' => 1, 'herkunft' => 'fund'], null);
        $this->assertFalse($this->rep->kategorieLoeschen($gleise));
        try {
            $this->rep->kategorieSpeichern(null, 'literatur', 1);
            $this->fail('Doppelter Name');
        } catch (DomainFehler $e) {
            $this->assertSame('Diese Kategorie gibt es bereits.', $e->getMessage());
        }
    }
}
