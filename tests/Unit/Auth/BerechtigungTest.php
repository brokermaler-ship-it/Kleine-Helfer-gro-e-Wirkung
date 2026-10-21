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
        foreach (Rolle::cases() as $r) {
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

    public function test_fahrzeugwart_darf_fahrzeuge_aber_nicht_personen(): void
    {
        $w = $this->benutzer(Rolle::Fahrzeugwart); // id 1
        $this->assertTrue(Berechtigung::darf($w, Berechtigung::LESEN));
        $this->assertTrue(Berechtigung::darf($w, Berechtigung::FAHRZEUG_ANLEGEN));
        $this->assertTrue(Berechtigung::darf($w, Berechtigung::FAHRZEUG_BEARBEITEN));
        $this->assertFalse(Berechtigung::darf($w, Berechtigung::PERSON_BEARBEITEN));
        $this->assertFalse(Berechtigung::darf($w, Berechtigung::DATEI_BEARBEITEN));
        $this->assertFalse(Berechtigung::darf($w, Berechtigung::BENUTZER_VERWALTEN));
        $this->assertFalse(Berechtigung::darf($w, Berechtigung::STAMMDATEN_VERWALTEN));
        $this->assertFalse(Berechtigung::darf($w, Berechtigung::BETRIEB));
        $this->assertFalse(Berechtigung::darf($w, Berechtigung::PERSON_DSGVO_LOESCHEN));
        foreach ([['angelegt_von_benutzer_id' => 2], ['angelegt_von_benutzer_id' => null], []] as $fz) {
            $this->assertTrue(Berechtigung::darfFahrzeugBearbeiten($w, $fz));
        }
    }

    public function test_rangfolge(): void
    {
        $alle = [
            Berechtigung::LESEN, Berechtigung::FAHRZEUG_ANLEGEN, Berechtigung::FAHRZEUG_BEARBEITEN,
            Berechtigung::PERSON_BEARBEITEN, Berechtigung::DATEI_BEARBEITEN, Berechtigung::BENUTZER_VERWALTEN,
            Berechtigung::STAMMDATEN_VERWALTEN, Berechtigung::BETRIEB, Berechtigung::PERSON_DSGVO_LOESCHEN,
            Berechtigung::ANLAGE_BEARBEITEN, Berechtigung::WARTUNGSART_VERWALTEN,
        ];
        $w = $this->benutzer(Rolle::Fahrzeugwart);
        $b = $this->benutzer(Rolle::Bearbeiter);
        foreach ($alle as $x) {
            if (Berechtigung::darf($w, $x)) {
                $this->assertTrue(Berechtigung::darf($b, $x), "Bearbeiter muss $x dürfen wie der Fahrzeugwart");
            }
        }
        $this->assertTrue(Berechtigung::darf($b, Berechtigung::PERSON_BEARBEITEN));
        $this->assertTrue(Berechtigung::darf($b, Berechtigung::DATEI_BEARBEITEN));
        foreach ([Rolle::Mitglied, Rolle::Fahrzeugwart, Rolle::Bearbeiter] as $r) {
            $this->assertFalse(Berechtigung::darf($this->benutzer($r), Berechtigung::PERSON_DSGVO_LOESCHEN));
        }
        $this->assertTrue(Berechtigung::darf($this->benutzer(Rolle::Admin), Berechtigung::PERSON_DSGVO_LOESCHEN));
        $this->assertSame([Rolle::Mitglied, Rolle::Fahrzeugwart, Rolle::Bearbeiter, Rolle::Admin], Rolle::cases());
    }

    public function test_anlage_bearbeiten_ab_bearbeiter(): void
    {
        $this->assertFalse(Berechtigung::darf(null, Berechtigung::ANLAGE_BEARBEITEN));
        $this->assertFalse(Berechtigung::darf($this->benutzer(Rolle::Mitglied), Berechtigung::ANLAGE_BEARBEITEN));
        $this->assertFalse(Berechtigung::darf($this->benutzer(Rolle::Fahrzeugwart), Berechtigung::ANLAGE_BEARBEITEN));
        $this->assertTrue(Berechtigung::darf($this->benutzer(Rolle::Bearbeiter), Berechtigung::ANLAGE_BEARBEITEN));
        $this->assertTrue(Berechtigung::darf($this->benutzer(Rolle::Admin), Berechtigung::ANLAGE_BEARBEITEN));
        $this->assertSame('anlage_bearbeiten', Berechtigung::ANLAGE_BEARBEITEN);
    }

    public function test_wartungsart_verwalten_ab_fahrzeugwart(): void
    {
        $this->assertFalse(Berechtigung::darf(null, Berechtigung::WARTUNGSART_VERWALTEN));
        $this->assertFalse(Berechtigung::darf($this->benutzer(Rolle::Mitglied), Berechtigung::WARTUNGSART_VERWALTEN));
        foreach ([Rolle::Fahrzeugwart, Rolle::Bearbeiter, Rolle::Admin] as $r) {
            $this->assertTrue(Berechtigung::darf($this->benutzer($r), Berechtigung::WARTUNGSART_VERWALTEN));
        }
        $this->assertSame('wartungsart_verwalten', Berechtigung::WARTUNGSART_VERWALTEN);
    }

    public function test_unbekannte_aktion_ist_verboten(): void
    {
        $this->assertFalse(Berechtigung::darf($this->benutzer(Rolle::Admin), 'gibt_es_nicht'));
    }

    public function test_rollen_labels(): void
    {
        $this->assertSame('Mitglied', Rolle::Mitglied->label());
        $this->assertSame('Fahrzeugwart', Rolle::Fahrzeugwart->label());
        $this->assertSame('fahrzeugwart', Rolle::Fahrzeugwart->value);
        $this->assertSame('Bearbeiter', Rolle::Bearbeiter->label());
        $this->assertSame('Administrator', Rolle::Admin->label());
    }
}
