<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\OneDrive;

use Emc\OneDrive\Pfadbildner;
use PHPUnit\Framework\TestCase;

final class PfadbildnerTest extends TestCase
{
    public function testFahrzeugPfad(): void
    {
        $p = Pfadbildner::fuerDokument(['titel' => 'Seitenansicht', 'endung' => 'jpg'], ['inventarnummer' => 'EMC-0001', 'bezeichnung' => 'V 200 060']);
        self::assertSame('Fahrzeuge/EMC-0001 V 200 060/Seitenansicht.jpg', $p);
    }

    public function test_anlage(): void
    {
        self::assertSame('Anlagen/A3 Clubanlage/Foto.jpg', Pfadbildner::fuerDokument(['titel' => 'Foto', 'endung' => 'jpg'], null, ['nummer' => 3, 'name' => 'Clubanlage']));
        self::assertSame('Anlagen/A7 Modul_ Bahnhof/Plan.pdf', Pfadbildner::fuerDokument(['titel' => 'Plan', 'endung' => 'pdf'], null, ['nummer' => '7', 'name' => 'Modul: Bahnhof']));
        // Ein Fahrzeug hat Vorrang (Dokumente hängen in der Regel nur an einem Objekt).
        self::assertSame('Fahrzeuge/EMC-0001 V 200/Foto.jpg', Pfadbildner::fuerDokument(['titel' => 'Foto', 'endung' => 'jpg'], ['inventarnummer' => 'EMC-0001', 'bezeichnung' => 'V 200'], ['nummer' => 3, 'name' => 'Clubanlage']));
    }

    public function test_wartungsfoto_im_unterordner_wartung(): void
    {
        self::assertSame('Fahrzeuge/EMC-0001 V 200/Wartung/Foto.jpg', Pfadbildner::fuerDokument(['titel' => 'Foto', 'endung' => 'jpg'], ['inventarnummer' => 'EMC-0001', 'bezeichnung' => 'V 200'], null, 'Wartung'));
        self::assertSame('Anlagen/A3 Clubanlage/Wartung/Foto.jpg', Pfadbildner::fuerDokument(['titel' => 'Foto', 'endung' => 'jpg'], null, ['nummer' => 3, 'name' => 'Clubanlage'], 'Wartung'));
        self::assertSame('Sonstiges/Foto.jpg', Pfadbildner::fuerDokument(['titel' => 'Foto', 'endung' => 'jpg'], null, null, 'Wartung'));
    }

    public function test_reparatur_unterordner(): void
    {
        self::assertSame('Fahrzeuge/EMC-0012 V 200/Reparatur/Foto.jpg', Pfadbildner::fuerDokument(['titel' => 'Foto', 'endung' => 'jpg'], ['inventarnummer' => 'EMC-0012', 'bezeichnung' => 'V 200'], null, 'Reparatur'));
        self::assertSame('Anlagen/A3 Clubanlage/Reparatur/Foto.jpg', Pfadbildner::fuerDokument(['titel' => 'Foto', 'endung' => 'jpg'], null, ['nummer' => 3, 'name' => 'Clubanlage'], 'Reparatur'));
        // Der Unterordner wird wie jeder Ordnername bereinigt.
        self::assertSame('Fahrzeuge/EMC-0012 V 200/a_b/Foto.jpg', Pfadbildner::fuerDokument(['titel' => 'Foto', 'endung' => 'jpg'], ['inventarnummer' => 'EMC-0012', 'bezeichnung' => 'V 200'], null, 'a/b'));
    }

    public function test_magazin_ordner(): void
    {
        self::assertSame('Magazin/M37 Märklin C-Gleis/Foto.jpg', Pfadbildner::fuerMagazin(['titel' => 'Foto', 'endung' => 'jpg'], ['id' => 37, 'bezeichnung' => 'Märklin C-Gleis']));
        self::assertSame('Magazin/M5 Buch_ Teil 2/Seite.jpg', Pfadbildner::fuerMagazin(['titel' => 'Seite.jpg', 'endung' => 'jpg'], ['id' => '5', 'bezeichnung' => 'Buch: Teil 2']));
    }

    public function test_angebot_ordner(): void
    {
        self::assertSame('Boerse/B2026-118 BR 50 Märklin/Foto.jpg', Pfadbildner::fuerAngebot(['titel' => 'Foto', 'endung' => 'jpg'], ['nummer' => 'B2026-118', 'bezeichnung' => 'BR 50 Märklin']));
        self::assertSame('Boerse/B2026-1 Kiste_ Gleise/Seite.jpg', Pfadbildner::fuerAngebot(['titel' => 'Seite.jpg', 'endung' => 'jpg'], ['nummer' => 'B2026-1', 'bezeichnung' => 'Kiste: Gleise']));
    }

    public function testOhneFahrzeugSonstiges(): void
    {
        self::assertSame('Sonstiges/Anleitung.pdf', Pfadbildner::fuerDokument(['titel' => 'Anleitung', 'endung' => 'pdf'], null));
    }

    public function testSonderzeichenWerdenErsetztUmlauteBleiben(): void
    {
        self::assertSame(
            'Sonstiges/Bär_ Größe_ _x_ a_b_c_d_e_f_.pdf',
            Pfadbildner::fuerDokument(['titel' => 'Bär: Größe? "x" a\\b/c*d<e>f|', 'endung' => 'pdf'], null)
        );
    }

    public function testBereinige(): void
    {
        self::assertSame('a_b_c_d_e_f_g_h_i', Pfadbildner::bereinige('a\\b/c:d*e?f"g<h>i'));
        self::assertSame('Würzburg ß', Pfadbildner::bereinige('Würzburg ß'));
        self::assertSame('x_y', Pfadbildner::bereinige("x\x00y"));
        self::assertSame('Name', Pfadbildner::bereinige('  Name .  '));
        self::assertSame('_', Pfadbildner::bereinige(' . '));
        self::assertSame('a_b', Pfadbildner::bereinige('a|b'));
    }

    public function testLaengeBegrenztJeSegment(): void
    {
        $lang = str_repeat('ä', 300);
        self::assertSame(120, mb_strlen(Pfadbildner::bereinige($lang)));
        $p = Pfadbildner::fuerDokument(['titel' => $lang, 'endung' => 'jpg'], ['inventarnummer' => 'EMC-0001', 'bezeichnung' => $lang]);
        [$a, $b, $c] = explode('/', $p);
        self::assertSame('Fahrzeuge', $a);
        self::assertLessThanOrEqual(120, mb_strlen($b));
        self::assertLessThanOrEqual(120, mb_strlen($c));
        self::assertStringEndsWith('.jpg', $c);
    }

    public function testEndungNichtDoppelt(): void
    {
        self::assertSame('Sonstiges/Plan.PDF', Pfadbildner::fuerDokument(['titel' => 'Plan.PDF', 'endung' => 'pdf'], null));
    }

    public function testLeererTitel(): void
    {
        self::assertSame('Sonstiges/_.pdf', Pfadbildner::fuerDokument(['titel' => '  ', 'endung' => 'pdf'], null));
    }
}
