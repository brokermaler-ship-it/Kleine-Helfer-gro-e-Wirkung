<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Boerse;

use Emc\Boerse\AngebotValidator;
use Emc\Boerse\BoerseValidator;
use PHPUnit\Framework\TestCase;

final class BoerseValidatorTest extends TestCase
{
    public function test_provision(): void
    {
        $this->assertSame('10.00', BoerseValidator::provision('10'));
        $this->assertSame('7.50', BoerseValidator::provision('7,5'));
        $this->assertSame('0.00', BoerseValidator::provision('0'));
        $this->assertSame('100.00', BoerseValidator::provision('100'));
        $this->assertSame('12.25', BoerseValidator::provision('012.25'));
        foreach (['100,5', '100.01', '-1', '7,555', 'zehn', '1000', ''] as $falsch) {
            $this->assertNull(BoerseValidator::provision($falsch), $falsch);
        }
        $this->assertSame('7,5', BoerseValidator::provisionAnzeige('7.50'));
        $this->assertSame('10', BoerseValidator::provisionAnzeige('10.00'));
    }

    public function test_pruefe(): void
    {
        [$d, $f] = BoerseValidator::pruefe(['name' => ' Herbstbörse ', 'datum' => '08.11.2026', 'ort' => '', 'provision_prozent' => ''], false);
        $this->assertSame([], $f);
        $this->assertSame(['name' => 'Herbstbörse', 'datum' => '2026-11-08', 'ort' => null, 'provision_prozent' => '10.00'], $d);
        [, $f] = BoerseValidator::pruefe(['name' => '', 'datum' => '31.02.2026', 'provision_prozent' => '100,5'], false);
        $this->assertSame(['name', 'datum', 'provision_prozent'], array_keys($f));
        $this->assertSame(BoerseValidator::KEINE_PROVISION, $f['provision_prozent']);
        [, $f] = BoerseValidator::pruefe(['name' => 'X', 'datum' => '2026-11-08', 'provision_prozent' => '12'], true, '10.00');
        $this->assertSame(BoerseValidator::PROVISION_GESPERRT, $f['provision_prozent']);
        [, $f] = BoerseValidator::pruefe(['name' => 'X', 'datum' => '2026-11-08', 'provision_prozent' => '10,0'], true, '10.00');
        $this->assertSame([], $f, 'gleiche Provision anders geschrieben');
    }

    public function test_angebot_mitglied_und_boersenwart(): void
    {
        $eingabe = ['bezeichnung' => 'Lok', 'kategorie_id' => '9', 'zustand' => 'gebraucht', 'wunschpreis' => '12,5', 'herkunft' => 'verein', 'person_id' => '4', 'erloes_spende' => '1', 'boerse_id' => 'dauerhaft', 'menge' => ''];
        [$d, $f] = AngebotValidator::pruefe($eingabe, false, [7], [1, 2]);
        $this->assertSame([], $f);
        $this->assertSame(['mitglied', 7, 1, '12.50', null, 1], [$d['herkunft'], $d['person_id'], $d['erloes_spende'], $d['wunschpreis'], $d['boerse_id'], $d['menge']]);

        [$d, $f] = AngebotValidator::pruefe($eingabe, true, [4, 7], [1, 2]);
        $this->assertSame(['verein', null, 0], [$d['herkunft'], $d['person_id'], $d['erloes_spende']], 'Spende nur bei Mitgliedern');
        [, $f] = AngebotValidator::pruefe(['herkunft' => 'mitglied', 'person_id' => ''] + $eingabe, true, [4, 7], [1, 2]);
        $this->assertSame(AngebotValidator::KEIN_MITGLIED, $f['person_id']);
        [, $f] = AngebotValidator::pruefe(['wunschpreis' => '1.234,50', 'boerse_id' => '3', 'menge' => '10000', 'bezeichnung' => '', 'zustand' => 'kaputt'] + $eingabe, true, [4], [1, 2], [9]);
        $this->assertSame(['bezeichnung', 'zustand', 'wunschpreis', 'boerse_id', 'menge'], array_keys($f));
        $this->assertSame('Bitte den Betrag angeben, z. B. 12,50.', $f['wunschpreis']);
        [, $f] = AngebotValidator::pruefe(['kategorie_id' => '8'] + $eingabe, true, [4], [1], [9]);
        $this->assertArrayHasKey('kategorie_id', $f);
    }

    /** M6: Konto ohne Person – keine Angebote als „meine“, verständliche Meldung beim Anlegen. */
    public function test_ohne_person(): void
    {
        $b = new \Emc\Auth\Benutzer(5, 0, 'x@verein.de', 'X', \Emc\Auth\Rolle::Mitglied, false);
        $f = \Emc\Boerse\AngebotFilter::ausRequest(new \Emc\Core\Request('GET', '/angebote', ['meine' => '1']), $b);
        $this->assertSame([], $f->ids);
        $this->assertTrue($f->meine);
        $eingabe = ['bezeichnung' => 'Lok', 'kategorie_id' => '9', 'zustand' => 'gebraucht', 'wunschpreis' => '5'];
        [, $fehler] = AngebotValidator::pruefe($eingabe, false, [0], []);
        $this->assertSame(AngebotValidator::KEINE_PERSON, $fehler['person_id']);
        $this->assertSame('Deinem Konto ist keine Person zugeordnet.', AngebotValidator::KEINE_PERSON);
    }
}
