<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Stammdaten;

use Emc\Core\App;
use Emc\Core\DomainFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Stammdaten\SpurweiteRepository;
use Emc\Tests\Integration\DbTestCase;

final class SpurweiteRepositoryTest extends DbTestCase
{
    private array $sitzung = [];

    private function repo(): SpurweiteRepository
    {
        return new SpurweiteRepository($this->pdo);
    }

    /** Die Stammliste wird zwischen Tests nicht geleert: Testdaten wieder entfernen. */
    protected function tearDown(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->exec('TRUNCATE TABLE fahrzeug');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->exec("DELETE FROM spurweite WHERE name NOT IN ('H0','H0e','H0m','TT','N','Z','0','1','G/IIm')");
        parent::tearDown();
    }

    public function test_startbestand_ist_vorhanden(): void
    {
        $alle = $this->repo()->alle();
        $this->assertCount(9, $alle);
        $this->assertSame('H0', $alle[0]['name']);
        $this->assertSame('G/IIm', $alle[8]['name']);
    }

    public function test_name_eindeutig(): void
    {
        $this->expectException(DomainFehler::class);
        $this->expectExceptionMessage('Diese Spurweite gibt es bereits.');
        $this->repo()->speichern('N', '1:160', 31);
    }

    public function test_name_eindeutig_ohne_beachtung_der_gross_kleinschreibung_auch_beim_aendern(): void
    {
        $r = $this->repo();
        $id = $r->speichern('Sonder', null, 99);
        try {
            $r->speichern('tt', null, 99, $id);
            $this->fail('DomainFehler erwartet');
        } catch (DomainFehler $e) {
            $this->assertSame('Diese Spurweite gibt es bereits.', $e->getMessage());
        }
        // Eigenen Namen erneut speichern ist erlaubt.
        $r->speichern('Sonder', '1:50', 98, $id);
        $this->assertSame('1:50', $r->finde($id)['massstab']);
    }

    public function test_anlegen_aendern_finden_und_loeschen(): void
    {
        $r = $this->repo();
        $id = $r->speichern('  Sonder ', '', 5);
        $z = $r->finde($id);
        $this->assertSame('Sonder', $z['name']);
        $this->assertNull($z['massstab']);
        $this->assertSame('Sonder', $r->alle()[0]['name']); // Sortierung 5 vor H0 (10)
        $r->loeschen($id);
        $this->assertNull($r->finde($id));
        $this->assertCount(9, $r->alle());
    }

    public function test_verwendete_spurweite_nicht_loeschbar(): void
    {
        $r = $this->repo();
        $id = $r->speichern('Sonder', null, 99);
        $this->pdo->prepare("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) VALUES ('EMC-0001', 'V 200', ?)")->execute([$id]);
        try {
            $r->loeschen($id);
            $this->fail('DomainFehler erwartet');
        } catch (DomainFehler $e) {
            $this->assertSame('Diese Spurweite wird noch von Fahrzeugen verwendet.', $e->getMessage());
        }
        $this->assertNotNull($r->finde($id));
    }

    private function http(string $methode, string $pfad, array $daten = []): Response
    {
        $app = App::erzeuge(self::testConfig(), new Session($this->sitzung));
        return $app->handle(new Request($methode, $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)]));
    }

    private function anmelden(string $rolle): void
    {
        $this->sitzungAnmelden($this->sitzung, $this->legeBenutzerAn('x@verein.de', 'geheim-passwort-1', $rolle));
        $this->sitzung['_csrf'] = str_repeat('a', 64);
    }

    public function test_http_nur_admin_und_anlegen_loeschen(): void
    {
        $this->anmelden('bearbeiter');
        $this->assertSame(403, $this->http('GET', '/admin/spurweiten')->status);

        $this->pdo->exec('DELETE FROM benutzer');
        $this->anmelden('admin');
        $liste = $this->http('GET', '/admin/spurweiten');
        $this->assertSame(200, $liste->status);
        $this->assertStringContainsString('G/IIm', $liste->body);

        $this->assertSame(303, $this->http('POST', '/admin/spurweiten', ['name' => 'Sonder', 'massstab' => '1:50', 'sortierung' => '99'])->status);
        $fehler = $this->http('POST', '/admin/spurweiten', ['name' => 'sonder', 'massstab' => '', 'sortierung' => '5']);
        $this->assertSame(422, $fehler->status);
        $this->assertStringContainsString('Diese Spurweite gibt es bereits.', $fehler->body);

        $id = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'Sonder'")->fetchColumn();
        $this->assertSame(303, $this->http('POST', '/admin/spurweiten/' . $id . '/loeschen')->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM spurweite WHERE name = 'Sonder'")->fetchColumn());
    }
}
