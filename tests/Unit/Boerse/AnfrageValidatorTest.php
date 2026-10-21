<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Boerse;

use Emc\Boerse\AnfrageValidator;
use PHPUnit\Framework\TestCase;

final class AnfrageValidatorTest extends TestCase
{
    /** @return array<string,mixed> */
    private function eingabe(array $mehr = []): array
    {
        return $mehr + ['name' => ' Erika Beispiel ', 'email' => 'erika@test.emc78.de', 'telefon' => '', 'nachricht' => 'Ist die Lok noch da?', 'einwilligung' => '1'];
    }

    public function test_gueltige_kaufanfrage(): void
    {
        [$d, $f] = AnfrageValidator::pruefe($this->eingabe(), 'kaufanfrage');
        $this->assertSame([], $f);
        $this->assertSame(['name' => 'Erika Beispiel', 'email' => 'erika@test.emc78.de', 'telefon' => null, 'nachricht' => 'Ist die Lok noch da?', 'wunsch' => null], $d);
    }

    public function test_ohne_email_und_telefon(): void
    {
        [, $f] = AnfrageValidator::pruefe($this->eingabe(['email' => '', 'telefon' => ' ']), 'kaufanfrage');
        $this->assertSame('Bitte gib eine E-Mail-Adresse oder eine Telefonnummer an.', $f['kontakt'] ?? null);
    }

    public function test_nur_telefon(): void
    {
        [$d, $f] = AnfrageValidator::pruefe($this->eingabe(['email' => '', 'telefon' => '+49 (345) 123/45-6']), 'kaufanfrage');
        $this->assertSame([], $f);
        $this->assertNull($d['email']);
        $this->assertSame('+49 (345) 123/45-6', $d['telefon']);
    }

    public function test_ungueltige_angaben(): void
    {
        [, $f] = AnfrageValidator::pruefe($this->eingabe(['email' => 'keine-adresse', 'telefon' => '12ab']), 'kaufanfrage');
        $this->assertArrayHasKey('email', $f);
        $this->assertArrayHasKey('telefon', $f);
        [, $f] = AnfrageValidator::pruefe($this->eingabe(['name' => '', 'nachricht' => ' ']), 'kaufanfrage');
        $this->assertSame('Bitte gib deinen Namen an.', $f['name']);
        $this->assertSame('Bitte schreib uns kurz, worum es geht.', $f['nachricht']);
        [, $f] = AnfrageValidator::pruefe($this->eingabe(['name' => str_repeat('a', 101), 'nachricht' => str_repeat('b', 3001), 'email' => str_repeat('c', 185) . '@x.de']), 'kaufanfrage');
        $this->assertArrayHasKey('name', $f);
        $this->assertArrayHasKey('nachricht', $f);
        $this->assertArrayHasKey('email', $f);
        // Felder als Array (?name[]=…) gelten als leer, kein Fehler 500.
        [, $f] = AnfrageValidator::pruefe($this->eingabe(['name' => ['x']]), 'kaufanfrage');
        $this->assertSame('Bitte gib deinen Namen an.', $f['name']);
    }

    public function test_ohne_einwilligung(): void
    {
        foreach ([[], ['einwilligung' => '0'], ['einwilligung' => 'on']] as $mehr) {
            $e = $this->eingabe();
            unset($e['einwilligung']);
            [, $f] = AnfrageValidator::pruefe($mehr + $e, 'kaufanfrage');
            $this->assertSame('Bitte stimme der Verarbeitung deiner Angaben zu.', $f['einwilligung'] ?? null);
        }
    }

    public function test_sammlung(): void
    {
        [, $f] = AnfrageValidator::pruefe($this->eingabe(['nachricht' => '']), 'sammlung');
        $this->assertSame('Bitte beschreib die Sammlung.', $f['nachricht']);
        $this->assertSame('Bitte wähle, ob du verkaufen oder spenden möchtest.', $f['wunsch']);
        [, $f] = AnfrageValidator::pruefe($this->eingabe(['wunsch' => 'tauschen']), 'sammlung');
        $this->assertArrayHasKey('wunsch', $f);
        [$d, $f] = AnfrageValidator::pruefe($this->eingabe(['wunsch' => 'spenden']), 'sammlung');
        $this->assertSame([], $f);
        $this->assertSame('spenden', $d['wunsch']);
        // Bei der Kaufanfrage zählt kein Wunsch.
        [$d] = AnfrageValidator::pruefe($this->eingabe(['wunsch' => 'spenden']), 'kaufanfrage');
        $this->assertNull($d['wunsch']);
    }

    public function test_hoechstens_fuenf_fotos(): void
    {
        [, $f] = AnfrageValidator::pruefe($this->eingabe(['wunsch' => 'verkaufen']), 'sammlung', 6);
        $this->assertSame('Bitte höchstens 5 Fotos auswählen.', $f['fotos'] ?? null);
        [, $f] = AnfrageValidator::pruefe($this->eingabe(['wunsch' => 'verkaufen']), 'sammlung', 5);
        $this->assertSame([], $f);
    }
}
