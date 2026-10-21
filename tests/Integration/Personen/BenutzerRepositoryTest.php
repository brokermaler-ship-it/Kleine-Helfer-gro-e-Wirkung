<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Personen;

use Emc\Auth\Rolle;
use Emc\Core\DomainFehler;
use Emc\Personen\BenutzerRepository;
use Emc\Personen\PersonRepository;
use Emc\Tests\Integration\DbTestCase;

final class BenutzerRepositoryTest extends DbTestCase
{
    private function repo(): BenutzerRepository
    {
        return new BenutzerRepository($this->pdo);
    }

    private function person(string $n = 'Bauer'): int
    {
        return (new PersonRepository($this->pdo))->speichern(['vorname' => 'Anna', 'nachname' => $n]);
    }

    public function test_doppelte_email_wird_abgelehnt(): void
    {
        $r = $this->repo();
        $r->anlegen($this->person(), 'Anna@Verein.de', Rolle::Mitglied, 'start-passwort-1');
        $this->expectException(DomainFehler::class);
        $this->expectExceptionMessage('Diese E-Mail-Adresse ist bereits vergeben.');
        $r->anlegen($this->person('Zweit'), 'anna@VEREIN.de', Rolle::Mitglied, 'start-passwort-1');
    }

    public function test_email_wird_klein_gespeichert(): void
    {
        $id = $this->repo()->anlegen($this->person(), ' Anna@Verein.DE ', Rolle::Mitglied, 'start-passwort-1');
        $this->assertSame('anna@verein.de', $this->pdo->query("SELECT email FROM benutzer WHERE id = $id")->fetchColumn());
    }

    public function test_neuer_benutzer_muss_passwort_aendern(): void
    {
        $r = $this->repo();
        $id = $r->anlegen($this->person(), 'a@verein.de', Rolle::Mitglied, 'start-passwort-1');
        $z = $this->pdo->query("SELECT * FROM benutzer WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $z['muss_passwort_aendern']);
        $this->assertTrue(password_verify('start-passwort-1', $z['passwort_hash']));

        $r->passwortSetzen($id, 'neues-passwort-22', false);
        $z = $this->pdo->query("SELECT * FROM benutzer WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $z['muss_passwort_aendern']);
        $this->assertTrue(password_verify('neues-passwort-22', $z['passwort_hash']));

        $r->passwortSetzen($id, 'start-3-passwort', true);
        $this->assertSame(1, (int) $this->pdo->query("SELECT muss_passwort_aendern FROM benutzer WHERE id = $id")->fetchColumn());
    }

    public function test_passwortregel(): void
    {
        $this->assertSame('Das Passwort muss mindestens 10 Zeichen lang sein.', BenutzerRepository::pruefePasswort('kurz'));
        $this->assertNull(BenutzerRepository::pruefePasswort('lang-genug-1'));
    }

    public function test_anlegen_mit_kurzem_passwort_wird_abgelehnt(): void
    {
        $this->expectException(DomainFehler::class);
        $this->repo()->anlegen($this->person(), 'a@verein.de', Rolle::Mitglied, 'kurz');
    }

    public function test_person_kann_nur_einen_zugang_haben(): void
    {
        $r = $this->repo();
        $p = $this->person();
        $r->anlegen($p, 'a@verein.de', Rolle::Mitglied, 'start-passwort-1');
        $this->expectException(DomainFehler::class);
        $r->anlegen($p, 'b@verein.de', Rolle::Mitglied, 'start-passwort-1');
    }

    public function test_letzter_admin_bleibt(): void
    {
        $r = $this->repo();
        $id = $r->anlegen($this->person(), 'admin@verein.de', Rolle::Admin, 'start-passwort-1');
        $meldung = 'Es muss mindestens ein aktiver Administrator bleiben.';
        foreach ([
            fn() => $r->aendern($id, Rolle::Bearbeiter, true, false),
            fn() => $r->aendern($id, Rolle::Admin, false, false),
            fn() => $r->loeschen($id),
        ] as $aktion) {
            try {
                $aktion();
                $this->fail('DomainFehler erwartet');
            } catch (DomainFehler $e) {
                $this->assertSame($meldung, $e->getMessage());
            }
        }
        $this->assertCount(1, $r->alle());

        // Mit einem zweiten aktiven Admin geht es.
        $zweiter = $r->anlegen($this->person('Zweit'), 'zwei@verein.de', Rolle::Admin, 'start-passwort-1');
        $r->aendern($id, Rolle::Bearbeiter, true, false);
        $r->loeschen($id);
        $this->assertCount(1, $r->alle());
        $this->assertSame($zweiter, (int) $r->alle()[0]['id']);
    }

    /** Das Häkchen muss beim Ändern immer angegeben werden, damit kein Aufrufer es versehentlich entfernt. */
    public function test_aendern_verlangt_das_boersenwart_haekchen(): void
    {
        $param = (new \ReflectionMethod(BenutzerRepository::class, 'aendern'))->getParameters()[3];
        $this->assertSame('istBoersenwart', $param->getName());
        $this->assertFalse($param->isOptional());

        $r = new BenutzerRepository($this->pdo);
        $id = $this->legeBenutzerAn('greta@verein.de', 'geheim-passwort-1', 'fahrzeugwart');
        $r->aendern($id, Rolle::Fahrzeugwart, true, true);
        $this->assertSame(1, (int) $r->finde($id)['ist_boersenwart']);
        $r->aendern($id, Rolle::Fahrzeugwart, true, false);
        $this->assertSame(0, (int) $r->finde($id)['ist_boersenwart']);
    }
}
