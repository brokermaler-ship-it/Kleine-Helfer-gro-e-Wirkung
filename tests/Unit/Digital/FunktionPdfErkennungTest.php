<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Digital;

use Emc\Digital\CsvErgebnis;
use Emc\Digital\FunktionPdfErkennung;
use PHPUnit\Framework\TestCase;

final class FunktionPdfErkennungTest extends TestCase
{
    private function fixture(string $name): CsvErgebnis
    {
        return FunktionPdfErkennung::erkenne((string) file_get_contents(__DIR__ . '/../../fixtures/pdf-text/' . $name));
    }

    /** @return array<int,string> Taste → Beschreibung */
    private function tasten(CsvErgebnis $e): array
    {
        $r = [];
        foreach ($e->zeilen as $z) {
            $r[$z['taste']] = $z['beschreibung'];
        }
        return $r;
    }

    /** @return array<int,string> Taste → Schaltart */
    private function schaltarten(CsvErgebnis $e): array
    {
        return array_column($e->zeilen, 'schaltart', 'taste');
    }

    public function test_beispiele_der_spezifikation(): void
    {
        $e = FunktionPdfErkennung::erkenne("F0 Licht\nF1 Sound an\nF2 – Pfiff\nF 3: Glocke");
        $this->assertSame([0 => 'Licht', 1 => 'Sound an', 2 => 'Pfiff', 3 => 'Glocke'], $this->tasten($e));
        $this->assertSame([0 => 'dauer', 1 => 'dauer', 2 => 'dauer', 3 => 'dauer'], $this->schaltarten($e));
        $this->assertSame([], $e->fehler);
        $this->assertSame([], $e->hinweise);
    }

    public function test_esu_zwei_spalten(): void
    {
        $e = $this->fixture('esu_loksound.txt');
        $this->assertSame([
            0 => 'Licht vorne/hinten',
            1 => 'Fahrgeräusch',
            2 => 'Signalhorn hoch',
            3 => 'Signalhorn tief',
            8 => 'Rangiergang',
            9 => 'Kurvenquietschen',
            10 => 'Bremsenquietschen aus',
            11 => 'Kupplungsgeräusch',
        ], $this->tasten($e));
        $this->assertSame([], $e->hinweise);
    }

    public function test_zimo_trenner_moment_bereich_trennung(): void
    {
        $e = $this->fixture('zimo.txt');
        $this->assertSame([0 => 'Stirnlampen', 1 => 'Fahrgeräusch an/aus', 2 => 'Pfiff kurz', 3 => 'Glocke', 12 => 'Rangierbeleuchtung'], $this->tasten($e));
        $this->assertSame('moment', $this->schaltarten($e)[2]);
        $this->assertSame('dauer', $this->schaltarten($e)[3]);
        $this->assertSame(['Bereich F5–F8 („Ansagen Bahnsteig“) nicht übernommen – bitte von Hand eintragen.'], $e->hinweise);
    }

    public function test_maerklin_kleinbuchstaben_und_zerlegte_umlaute(): void
    {
        $e = $this->fixture('maerklin_trix.txt');
        $this->assertSame([0 => 'Spitzensignal', 1 => 'Rauchsatz', 2 => 'Geräusch: Lokpfeife', 3 => "Ger\u{00E4}usch: Betriebsger\u{00E4}usch"], $this->tasten($e));
        $this->assertArrayNotHasKey(5, $this->tasten($e));
        $this->assertSame(['Bereich F13–F15 („Geräusch: Bahnhofsansagen“) nicht übernommen – bitte von Hand eintragen.'], $e->hinweise);
    }

    public function test_piko_tab_und_doppelte_taste(): void
    {
        $e = $this->fixture('piko.txt');
        $this->assertSame([0 => 'Licht', 1 => 'Sound an/aus', 2 => 'Pfiff', 3 => 'Glocke', 4 => 'Kurvenquietschen'], $this->tasten($e));
        $this->assertSame(['F2 kommt mehrfach vor; übernommen wurde „Pfiff“.'], $e->hinweise);
    }

    public function test_roco_pipe_m_marke_und_grenze(): void
    {
        $e = $this->fixture('roco.txt');
        $this->assertSame([0 => 'Licht ein/aus', 1 => 'Sound ein/aus', 2 => 'Horn', 3 => 'Pfeife'], $this->tasten($e));
        $this->assertSame('moment', $this->schaltarten($e)[2]);
        $this->assertSame(['F69 liegt außerhalb von F0–F68 und wurde übersprungen.'], $e->hinweise);
    }

    public function test_f68_und_fuehrende_null(): void
    {
        $e = FunktionPdfErkennung::erkenne("F68 Ansage Ende\nF05 Kuppeln");
        $this->assertSame([5 => 'Kuppeln', 68 => 'Ansage Ende'], $this->tasten($e));
        $this->assertSame([5, 68], array_column($e->zeilen, 'taste'));
    }

    public function test_fliesstext_ohne_falsche_treffer(): void
    {
        $e = FunktionPdfErkennung::erkenne("Die Lok fährt (wie F3) langsam.\nSeite 4\nKopf 2 Zylinder");
        $this->assertSame([], $e->zeilen);
        $this->assertSame([], $e->hinweise);
    }

    public function test_gleiche_taste_gleicher_text_still(): void
    {
        $e = FunktionPdfErkennung::erkenne("F1 Licht\nF1 licht");
        $this->assertSame([1 => 'Licht'], $this->tasten($e));
        $this->assertSame([], $e->hinweise);
    }

    public function test_ueberlange_beschreibung(): void
    {
        $e = FunktionPdfErkennung::erkenne('F1 ' . str_repeat('x', 120));
        $this->assertSame(100, mb_strlen($e->zeilen[0]['beschreibung']));
        $this->assertSame(['Beschreibung von F1 auf 100 Zeichen gekürzt.'], $e->hinweise);
    }

    public function test_windows_1252_text(): void
    {
        $this->assertSame([1 => 'Geräusch'], $this->tasten(FunktionPdfErkennung::erkenne("F1 Ger\xe4usch")));
    }
}
