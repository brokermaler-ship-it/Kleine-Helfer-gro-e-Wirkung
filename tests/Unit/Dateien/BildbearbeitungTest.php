<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Dateien;

use Emc\Dateien\Bildbearbeitung;
use Emc\Tests\Support\ExifJpeg;
use PHPUnit\Framework\TestCase;

/** Neu kodieren öffentlicher Uploads: ohne EXIF/GPS, aufgerichtet, höchstens 2000 px. */
final class BildbearbeitungTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    private function datei(string $inhalt): string
    {
        $pfad = tempnam(sys_get_temp_dir(), 'bild');
        $this->temp[] = $pfad;
        file_put_contents($pfad, $inhalt);
        return $pfad;
    }

    public function test_ohne_metadaten(): void
    {
        $pfad = $this->datei(ExifJpeg::erzeuge(300, 200, 6));
        $vorher = exif_read_data($pfad);
        $this->assertArrayHasKey('GPSLatitude', $vorher, 'Fixture hat GPS');

        $this->assertTrue((new Bildbearbeitung())->ohneMetadaten($pfad));

        clearstatcache();
        $exif = @exif_read_data($pfad);
        $this->assertFalse(is_array($exif) && isset($exif['GPSLatitude']), 'GPS entfernt');
        $this->assertFalse(is_array($exif) && isset($exif['Make']), 'Kamera entfernt');
        $this->assertStringNotContainsString(ExifJpeg::KAMERA, (string) file_get_contents($pfad));
        $this->assertStringNotContainsString('Exif', (string) file_get_contents($pfad));
        $info = getimagesize($pfad);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame([200, 300], [$info[0], $info[1]], 'aufgerichtet (Ausrichtung 6)');
        // Die blaue Markierung (gespeichert oben links) liegt nach dem Drehen um 90° im Uhrzeigersinn oben rechts.
        $bild = imagecreatefromjpeg($pfad);
        $farbe = imagecolorsforindex($bild, imagecolorat($bild, 190, 10));
        $this->assertGreaterThan(150, $farbe['blue']);
        $this->assertLessThan(100, $farbe['red']);
    }

    public function test_grosses_bild_wird_verkleinert(): void
    {
        $bild = imagecreatetruecolor(5000, 4000);
        $pfad = tempnam(sys_get_temp_dir(), 'bild');
        $this->temp[] = $pfad;
        imagepng($bild, $pfad, 1);
        unset($bild);
        $this->assertTrue((new Bildbearbeitung(fn() => null))->ohneMetadaten($pfad));
        clearstatcache();
        $info = getimagesize($pfad);
        $this->assertSame([2000, 1600, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]], 'PNG wird JPEG, längste Seite 2000');
    }

    public function test_nicht_lesbares_wird_abgelehnt(): void
    {
        $pdf = $this->datei((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/dateien/anleitung.pdf'));
        $this->assertFalse((new Bildbearbeitung())->ohneMetadaten($pdf));
        // HEIC-Attrappe (ftyp-Kopf), GIF und kaputtes JPEG
        $heic = $this->datei("\0\0\0\x18ftypheic\0\0\0\0mif1heic" . str_repeat("\0", 64));
        $this->assertFalse((new Bildbearbeitung())->ohneMetadaten($heic));
        $gif = imagecreatetruecolor(10, 10);
        ob_start();
        imagegif($gif);
        $this->assertFalse((new Bildbearbeitung())->ohneMetadaten($this->datei((string) ob_get_clean())));
        $kaputt = $this->datei(substr(ExifJpeg::erzeuge(), 0, 400));
        $this->assertFalse((new Bildbearbeitung())->ohneMetadaten($kaputt));
        $this->assertFalse((new Bildbearbeitung())->ohneMetadaten('/gibt/es/nicht.jpg'));
    }

    public function test_speichergrenze(): void
    {
        $pfad = $this->datei(ExifJpeg::erzeuge());
        $inhalt = (string) file_get_contents($pfad);
        $this->assertFalse((new Bildbearbeitung(fn() => 1024))->ohneMetadaten($pfad), 'zu wenig Speicher');
        $this->assertSame($inhalt, (string) file_get_contents($pfad), 'bei false bleibt die Datei unverändert (Aufrufer löscht)');
    }
}
