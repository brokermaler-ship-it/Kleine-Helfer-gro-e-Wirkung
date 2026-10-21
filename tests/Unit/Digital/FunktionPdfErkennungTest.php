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
    // --- Laufzeit und Speicher (Review C1, C2) ---

    public function test_lange_kette_von_trennungen_ist_linear(): void
    {
        $start = microtime(true);
        $e = FunktionPdfErkennung::erkenne(str_repeat("ab-\n", 500000)); // 2 MB
        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertSame([], $e->zeilen);
    }

    public function test_zwei_mb_in_einer_zeile_braucht_wenig_speicher(): void
    {
        $text = str_repeat('F1 a  F99 b  ', 170000); // gut 2 MB, eine Zeile
        $vorher = memory_get_usage();
        memory_reset_peak_usage();
        $start = microtime(true);
        $e = FunktionPdfErkennung::erkenne($text);
        $this->assertLessThan(64 * 1048576, memory_get_peak_usage() - $vorher);
        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertSame([1 => 'a'], $this->tasten($e));
        $this->assertLessThanOrEqual(21, count($e->hinweise));
    }

    public function test_hinweise_je_taste_und_art_nur_einmal(): void
    {
        $e = FunktionPdfErkennung::erkenne("F99 a\nF99 b\nF5-F8 x\nF5-F8 y\nF1 Licht\nF1 Horn\nF1 Pfiff");
        $this->assertSame([
            'F99 liegt außerhalb von F0–F68 und wurde übersprungen.',
            'Bereich F5–F8 („x“) nicht übernommen – bitte von Hand eintragen.',
            'F1 kommt mehrfach vor; übernommen wurde „Licht“.',
        ], $e->hinweise);
    }

    public function test_hinweise_hoechstens_zwanzig(): void
    {
        $zeilen = [];
        for ($t = 100; $t < 400; $t++) {
            $zeilen[] = "F$t Ansage";
        }
        $e = FunktionPdfErkennung::erkenne(implode("\n", $zeilen));
        $this->assertCount(21, $e->hinweise);
        $this->assertSame('F119 liegt außerhalb von F0–F68 und wurde übersprungen.', $e->hinweise[19]);
        $this->assertSame('… und 280 weitere Hinweise.', $e->hinweise[20]);
    }

    public function test_bereichshinweis_zitiert_hoechstens_100_zeichen(): void
    {
        $e = FunktionPdfErkennung::erkenne('F5–F8 ' . str_repeat('y', 300));
        $this->assertSame('Bereich F5–F8 („' . str_repeat('y', 100) . '“) nicht übernommen – bitte von Hand eintragen.', $e->hinweise[0]);
    }

    public function test_hoechstens_70_tasten_je_zeile(): void
    {
        $spalten = [];
        for ($t = 0; $t < 100; $t++) {
            $spalten[] = "F$t Taste$t";
        }
        $e = FunktionPdfErkennung::erkenne(implode("\t", $spalten));
        $this->assertCount(69, $e->zeilen);
        $this->assertSame(['F69 liegt außerhalb von F0–F68 und wurde übersprungen.'], $e->hinweise);
    }

    // --- Fließtext (Review I1) ---

    public function test_anleitung_mit_einleitung_vor_der_tabelle(): void
    {
        $e = FunktionPdfErkennung::erkenne(
            "Mit F1 schalten Sie das Fahrgeräusch ein, mit F2 das Horn.\n"
            . "Drücken Sie die F0-Taste für Licht.\n"
            . "Taste  Funktion\n"
            . "F0  Licht\n"
            . "F1  Fahrgeräusch\n"
            . "F2  Horn\n"
        );
        $this->assertSame([0 => 'Licht', 1 => 'Fahrgeräusch', 2 => 'Horn'], $this->tasten($e));
        $this->assertSame([], $e->hinweise);
    }

    public function test_taste_mit_bindestrich_im_satz_ist_kein_anfang(): void
    {
        foreach (['F1-Taste drücken', 'Zum Einschalten F1-Taste drücken.', 'Drücken Sie F1 für Licht.', 'Die Taste F3 schaltet das Licht', 'Spannung F 12 V Gleichstrom'] as $satz) {
            $e = FunktionPdfErkennung::erkenne($satz);
            $this->assertSame([], $e->zeilen, $satz);
            $this->assertSame([], $e->hinweise, $satz);
        }
    }

    public function test_starke_anfaenge_nach_wort_aufzaehlung_und_bindestrich_mit_leerraum(): void
    {
        $e = FunktionPdfErkennung::erkenne("Taste F1 = Licht\nFkt. F2 Horn\nFunktionstaste F3: Glocke\n• F4 Kuppeln\nF5 - Pfiff\nF6 Pfiff wie F7 lang");
        $this->assertSame([1 => 'Licht', 2 => 'Horn', 3 => 'Glocke', 4 => 'Kuppeln', 5 => 'Pfiff', 6 => 'Pfiff wie F7 lang'], $this->tasten($e));
    }

    // --- Kleinigkeiten (Review M1–M7, M9) ---

    public function test_nur_ascii_ziffern_und_kein_rueckverfolgungs_limit(): void
    {
        $this->assertSame([], FunktionPdfErkennung::erkenne("F\u{0967} Licht\nF\u{FF11} Licht")->zeilen);
        $e = FunktionPdfErkennung::erkenne('F1 Licht  F2' . str_repeat(' ', 1048576) . ')');
        $this->assertSame([1 => 'Licht'], $this->tasten($e));
    }

    public function test_unsichtbare_zeichen_und_steuerzeichen(): void
    {
        $e = FunktionPdfErkennung::erkenne("\u{FEFF}F0 Licht\nF1\u{200B}Horn\nF2 Pf\u{00AD}iff\x00\nF3 Glo\u{0081}cke\x07");
        $this->assertSame([0 => 'Licht', 1 => 'Horn', 2 => 'Pfiff', 3 => 'Glocke'], $this->tasten($e));
    }

    public function test_senkrechte_striche_am_rand(): void
    {
        $this->assertSame([0 => 'Licht'], $this->tasten(FunktionPdfErkennung::erkenne('| F0 | Licht |')));
        $this->assertSame([0 => 'Licht', 1 => 'Sound'], $this->tasten(FunktionPdfErkennung::erkenne('F0 | Licht | F1 | Sound')));
    }

    public function test_gekuerzte_beschreibung_ohne_leerzeichen_am_ende(): void
    {
        $e = FunktionPdfErkennung::erkenne('F1 ' . str_repeat('a', 99) . ' b');
        $this->assertSame(str_repeat('a', 99), $e->zeilen[0]['beschreibung']);
        $this->assertSame(['Beschreibung von F1 auf 100 Zeichen gekürzt.'], $e->hinweise);
    }

    public function test_frei_mit_satzzeichen_und_abkuerzung(): void
    {
        $e = FunktionPdfErkennung::erkenne("F4 frei.\nF5 n. b.\nF6 frei (s. u.)\nF7 n.b.\nF8 Unbelegt:");
        $this->assertSame([], $e->zeilen);
        $this->assertSame([], $e->hinweise);
    }

    public function test_punktreihen_mit_leerzeichen_und_vor_der_moment_marke(): void
    {
        $e = FunktionPdfErkennung::erkenne("F1 Licht . . . . . 12\nF2 Horn ....... (M)");
        $this->assertSame([1 => 'Licht', 2 => 'Horn'], $this->tasten($e));
        $this->assertSame('moment', $this->schaltarten($e)[2]);
    }

    public function test_beschreibung_in_klammern(): void
    {
        $this->assertSame([1 => 'Licht'], $this->tasten(FunktionPdfErkennung::erkenne('F1 (Licht)')));
    }

    public function test_ergaenzungsstrich_bleibt(): void
    {
        $e = FunktionPdfErkennung::erkenne("F0 Vorder-\nund Rücklicht\nF1 Stirn-\noder Schlusslicht");
        $this->assertSame([0 => 'Vorder- und Rücklicht', 1 => 'Stirn- oder Schlusslicht'], $this->tasten($e));
    }
}
