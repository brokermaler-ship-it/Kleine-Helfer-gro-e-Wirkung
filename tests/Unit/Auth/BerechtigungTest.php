<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Auth;

use Emc\Auth\Benutzer;
use Emc\Auth\Berechtigung;
use Emc\Auth\Rolle;
use PHPUnit\Framework\TestCase;

final class BerechtigungTest extends TestCase
{
    private function benutzer(Rolle $r): Benutzer
    {
        return new Benutzer(1, 1, 'a@b.de', 'A B', $r, false);
    }

    public function test_rechte_matrix(): void
    {
        $m = $this->benutzer(Rolle::Mitglied);
        $b = $this->benutzer(Rolle::Bearbeiter);
        $a = $this->benutzer(Rolle::Admin);
        $this->assertFalse(Berechtigung::darf(null, Berechtigung::LESEN));
        $this->assertTrue(Berechtigung::darf($m, Berechtigung::LESEN));
        $this->assertFalse(Berechtigung::darf($m, Berechtigung::FAHRZEUG_BEARBEITEN));
        $this->assertTrue(Berechtigung::darf($b, Berechtigung::FAHRZEUG_BEARBEITEN));
        $this->assertTrue(Berechtigung::darf($b, Berechtigung::PERSON_BEARBEITEN));
        $this->assertTrue(Berechtigung::darf($b, Berechtigung::DATEI_BEARBEITEN));
        $this->assertFalse(Berechtigung::darf($b, Berechtigung::BENUTZER_VERWALTEN));
        $this->assertFalse(Berechtigung::darf($b, Berechtigung::BETRIEB));
        foreach ([Berechtigung::BENUTZER_VERWALTEN, Berechtigung::STAMMDATEN_VERWALTEN, Berechtigung::BETRIEB] as $x) {
            $this->assertTrue(Berechtigung::darf($a, $x));
        }
    }

    public function test_fahrzeug_anlegen_ab_mitglied(): void
    {
        $this->assertFalse(Berechtigung::darf(null, Berechtigung::FAHRZEUG_ANLEGEN));
        foreach ([Rolle::Mitglied, Rolle::Bearbeiter, Rolle::Admin] as $r) {
            $this->assertTrue(Berechtigung::darf($this->benutzer($r), Berechtigung::FAHRZEUG_ANLEGEN));
        }
    }

    public function test_darf_fahrzeug_bearbeiten_je_objekt(): void
    {
        $m = $this->benutzer(Rolle::Mitglied); // id 1
        $b = $this->benutzer(Rolle::Bearbeiter);
        $a = $this->benutzer(Rolle::Admin);
        $eigenes = ['angelegt_von_benutzer_id' => '1'];
        $fremdes = ['angelegt_von_benutzer_id' => 2];
        $altbestand = ['angelegt_von_benutzer_id' => null];
        $this->assertTrue(Berechtigung::darfFahrzeugBearbeiten($m, $eigenes));
        $this->assertFalse(Berechtigung::darfFahrzeugBearbeiten($m, $fremdes));
        $this->assertFalse(Berechtigung::darfFahrzeugBearbeiten($m, $altbestand));
        $this->assertFalse(Berechtigung::darfFahrzeugBearbeiten($m, []));
        $this->assertFalse(Berechtigung::darfFahrzeugBearbeiten(null, $eigenes));
        foreach ([$b, $a] as $x) {
            foreach ([$eigenes, $fremdes, $altbestand] as $fz) {
                $this->assertTrue(Berechtigung::darfFahrzeugBearbeiten($x, $fz));
            }
        }
    }

    public function test_unbekannte_aktion_ist_verboten(): void
    {
        $this->assertFalse(Berechtigung::darf($this->benutzer(Rolle::Admin), 'gibt_es_nicht'));
    }

    public function test_rollen_labels(): void
    {
        $this->assertSame('Mitglied', Rolle::Mitglied->label());
        $this->assertSame('Bearbeiter', Rolle::Bearbeiter->label());
        $this->assertSame('Administrator', Rolle::Admin->label());
    }
}
