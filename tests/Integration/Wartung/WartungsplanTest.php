<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Wartung;

use Emc\Tests\Integration\DbTestCase;
use Emc\Wartung\Ampel;
use Emc\Wartung\AusnahmeRepository;
use Emc\Wartung\WartungFilter;
use Emc\Wartung\Wartungsplan;
use Emc\Wartung\WartungRepository;
use Emc\Wartung\WartungsartRepository;

final class WartungsplanTest extends DbTestCase
{
    private int $h0;
    private int $n;
    private \DateTimeImmutable $heute;

    protected function setUp(): void
    {
        parent::setUp();
        $this->h0 = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $this->n = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name <> 'H0' ORDER BY id LIMIT 1")->fetchColumn();
        $this->heute = new \DateTimeImmutable('2026-10-21');
    }

    private function plan(): Wartungsplan
    {
        return new Wartungsplan($this->pdo, new WartungsartRepository($this->pdo), new WartungRepository($this->pdo), new AusnahmeRepository($this->pdo));
    }

    private function fahrzeug(string $gattung = 'lok', ?int $eigentuemer = null, ?int $spurweite = null, string $status = 'einsatzbereit', string $stunden = '40.0', string $bezeichnung = 'V 200'): int
    {
        static $nr = 0;
        $nr++;
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, gattung, spurweite_id, eigentuemer_person_id, status, betriebsstunden) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([sprintf('EMC-%04d', $nr), $bezeichnung, $gattung, $spurweite ?? $this->h0, $eigentuemer, $status, $stunden]);
        return (int) $this->pdo->lastInsertId();
    }

    private function anlage(?int $spurweite = null, string $name = 'Clubanlage'): int
    {
        $this->pdo->prepare("INSERT INTO anlage (nummer, name, spurweite_id) VALUES ((SELECT COALESCE(MAX(a.nummer), 0) + 1 FROM anlage a), ?, ?)")
            ->execute([$name, $spurweite ?? $this->h0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function person(): int
    {
        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('Erika', 'Beispiel')");
        return (int) $this->pdo->lastInsertId();
    }

    private function art(string $name): int
    {
        $st = $this->pdo->prepare('SELECT id FROM wartungsart WHERE name = ?');
        $st->execute([$name]);
        return (int) $st->fetchColumn();
    }

    /** @param list<string> $arten */
    private function wartung(string $typ, int $id, string $datum, array $arten, ?float $bezug = null): void
    {
        (new WartungRepository($this->pdo))->eintragen($typ, $id, [
            'datum' => $datum, 'betriebsstunden' => $bezug, 'stundenstand_bezug' => $bezug, 'ausgefuehrt_von_person_id' => null, 'notiz' => null,
        ], array_map(fn(string $n): int => $this->art($n), $arten), null);
    }

    /** @return array<string,mixed> */
    private function objekt(string $typ, int $id): array
    {
        $tabelle = $typ === 'anlage' ? 'anlage' : 'fahrzeug';
        return $this->pdo->query("SELECT * FROM $tabelle WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string,mixed>> $zeilen
     * @return array<string, array<string,mixed>> Name der Wartungsart => Zeile
     */
    private static function nachArt(array $zeilen): array
    {
        $e = [];
        foreach ($zeilen as $z) {
            $e[$z['art']['name']] = $z;
        }
        return $e;
    }

    public function test_arten_nach_gattung(): void
    {
        $lok = $this->fuerObjekt('fahrzeug', $this->fahrzeug('lok'));
        self::assertSame(['Räder und Radschleifer reinigen', 'Getriebe und Lager schmieren', 'Haftreifen prüfen/tauschen', 'Motor/Kohlen prüfen', 'Kupplungen prüfen'],
            array_map(fn($z) => $z['art']['name'], $lok));
        self::assertCount(2, $this->fuerObjekt('fahrzeug', $this->fahrzeug('wagen')));
        self::assertCount(5, $this->fuerObjekt('fahrzeug', $this->fahrzeug('triebwagen')));
        $anlage = $this->fuerObjekt('anlage', $this->anlage());
        self::assertSame(['Gleise reinigen', 'Weichen und Antriebe prüfen', 'Verkabelung und Stecker prüfen'], array_map(fn($z) => $z['art']['name'], $anlage));
        foreach ([...$lok, ...$anlage] as $z) {
            self::assertTrue($z['faelligkeit']->nochNieErfasst);
            self::assertSame(Ampel::Gelb, $z['faelligkeit']->ampel);
            self::assertNull($z['letzte']);
            self::assertFalse($z['eigenes_intervall']);
        }
        // Bei Anlagen zählen keine Stunden.
        self::assertNull($anlage[0]['stunden']);
        self::assertSame(6, $lok[0]['monate']);
        self::assertSame(20, $lok[0]['stunden']);
    }

    /** @return list<array<string,mixed>> */
    private function fuerObjekt(string $typ, int $id): array
    {
        return $this->plan()->fuerObjekt($typ, $this->objekt($typ, $id), $this->heute);
    }

    public function test_ausgemustert_inaktiv_und_abgeschaltet_entfallen(): void
    {
        $ausgemustert = $this->fahrzeug('lok', status: 'ausgemustert');
        $this->wartung('fahrzeug', $ausgemustert, '2020-01-01', ['Kupplungen prüfen']);
        self::assertSame([], $this->fuerObjekt('fahrzeug', $ausgemustert));

        $lok = $this->fahrzeug('lok');
        $this->pdo->exec("UPDATE wartungsart SET aktiv = 0 WHERE name = 'Motor/Kohlen prüfen'");
        (new AusnahmeRepository($this->pdo))->speichere('fahrzeug', $lok, $this->art('Haftreifen prüfen/tauschen'), 'aus', null, null);
        self::assertSame(['Räder und Radschleifer reinigen', 'Getriebe und Lager schmieren', 'Kupplungen prüfen'],
            array_map(fn($z) => $z['art']['name'], $this->fuerObjekt('fahrzeug', $lok)));

        $ueb = $this->plan()->uebersicht(new WartungFilter(), $this->heute);
        self::assertSame([$lok], array_values(array_unique(array_map(fn($z) => (int) $z['objekt']['id'], $ueb))));
        self::assertCount(3, $ueb);
    }

    public function test_eigenes_intervall_ersetzt_standard(): void
    {
        $mit = $this->fahrzeug('lok');
        $ohne = $this->fahrzeug('lok');
        (new AusnahmeRepository($this->pdo))->speichere('fahrzeug', $mit, $this->art('Räder und Radschleifer reinigen'), 'eigen', 3, null);
        $vorVier = $this->heute->modify('-4 months')->format('Y-m-d');
        $this->wartung('fahrzeug', $mit, $vorVier, ['Räder und Radschleifer reinigen'], 40.0);
        $this->wartung('fahrzeug', $ohne, $vorVier, ['Räder und Radschleifer reinigen'], 40.0);

        $z = self::nachArt($this->fuerObjekt('fahrzeug', $mit))['Räder und Radschleifer reinigen'];
        self::assertSame(Ampel::Rot, $z['faelligkeit']->ampel);
        self::assertTrue($z['eigenes_intervall']);
        self::assertSame(3, $z['monate']);
        self::assertNull($z['stunden']);
        self::assertNull($z['faelligkeit']->faelligBeiStunden);

        $z = self::nachArt($this->fuerObjekt('fahrzeug', $ohne))['Räder und Radschleifer reinigen'];
        self::assertSame(Ampel::Gruen, $z['faelligkeit']->ampel);
        self::assertFalse($z['eigenes_intervall']);
        self::assertSame(60.0, $z['faelligkeit']->faelligBeiStunden);
    }

    public function test_juengstes_datum_ist_bezug(): void
    {
        $lok = $this->fahrzeug('lok', stunden: '62.0');
        $this->wartung('fahrzeug', $lok, '2026-09-01', ['Räder und Radschleifer reinigen'], 60.0);
        $this->wartung('fahrzeug', $lok, '2026-03-01', ['Räder und Radschleifer reinigen'], 30.0); // Nachtrag
        $z = self::nachArt($this->fuerObjekt('fahrzeug', $lok))['Räder und Radschleifer reinigen'];
        self::assertSame(['datum' => '2026-09-01', 'stundenstand' => 60.0], $z['letzte']);
        self::assertSame('2027-03-01', $z['faelligkeit']->faelligAm?->format('Y-m-d'));
        self::assertSame(80.0, $z['faelligkeit']->faelligBeiStunden);
        self::assertSame(Ampel::Gruen, $z['faelligkeit']->ampel);
    }

    public function test_eine_wartung_mehrere_arten(): void
    {
        $lok = $this->fahrzeug('lok');
        $this->wartung('fahrzeug', $lok, '2026-10-01', ['Räder und Radschleifer reinigen', 'Kupplungen prüfen'], 40.0);
        $z = self::nachArt($this->fuerObjekt('fahrzeug', $lok));
        self::assertSame('2026-10-01', $z['Räder und Radschleifer reinigen']['letzte']['datum']);
        self::assertSame('2026-10-01', $z['Kupplungen prüfen']['letzte']['datum']);
        self::assertSame(Ampel::Gruen, $z['Kupplungen prüfen']['faelligkeit']->ampel);
        self::assertTrue($z['Motor/Kohlen prüfen']['faelligkeit']->nochNieErfasst);
    }

    public function test_gattungswechsel(): void
    {
        $fz = $this->fahrzeug('wagen');
        $this->wartung('fahrzeug', $fz, '2026-10-01', ['Kupplungen prüfen']);
        self::assertSame(['Räder und Radschleifer reinigen', 'Kupplungen prüfen'], array_keys(self::nachArt($this->fuerObjekt('fahrzeug', $fz))));

        $this->pdo->exec("UPDATE fahrzeug SET gattung = 'lok' WHERE id = $fz");
        $z = self::nachArt($this->fuerObjekt('fahrzeug', $fz));
        self::assertCount(5, $z);
        foreach (['Getriebe und Lager schmieren', 'Haftreifen prüfen/tauschen', 'Motor/Kohlen prüfen'] as $name) {
            self::assertTrue($z[$name]['faelligkeit']->nochNieErfasst, $name);
        }
        self::assertSame('2026-10-01', $z['Kupplungen prüfen']['letzte']['datum']);
        self::assertSame(Ampel::Gruen, $z['Kupplungen prüfen']['faelligkeit']->ampel);

        // Auch die Übersicht folgt sofort.
        $ueb = $this->plan()->uebersicht(new WartungFilter(), $this->heute);
        self::assertCount(5, $ueb);
    }

    public function test_uebersicht_filter_und_sortierung(): void
    {
        $this->pdo->exec("UPDATE wartungsart SET aktiv = 0 WHERE name NOT IN ('Kupplungen prüfen', 'Gleise reinigen')");
        $mitglied = $this->person();
        $f1 = $this->fahrzeug('lok', bezeichnung: 'Vereinslok');
        $f2 = $this->fahrzeug('wagen', $mitglied, bezeichnung: 'Mitgliederwagen');
        $f3 = $this->fahrzeug('lok', spurweite: $this->n, bezeichnung: 'N-Lok');
        $f4 = $this->fahrzeug('lok', status: 'ausgemustert');
        $a1 = $this->anlage(name: 'Clubanlage H0');
        $a2 = $this->anlage($this->n, 'Clubanlage N');
        $this->wartung('fahrzeug', $f1, $this->heute->modify('-13 months')->format('Y-m-d'), ['Kupplungen prüfen']);
        $this->wartung('fahrzeug', $f2, $this->heute->modify('-14 months')->format('Y-m-d'), ['Kupplungen prüfen']);
        $this->wartung('fahrzeug', $f4, $this->heute->modify('-20 months')->format('Y-m-d'), ['Kupplungen prüfen']);
        $this->wartung('anlage', $a1, $this->heute->modify('-75 days')->format('Y-m-d'), ['Gleise reinigen']);
        $this->wartung('anlage', $a2, $this->heute->modify('-7 days')->format('Y-m-d'), ['Gleise reinigen']);

        $schluessel = fn(array $zeilen): array => array_map(fn($z) => $z['objekt_typ'] . ':' . $z['objekt']['id'], $zeilen);
        $alle = $this->plan()->uebersicht(new WartungFilter(), $this->heute);
        self::assertSame(["fahrzeug:$f2", "fahrzeug:$f1", "anlage:$a1", "fahrzeug:$f3", "anlage:$a2"], $schluessel($alle));
        self::assertSame([Ampel::Rot, Ampel::Rot, Ampel::Gelb, Ampel::Gelb, Ampel::Gruen], array_map(fn($z) => $z['faelligkeit']->ampel, $alle));
        self::assertFalse($alle[2]['faelligkeit']->nochNieErfasst);
        self::assertTrue($alle[3]['faelligkeit']->nochNieErfasst);
        self::assertSame('A1', $alle[2]['objekt']['code']);
        self::assertSame('Kupplungen prüfen', $alle[0]['art']['name']);
        self::assertNotNull($alle[0]['letzte']);

        self::assertSame(["anlage:$a1", "anlage:$a2"], $schluessel($this->plan()->uebersicht(new WartungFilter('anlage'), $this->heute)));
        self::assertSame(["fahrzeug:$f2", "fahrzeug:$f1", "fahrzeug:$f3"], $schluessel($this->plan()->uebersicht(new WartungFilter('fahrzeug'), $this->heute)));
        self::assertSame(["fahrzeug:$f2"], $schluessel($this->plan()->uebersicht(new WartungFilter(null, null, 'mitglied'), $this->heute)));
        self::assertSame(["fahrzeug:$f1", "anlage:$a1", "fahrzeug:$f3", "anlage:$a2"], $schluessel($this->plan()->uebersicht(new WartungFilter(null, null, 'verein'), $this->heute)));
        self::assertSame(["fahrzeug:$f3", "anlage:$a2"], $schluessel($this->plan()->uebersicht(new WartungFilter(null, $this->n), $this->heute)));
        self::assertSame(["anlage:$a2"], $schluessel($this->plan()->uebersicht(new WartungFilter('anlage', $this->n, 'verein'), $this->heute)));

        $s = $this->plan()->zusammenfassung($this->heute);
        self::assertSame(2, $s['rot']);
        self::assertSame(2, $s['gelb']);
        self::assertSame(["fahrzeug:$f2", "fahrzeug:$f1", "anlage:$a1", "fahrzeug:$f3"], $schluessel($s['dringendste']));
    }

    public function test_zusammenfassung_hoechstens_fuenf(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->fahrzeug('lok'); // je 5 Arten „noch nie erfasst“
        }
        $s = $this->plan()->zusammenfassung($this->heute);
        self::assertSame(0, $s['rot']);
        self::assertSame(15, $s['gelb']);
        self::assertCount(5, $s['dringendste']);
    }

    public function test_uebersicht_ohne_abfrage_je_zeile(): void
    {
        $abfragen = function (): int {
            $zaehle = fn(): int => (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];
            $vorher = $zaehle();
            $this->plan()->uebersicht(new WartungFilter(), $this->heute);
            return $zaehle() - $vorher;
        };
        $fz = $this->fahrzeug('lok');
        $this->wartung('fahrzeug', $fz, '2026-01-01', ['Kupplungen prüfen']);
        $this->anlage();
        $wenige = $abfragen();
        for ($i = 0; $i < 6; $i++) {
            $fz = $this->fahrzeug($i % 2 === 0 ? 'lok' : 'wagen');
            $this->wartung('fahrzeug', $fz, '2026-0' . ($i + 1) . '-01', ['Kupplungen prüfen', 'Räder und Radschleifer reinigen'], 10.0);
            (new AusnahmeRepository($this->pdo))->speichere('fahrzeug', $fz, $this->art('Kupplungen prüfen'), 'eigen', 6, null);
            $a = $this->anlage();
            $this->wartung('anlage', $a, '2026-05-01', ['Gleise reinigen']);
        }
        self::assertCount(7 * 5 - 3 * 3 + 7 * 3, $this->plan()->uebersicht(new WartungFilter(), $this->heute));
        self::assertSame($wenige, $abfragen());
        self::assertLessThanOrEqual(10, $wenige);
    }
}
