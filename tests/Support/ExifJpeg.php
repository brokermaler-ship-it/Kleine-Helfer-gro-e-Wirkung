<?php

declare(strict_types=1);

namespace Emc\Tests\Support;

/**
 * Erzeugt zur Laufzeit ein JPEG mit EXIF-Block (Ausrichtung und GPS-Position), wie es ein Handy schreibt –
 * für Tests, dass öffentliche Uploads ohne Metadaten gespeichert werden.
 */
final class ExifJpeg
{
    /** Kamera-Kennung im EXIF-Block (IFD0 „Make“), damit Tests auch ihr Verschwinden prüfen können. */
    public const KAMERA = 'TestKamera-4711';

    /**
     * @param int $breite bzw. $hoehe der gespeicherten Pixel (vor dem Aufrichten)
     * @param int $orientierung EXIF-Ausrichtung (6 = um 90° im Uhrzeigersinn drehen)
     */
    public static function erzeuge(int $breite = 300, int $hoehe = 200, int $orientierung = 6): string
    {
        $bild = imagecreatetruecolor($breite, $hoehe);
        imagefill($bild, 0, 0, (int) imagecolorallocate($bild, 200, 30, 30));
        // Markierung oben links, damit sich die Drehung nachweisen lässt.
        imagefilledrectangle($bild, 0, 0, intdiv($breite, 4), intdiv($hoehe, 4), (int) imagecolorallocate($bild, 0, 0, 255));
        ob_start();
        imagejpeg($bild, null, 90);
        $jpeg = (string) ob_get_clean();
        $tiff = self::tiff($orientierung);
        $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\0\0" . $tiff;
        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }

    /** TIFF-Struktur (Motorola): IFD0 mit Make, Orientation und Zeiger auf das GPS-IFD (Breite 51° 29′ N, Länge 11° 58′ O). */
    private static function tiff(int $orientierung): string
    {
        $make = self::KAMERA . "\0";
        // Kopf 8 + IFD0 (2 + 3·12 + 4 = 42) → GPS-IFD ab 50 (2 + 4·12 + 4 = 54) → Daten ab 104.
        $gpsOffset = 50;
        $datenOffset = $gpsOffset + 54;
        $breiteOffset = $datenOffset;
        $laengeOffset = $breiteOffset + 24;
        $makeOffset = $laengeOffset + 24;
        $t = 'MM' . pack('n', 42) . pack('N', 8);
        $t .= pack('n', 3);
        $t .= pack('nnN', 0x010F, 2, strlen($make)) . pack('N', $makeOffset);
        $t .= pack('nnN', 0x0112, 3, 1) . pack('nn', $orientierung, 0);
        $t .= pack('nnN', 0x8825, 4, 1) . pack('N', $gpsOffset);
        $t .= pack('N', 0);
        $t .= pack('n', 4);
        $t .= pack('nnN', 0x0001, 2, 2) . "N\0\0\0";
        $t .= pack('nnN', 0x0002, 5, 3) . pack('N', $breiteOffset);
        $t .= pack('nnN', 0x0003, 2, 2) . "E\0\0\0";
        $t .= pack('nnN', 0x0004, 5, 3) . pack('N', $laengeOffset);
        $t .= pack('N', 0);
        $t .= pack('NNNNNN', 51, 1, 29, 1, 0, 1);
        $t .= pack('NNNNNN', 11, 1, 58, 1, 0, 1);
        $t .= $make;
        return $t;
    }
}
