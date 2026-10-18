<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Personen;

use Emc\Core\DomainFehler;
use Emc\Personen\PersonRepository;
use Emc\Tests\Integration\DbTestCase;

final class PersonRepositoryTest extends DbTestCase
{
    private function repo(): PersonRepository
    {
        return new PersonRepository($this->pdo);
    }

    public function test_anlegen_finden_aendern_und_sortierung(): void
    {
        $r = $this->repo();
        $b = $r->speichern(['vorname' => 'Zoe', 'nachname' => 'Bauer']);
        $a = $r->speichern(['vorname' => 'Anna', 'nachname' => 'Bauer', 'email' => 'a@x.de']);
        $r->speichern(['vorname' => 'Ina', 'nachname' => 'Adler', 'aktiv' => false]);

        $this->assertSame('Anna', $r->finde($a)['vorname']);
        $this->assertSame(['Anna', 'Zoe'], array_column($r->alle(), 'vorname'));
        $this->assertSame(['Ina', 'Anna', 'Zoe'], array_column($r->alle(false), 'vorname'));

        $r->speichern(['vorname' => 'Zora', 'nachname' => 'Bauer'], $b);
        $this->assertSame('Zora', $r->finde($b)['vorname']);
    }

    public function test_person_mit_fahrzeug_kann_nicht_geloescht_werden(): void
    {
        $r = $this->repo();
        $id = $r->speichern(['vorname' => 'Anna', 'nachname' => 'Bauer']);
        $this->pdo->prepare(
            "INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, eigentuemer_person_id) VALUES ('EMC-0001', 'V 200', (SELECT MIN(id) FROM spurweite), ?)"
        )->execute([$id]);
        try {
            $r->loeschen($id);
            $this->fail('DomainFehler erwartet');
        } catch (DomainFehler $e) {
            $this->assertSame('Diese Person kann nicht gelöscht werden, weil ihr noch Fahrzeuge gehören.', $e->getMessage());
        }
        $this->assertNotNull($r->finde($id));
    }

    public function test_person_mit_zugang_kann_nicht_geloescht_werden(): void
    {
        $bid = $this->legeBenutzerAn('max@verein.de');
        $pid = (int) $this->pdo->query("SELECT person_id FROM benutzer WHERE id = $bid")->fetchColumn();
        $this->expectException(DomainFehler::class);
        $this->expectExceptionMessage('Diese Person hat einen Zugang. Bitte zuerst den Zugang löschen oder die Person deaktivieren.');
        $this->repo()->loeschen($pid);
    }

    public function test_person_ohne_bezuege_wird_geloescht(): void
    {
        $r = $this->repo();
        $id = $r->speichern(['vorname' => 'Anna', 'nachname' => 'Bauer']);
        $r->loeschen($id);
        $this->assertNull($r->finde($id));
    }
}
