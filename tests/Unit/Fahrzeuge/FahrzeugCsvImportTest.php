<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Fahrzeuge;

use Emc\Fahrzeuge\FahrzeugCsvImport;
use PHPUnit\Framework\TestCase;

final class FahrzeugCsvImportTest extends TestCase
{
    private const KOPF = 'Alte Nummer;Bezeichnung;Gattung;Baureihe;Bahn;Epoche;Spur;Strom;Hersteller;Artikel;Besitzer;Quelle;Notiz';
    private const SPUR = ['H0' => 1, 'TT' => 4, 'N' => 5];

    private static function csv(string ...$zeilen): string
    {
        return "\xEF\xBB\xBF" . self::KOPF . "\r\n" . implode("\r\n", $zeilen) . "\r\n";
    }

    public function test_gueltige_zeile_mit_bom_und_crlf(): void
    {
        $e = FahrzeugCsvImport::parse(self::csv('A-1;V 100 rot;Lok;V 100;DR;IV;TT;3L;Beispielwerk;4711;Erika Beispiel;Liste 2020;Lack ab'), self::SPUR);
        $this->assertSame([], $e->fehler);
        $this->assertCount(1, $e->zeilen);
        $z = $e->zeilen[0];
        $this->assertSame(2, $z['nr']);
        $this->assertSame('anlegen', $z['status']);
        $this->assertFalse($z['doppelt']);
        $this->assertSame([
            'bezeichnung' => 'V 100 rot', 'baureihe' => 'V 100', 'bahnverwaltung' => 'DR', 'epoche' => 'IV', 'gattung' => 'lok',
            'hersteller' => 'Beispielwerk', 'artikelnummer' => '4711', 'spurweite_id' => 4, 'stromsystem' => '3L',
        ], $z['daten']);
        $this->assertSame('A-1', $z['alte_nummer']);
        $this->assertSame('Erika Beispiel', $z['besitzer']);
        $this->assertSame('Liste 2020', $z['quelle']);
        $this->assertSame('Lack ab', $z['notiz']);
        $this->assertTrue($e->istImportierbar());
        $this->assertSame(1, $e->anzahl('anlegen'));
    }

    public function test_kopfzeile_in_anderer_reihenfolge_und_schreibweise_optionale_spalten_fehlen(): void
    {
        $e = FahrzeugCsvImport::parse("SPUR;  bezeichnung ;GATTUNG;Alte nummer\nh0;Kesselwagen;wagen;7\n", self::SPUR);
        $this->assertSame([], $e->fehler);
        $z = $e->zeilen[0];
        $this->assertSame('anlegen', $z['status']);
        $this->assertSame('Kesselwagen', $z['daten']['bezeichnung']);
        $this->assertSame('wagen', $z['daten']['gattung']);
        $this->assertSame(1, $z['daten']['spurweite_id']);
        $this->assertSame('2L', $z['daten']['stromsystem'], 'leerer Strom = 2L');
        $this->assertNull($z['daten']['baureihe']);
        $this->assertNull($z['daten']['epoche']);
        $this->assertSame('7', $z['alte_nummer']);
        $this->assertSame('', $z['notiz']);
    }

    public function test_pflichtspalte_fehlt_im_kopf(): void
    {
        $e = FahrzeugCsvImport::parse("Bezeichnung;Gattung\nLok;Lok\n", self::SPUR);
        $this->assertSame(['In der Kopfzeile fehlt die Spalte „Spur“.'], $e->fehler);
        $this->assertSame([], $e->zeilen);
        $this->assertFalse($e->istImportierbar());
    }

    public function test_doppelte_spalte_im_kopf(): void
    {
        $e = FahrzeugCsvImport::parse("Bezeichnung;Gattung;Spur;spur\nLok;Lok;H0;H0\n", self::SPUR);
        $this->assertSame(['Die Spalte „Spur“ steht mehrfach in der Kopfzeile.'], $e->fehler);
    }

    public function test_windows_1252_wird_erkannt(): void
    {
        $inhalt = mb_convert_encoding("Bezeichnung;Gattung;Spur;Notiz\nGüterwagen „Gräf“;Wagen;TT;Rädersatz fehlt\n", 'Windows-1252', 'UTF-8');
        $e = FahrzeugCsvImport::parse($inhalt, self::SPUR);
        $this->assertSame([], $e->fehler);
        $this->assertSame('Güterwagen „Gräf“', $e->zeilen[0]['daten']['bezeichnung']);
        $this->assertSame('Rädersatz fehlt', $e->zeilen[0]['notiz']);
    }

    public function test_tab_und_komma_als_trenner(): void
    {
        $tab = FahrzeugCsvImport::parse("Bezeichnung\tGattung\tSpur\nLok A\tLok\tN\n", self::SPUR);
        $this->assertSame('Lok A', $tab->zeilen[0]['daten']['bezeichnung']);
        $komma = FahrzeugCsvImport::parse("Bezeichnung,Gattung,Spur\n\"Wagen, grün\",Wagen,N\n", self::SPUR);
        $this->assertSame([], $komma->fehler);
        $this->assertSame('Wagen, grün', $komma->zeilen[0]['daten']['bezeichnung']);
    }

    public function test_gattung_varianten_und_zugverband(): void
    {
        $e = FahrzeugCsvImport::parse(self::csv(
            ';L;LOK;;;;H0;;;;;;',
            ';T;triebwagen;;;;H0;;;;;;',
            ';W;Wagen;;;;H0;;;;;;',
            ';S;sonderFAHRZEUG;;;;H0;;;;;;',
            ';Z1;Zugverband;;;;H0;;;;;;',
            ';Z2;zug;;;;Unbekannt;;;;;;',
        ), self::SPUR);
        $this->assertSame(['lok', 'triebwagen', 'wagen', 'sonderfahrzeug'], array_map(fn($z) => $z['daten']['gattung'], array_slice($e->zeilen, 0, 4)));
        $this->assertSame('zugverband', $e->zeilen[4]['status']);
        $this->assertSame('zugverband', $e->zeilen[5]['status'], 'Zugverbände werden nicht weiter geprüft');
        $this->assertSame([], $e->zeilen[5]['fehler']);
        $this->assertSame(4, $e->anzahl('anlegen'));
        $this->assertSame(2, $e->anzahl('zugverband'));
        $this->assertTrue($e->istImportierbar());
        $this->assertCount(4, $e->anzulegende());
    }

    public function test_fehlerzeilen(): void
    {
        $e = FahrzeugCsvImport::parse(self::csv(
            ';;Lok;;;;H0;;;;;;',
            ';Lok;Dampfwalze;;;;H0;;;;;;',
            ';Lok;;;;;H0;;;;;;',
            ';Lok;Lok;;;;Spur 1;;;;;;',
            ';Lok;Lok;;;;;;;;;;',
            ';Lok;Lok;;;IIIb;H0;4L;;;;;',
            ';Lok;Lok;;;iv;h0;3l;;;;;',
        ), self::SPUR);
        $this->assertSame(['Bezeichnung fehlt.'], $e->zeilen[0]['fehler']);
        $this->assertSame(['Gattung „Dampfwalze“ ist unbekannt (erlaubt: Lok, Triebwagen, Wagen, Sonderfahrzeug, Zugverband).'], $e->zeilen[1]['fehler']);
        $this->assertSame(['Gattung fehlt.'], $e->zeilen[2]['fehler']);
        $this->assertSame(['Spur „Spur 1“ ist unbekannt (vorhanden: H0, TT, N).'], $e->zeilen[3]['fehler']);
        $this->assertSame(['Spur fehlt.'], $e->zeilen[4]['fehler']);
        $this->assertSame(['Epoche „IIIb“ ist ungültig (erlaubt I bis VI).', 'Strom „4L“ ist unbekannt (erlaubt 2L, 3L oder leer).'], $e->zeilen[5]['fehler']);
        $this->assertSame('fehler', $e->zeilen[5]['status']);
        $this->assertSame('anlegen', $e->zeilen[6]['status'], 'Groß-/Kleinschreibung egal');
        $this->assertSame('IV', $e->zeilen[6]['daten']['epoche']);
        $this->assertSame('3L', $e->zeilen[6]['daten']['stromsystem']);
        $this->assertSame(6, $e->anzahl('fehler'));
        $this->assertFalse($e->istImportierbar(), 'Fehlerzeilen blockieren den ganzen Import');
    }

    public function test_feldlaengen(): void
    {
        $e = FahrzeugCsvImport::parse(self::csv(
            ';' . str_repeat('x', 151) . ';Lok;' . str_repeat('b', 101) . ';;;H0;;;;;;',
            ';' . str_repeat('ä', 150) . ';Lok;;;;H0;;' . str_repeat('h', 100) . ';;;;',
            str_repeat('n', 51) . ';Lok;Lok;;;;H0;;;;' . str_repeat('p', 151) . ';' . str_repeat('q', 251) . ';',
        ), self::SPUR);
        $this->assertSame(['Bezeichnung: höchstens 150 Zeichen.', 'Baureihe: höchstens 100 Zeichen.'], $e->zeilen[0]['fehler']);
        $this->assertSame('anlegen', $e->zeilen[1]['status'], 'genau an der Grenze, Umlaute zählen als ein Zeichen');
        $this->assertSame(['Alte Nummer: höchstens 50 Zeichen.', 'Besitzer: höchstens 150 Zeichen.', 'Quelle: höchstens 250 Zeichen.'], $e->zeilen[2]['fehler']);
    }

    /** TEXT fasst 65535 Byte: die zusammengesetzte Notiz (mit Herkunftszeile) darf nicht größer werden. */
    public function test_notiz_grenze_in_byte(): void
    {
        $e = FahrzeugCsvImport::parse(self::csv(
            'A-1;Lok;Lok;;;;H0;;;;;;' . str_repeat('ä', 40000),
            'A-2;Lok 2;Lok;;;;H0;;;;;;' . str_repeat('a', 40000),
        ), self::SPUR, [], 'bestand.csv');
        $this->assertSame(['Notiz ist zusammen mit der Herkunftsangabe zu lang (höchstens 65535 Byte; Umlaute zählen doppelt).'], $e->zeilen[0]['fehler']);
        $this->assertSame('anlegen', $e->zeilen[1]['status']);
        $this->assertFalse($e->istImportierbar());
        $grenzfall = ['alte_nummer' => 'A-2', 'besitzer' => '', 'quelle' => '', 'notiz' => str_repeat('a', 40000)];
        $this->assertLessThanOrEqual(65535, strlen(FahrzeugCsvImport::notiz($grenzfall, 'bestand.csv', new \DateTimeImmutable())));
    }

    public function test_reine_trennerzeilen_vor_dem_kopf_werden_uebersprungen(): void
    {
        $e = FahrzeugCsvImport::parse("\xEF\xBB\xBF;;;\r\n ; ;\r\n\"\";\"\"\r\nBezeichnung;Gattung;Spur\r\nLok;Lok;H0\r\n", self::SPUR);
        $this->assertSame([], $e->fehler);
        $this->assertSame('Lok', $e->zeilen[0]['daten']['bezeichnung']);
        $this->assertSame(5, $e->zeilen[0]['nr']);
    }

    public function test_doppelte_bezeichnungen_in_datei_und_vorhanden(): void
    {
        $e = FahrzeugCsvImport::parse(self::csv(
            ';BR 228 203-6;Lok;;;;H0;;;;;;',
            ';Kesselwagen;Wagen;;;;H0;;;;;;',
            ';br 228 203-6 ;Lok;;;;H0;;;;;;',
            ';V 60;Lok;;;;H0;;;;;;',
            ';Kesselwagen;Zugverband;;;;H0;;;;;;',
        ), self::SPUR, ['V 60', 'Anderes']);
        $this->assertSame([true, false, true, true, false], array_map(fn($z) => $z['doppelt'], $e->zeilen));
        $this->assertSame('anlegen', $e->zeilen[0]['status'], 'doppelt wird trotzdem angelegt');
        $this->assertSame(3, $e->anzahlDoppelt());
        $this->assertTrue($e->istImportierbar());
    }

    public function test_leere_zeilen_werden_uebersprungen_und_zeilennummern_stimmen(): void
    {
        $e = FahrzeugCsvImport::parse("Bezeichnung;Gattung;Spur\n\n;;\nLok;Lok;H0\n", self::SPUR);
        $this->assertCount(1, $e->zeilen);
        $this->assertSame(4, $e->zeilen[0]['nr']);
    }

    public function test_leere_datei_und_nur_kopf(): void
    {
        $this->assertSame(['Die Datei enthält keine Fahrzeuge.'], FahrzeugCsvImport::parse('', self::SPUR)->fehler);
        $nurKopf = FahrzeugCsvImport::parse(self::KOPF . "\r\n", self::SPUR);
        $this->assertSame(['Die Datei enthält keine Fahrzeuge.'], $nurKopf->fehler);
        $this->assertFalse($nurKopf->istImportierbar());
    }

    public function test_nur_zugverbaende_ist_nicht_importierbar(): void
    {
        $e = FahrzeugCsvImport::parse("Bezeichnung;Gattung;Spur\nIC;Zugverband;H0\n", self::SPUR);
        $this->assertSame([], $e->fehler);
        $this->assertFalse($e->istImportierbar());
    }

    public function test_grenzen_zeilen_und_bytes(): void
    {
        $zeilen = str_repeat("Lok;Lok;H0\n", FahrzeugCsvImport::MAX_ZEILEN);
        $this->assertSame([], FahrzeugCsvImport::parse("Bezeichnung;Gattung;Spur\n" . $zeilen, self::SPUR)->fehler);
        $zuViel = FahrzeugCsvImport::parse("Bezeichnung;Gattung;Spur\n" . $zeilen . "Lok;Lok;H0\n", self::SPUR);
        $this->assertSame(['Die Datei hat mehr als 2000 Fahrzeugzeilen. Bitte aufteilen.'], $zuViel->fehler);
        $this->assertSame([], $zuViel->zeilen);
        $gross = FahrzeugCsvImport::parse("Bezeichnung;Gattung;Spur\nLok;Lok;H0;" . str_repeat('x', FahrzeugCsvImport::MAX_BYTES) . "\n", self::SPUR);
        $this->assertSame(['Die Datei ist zu groß (höchstens 2 MB).'], $gross->fehler);
    }

    public function test_vorlage_ist_fehlerfrei(): void
    {
        $e = FahrzeugCsvImport::parse(FahrzeugCsvImport::VORLAGE, self::SPUR);
        $this->assertSame([], $e->fehler);
        $this->assertSame(2, $e->anzahl('anlegen'));
        $this->assertTrue(str_starts_with(FahrzeugCsvImport::VORLAGE, "\u{FEFF}" . self::KOPF . "\r\n"));
    }

    public function test_notiz_zusammensetzen(): void
    {
        $datum = new \DateTimeImmutable('2026-10-21 10:00');
        $z = ['alte_nummer' => 'EMC-0003', 'besitzer' => 'Erika Beispiel', 'quelle' => 'Liste.', 'notiz' => 'Lack ab'];
        $this->assertSame(
            "Lack ab\n\nImport vom 21.10.2026 aus „bestand.csv“, alte Nummer EMC-0003. Besitzer laut Liste: Erika Beispiel. Quelle: Liste.",
            FahrzeugCsvImport::notiz($z, 'bestand.csv', $datum)
        );
        $leer = ['alte_nummer' => '', 'besitzer' => '', 'quelle' => '', 'notiz' => ''];
        $this->assertSame('Import vom 21.10.2026 aus „bestand.csv“.', FahrzeugCsvImport::notiz($leer, 'bestand.csv', $datum));
    }

    public function test_dateiname_bereinigen(): void
    {
        $this->assertSame('Bestand 2026.csv', FahrzeugCsvImport::dateiname('C:\\fakepath\\Bestand 2026.csv'));
        $this->assertSame('a_b_.csv', FahrzeugCsvImport::dateiname("../a\"<b>\x01.csv"));
        $this->assertSame('import.csv', FahrzeugCsvImport::dateiname(''));
        $this->assertSame(100, mb_strlen(FahrzeugCsvImport::dateiname(str_repeat('ü', 300) . '.csv')));
    }
}
