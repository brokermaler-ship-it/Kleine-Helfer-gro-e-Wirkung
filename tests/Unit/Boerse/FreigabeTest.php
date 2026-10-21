<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Boerse;

use Emc\Boerse\Freigabe;
use PHPUnit\Framework\TestCase;

/** Freigabe-Workflow: Rückfall auf „vorgeschlagen“ und erlaubte Übergänge. */
final class FreigabeTest extends TestCase
{
    private const ALT = [
        'bezeichnung' => 'BR 50 Märklin', 'beschreibung' => 'gut erhalten', 'wunschpreis' => '12.50', 'kategorie_id' => 2,
        'spurweite_id' => null, 'zustand' => 'gebraucht', 'notiz' => 'Kiste 3', 'erloes_spende' => 0,
    ];

    public function test_inhalt_geaendert(): void
    {
        $this->assertFalse(Freigabe::inhaltGeaendert(self::ALT, self::ALT));
        $this->assertFalse(Freigabe::inhaltGeaendert(self::ALT, ['wunschpreis' => '12.5', 'kategorie_id' => '2'] + self::ALT), 'gleicher Betrag, Zahl als Text');
        $this->assertFalse(Freigabe::inhaltGeaendert(self::ALT, ['notiz' => 'Kiste 4', 'erloes_spende' => 1] + self::ALT), 'nur interne Felder');
        $this->assertTrue(Freigabe::inhaltGeaendert(self::ALT, ['wunschpreis' => '13.00'] + self::ALT));
        $this->assertTrue(Freigabe::inhaltGeaendert(self::ALT, ['beschreibung' => null] + self::ALT));
        $this->assertTrue(Freigabe::inhaltGeaendert(self::ALT, ['spurweite_id' => 3] + self::ALT));
    }

    /** @return list<array{0:string,1:bool,2:bool,3:string}> */
    public static function nachAenderung(): array
    {
        return [
            'Mitglied ändert Preis am freigegebenen' => ['freigegeben', false, true, 'vorgeschlagen'],
            'Mitglied ändert nur Notiz' => ['freigegeben', false, false, 'freigegeben'],
            'Börsenwart ändert' => ['freigegeben', true, true, 'freigegeben'],
            'vorgeschlagen bleibt' => ['vorgeschlagen', false, true, 'vorgeschlagen'],
            'nein bleibt' => ['nein', false, true, 'nein'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nachAenderung')]
    public function test_nach_aenderung(string $vorher, bool $boersenwart, bool $geaendert, string $erwartet): void
    {
        $this->assertSame($erwartet, Freigabe::nachAenderung($vorher, $boersenwart, $geaendert));
    }

    public function test_erlaubt(): void
    {
        // Mitglied (eigenes Angebot)
        $this->assertTrue(Freigabe::erlaubt('nein', 'vorgeschlagen', false, 'mitglied'));
        $this->assertTrue(Freigabe::erlaubt('vorgeschlagen', 'nein', false, 'mitglied'));
        $this->assertTrue(Freigabe::erlaubt('freigegeben', 'nein', false, 'mitglied'));
        $this->assertFalse(Freigabe::erlaubt('nein', 'freigegeben', false, 'mitglied'));
        $this->assertFalse(Freigabe::erlaubt('vorgeschlagen', 'freigegeben', false, 'mitglied'));
        $this->assertFalse(Freigabe::erlaubt('freigegeben', 'vorgeschlagen', false, 'mitglied'));
        $this->assertFalse(Freigabe::erlaubt('nein', 'vorgeschlagen', false, 'verein'));
        // Börsenwart
        $this->assertTrue(Freigabe::erlaubt('vorgeschlagen', 'freigegeben', true, 'mitglied'));
        $this->assertTrue(Freigabe::erlaubt('nein', 'freigegeben', true, 'mitglied'));
        $this->assertTrue(Freigabe::erlaubt('nein', 'freigegeben', true, 'verein'));
        $this->assertTrue(Freigabe::erlaubt('freigegeben', 'nein', true, 'verein'));
        $this->assertTrue(Freigabe::erlaubt('vorgeschlagen', 'nein', true, 'mitglied'));
        $this->assertFalse(Freigabe::erlaubt('nein', 'vorgeschlagen', true, 'verein'));
        $this->assertFalse(Freigabe::erlaubt('nein', 'vorgeschlagen', true, 'mitglied'), 'der Börsenwart gibt direkt frei');
    }

    public function test_unbekannte_werte(): void
    {
        $this->assertFalse(Freigabe::erlaubt('nein', 'irgendwas', true, 'verein'));
        $this->assertFalse(Freigabe::erlaubt('nein', 'nein', true, 'verein'), 'kein Übergang auf denselben Wert');
    }
}
