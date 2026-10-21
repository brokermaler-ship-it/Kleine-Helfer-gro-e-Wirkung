<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Magazin;

use Emc\Magazin\BuchungValidator;
use PHPUnit\Framework\TestCase;

final class BuchungValidatorTest extends TestCase
{
    private \DateTimeImmutable $heute;

    protected function setUp(): void
    {
        $this->heute = new \DateTimeImmutable('2026-10-21');
    }

    /** @return array{0: array<string,mixed>, 1: array<string,string>} */
    private function pruefe(array $eingabe, string $art): array
    {
        return BuchungValidator::pruefe($eingabe, $art, $this->heute);
    }

    public function test_eingang_gueltig(): void
    {
        [$d, $f] = $this->pruefe(['menge' => ' 5 ', 'herkunft' => 'spende', 'freitext' => ' Spende von Familie Beispiel ', 'betrag' => '12,5', 'datum' => ''], 'eingang');
        $this->assertSame([], $f);
        $this->assertSame('2026-10-21', $d['datum']);
        $this->assertSame(5, $d['menge']);
        $this->assertSame('spende', $d['herkunft']);
        $this->assertNull($d['ausgang_art']);
        $this->assertSame('Spende von Familie Beispiel', $d['freitext']);
        $this->assertSame('12.50', $d['betrag']);
        $this->assertNull($d['lagerort_neu']);
    }

    public function test_eingang_ohne_herkunft(): void
    {
        [, $f] = $this->pruefe(['menge' => '1'], 'eingang');
        $this->assertSame('Bitte die Herkunft wählen.', $f['herkunft']);
        [, $f] = $this->pruefe(['menge' => '1', 'herkunft' => 'geklaut'], 'eingang');
        $this->assertSame('Bitte die Herkunft wählen.', $f['herkunft']);
    }

    public function test_ausgang_art(): void
    {
        [, $f] = $this->pruefe(['menge' => '1'], 'ausgang');
        $this->assertSame('Bitte die Art des Ausgangs wählen.', $f['ausgang_art']);
        // Verkäufe über die Börse bucht nur die Kasse.
        [, $f] = $this->pruefe(['menge' => '1', 'ausgang_art' => 'verkauft_boerse'], 'ausgang');
        $this->assertSame('Bitte die Art des Ausgangs wählen.', $f['ausgang_art']);
        [$d, $f] = $this->pruefe(['menge' => '2', 'ausgang_art' => 'gegen_spende', 'freitext' => 'an den Nachbarverein', 'betrag' => '20'], 'ausgang');
        $this->assertSame([], $f);
        $this->assertSame('gegen_spende', $d['ausgang_art']);
        $this->assertNull($d['herkunft']);
        $this->assertSame(2, $d['menge']);
        $this->assertSame('20.00', $d['betrag']);
    }

    public function test_menge(): void
    {
        foreach (['0', '-1', '1.5', '', 'zwei', '100000', '1,0'] as $menge) {
            [, $f] = $this->pruefe(['menge' => $menge, 'ausgang_art' => 'verschrottet'], 'ausgang');
            $this->assertSame('Bitte eine Menge ab 1 angeben.', $f['menge'] ?? null, $menge);
        }
        [$d, $f] = $this->pruefe(['menge' => '99999', 'herkunft' => 'fund'], 'eingang');
        $this->assertSame([], $f);
        $this->assertSame(99999, $d['menge']);
        [, $f] = $this->pruefe(['menge' => ['1'], 'herkunft' => 'fund'], 'eingang');
        $this->assertArrayHasKey('menge', $f);
    }

    public function test_betrag(): void
    {
        [, $f] = $this->pruefe(['menge' => '1', 'herkunft' => 'kauf', 'betrag' => '1.234,50'], 'eingang');
        $this->assertSame('Bitte den Betrag angeben, z. B. 12,50.', $f['betrag']);
        [, $f] = $this->pruefe(['menge' => '1', 'herkunft' => 'kauf', 'betrag' => '-3'], 'eingang');
        $this->assertSame('Bitte den Betrag angeben, z. B. 12,50.', $f['betrag']);
        [$d, $f] = $this->pruefe(['menge' => '1', 'herkunft' => 'kauf', 'betrag' => '  '], 'eingang');
        $this->assertSame([], $f);
        $this->assertNull($d['betrag']);
    }

    public function test_umlagerung(): void
    {
        [, $f] = $this->pruefe(['lagerort_neu' => '  '], 'umlagerung');
        $this->assertSame('Bitte den neuen Lagerort angeben.', $f['lagerort_neu']);
        [, $f] = $this->pruefe(['lagerort_neu' => str_repeat('x', 101)], 'umlagerung');
        $this->assertSame('Bitte höchstens 100 Zeichen eingeben.', $f['lagerort_neu']);
        // Menge, Herkunft und Betrag spielen bei der Umlagerung keine Rolle.
        [$d, $f] = $this->pruefe(['lagerort_neu' => ' Regal 3 ', 'menge' => 'egal', 'herkunft' => 'spende', 'betrag' => 'x'], 'umlagerung');
        $this->assertSame([], $f);
        $this->assertSame(0, $d['menge']);
        $this->assertSame('Regal 3', $d['lagerort_neu']);
        $this->assertNull($d['herkunft']);
        $this->assertNull($d['betrag']);
    }

    public function test_datum(): void
    {
        [, $f] = $this->pruefe(['menge' => '1', 'herkunft' => 'fund', 'datum' => '2026-10-22'], 'eingang');
        $this->assertSame('Bitte ein gültiges Datum angeben.', $f['datum']);
        [, $f] = $this->pruefe(['menge' => '1', 'herkunft' => 'fund', 'datum' => '2026-02-30'], 'eingang');
        $this->assertSame('Bitte ein gültiges Datum angeben.', $f['datum']);
        [$d, $f] = $this->pruefe(['menge' => '1', 'herkunft' => 'fund', 'datum' => '3.5.2025'], 'eingang');
        $this->assertSame([], $f);
        $this->assertSame('2025-05-03', $d['datum']);
        [$d] = $this->pruefe(['menge' => '1', 'herkunft' => 'fund', 'datum' => '2026-10-21'], 'eingang');
        $this->assertSame('2026-10-21', $d['datum']);
    }

    public function test_freitext_und_art(): void
    {
        [, $f] = $this->pruefe(['menge' => '1', 'herkunft' => 'fund', 'freitext' => str_repeat('ä', 201)], 'eingang');
        $this->assertSame('Bitte höchstens 200 Zeichen eingeben.', $f['freitext']);
        [, $f] = $this->pruefe([], 'loeschen');
        $this->assertSame('Bitte eine Buchungsart wählen.', $f['art']);
    }

    public function test_ohne_heute_gilt_das_heutige_datum(): void
    {
        [$d] = BuchungValidator::pruefe(['menge' => '1', 'herkunft' => 'fund'], 'eingang');
        $this->assertSame(date('Y-m-d'), $d['datum']);
        [, $f] = BuchungValidator::pruefe(['menge' => '1', 'herkunft' => 'fund', 'datum' => date('Y-m-d', strtotime('+1 day'))], 'eingang');
        $this->assertSame('Bitte ein gültiges Datum angeben.', $f['datum']);
    }
}
