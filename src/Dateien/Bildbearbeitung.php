<?php

declare(strict_types=1);

namespace Emc\Dateien;

/** Erzeugt aufrechte, verkleinerte JPEG-Varianten (Anzeige 1600 px, Vorschau 400 px). */
final class Bildbearbeitung
{
    private const MAX_PIXEL = 40000000;
    private const QUALITAET = 82;

    private ?\Closure $budget;

    /** @param ?\Closure():?int $budget frei verfügbarer Speicher in Byte (null = unbegrenzt); Standard: memory_limit abzüglich Verbrauch */
    public function __construct(?\Closure $budget = null)
    {
        $this->budget = $budget;
    }

    private function verfuegbar(): ?int
    {
        if ($this->budget !== null) {
            return ($this->budget)();
        }
        $roh = trim((string) ini_get('memory_limit'));
        if ($roh === '-1') {
            return null;
        }
        $limit = UploadService::iniBytes($roh);
        return $limit <= 0 ? null : $limit - memory_get_usage(true);
    }

    public function erzeugeVarianten(string $quelle, string $zielBasis): bool
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return false;
        }
        $info = @getimagesize($quelle);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXEL) {
            return false;
        }
        $drehen = $this->orientierung($quelle, $info[2]) > 1;
        $bedarf = (int) ($info[0] * $info[1] * 5 * ($drehen ? 2 : 1)) + 16 * 1048576 + (int) (@filesize($quelle) ?: 0);
        $frei = $this->verfuegbar();
        if ($frei !== null && $bedarf > $frei) {
            return false; // lieber ohne Varianten als ein nicht abfangbarer Speicherfehler
        }
        $daten = @file_get_contents($quelle);
        $bild = $daten === false ? false : @imagecreatefromstring($daten);
        unset($daten);
        if ($bild === false) {
            return false;
        }
        try {
            $bild = $this->richteAus($bild, $this->orientierung($quelle, $info[2]));
            $anzeige = $zielBasis . '.anzeige.jpg';
            $vorschau = $zielBasis . '.vorschau.jpg';
            if (!$this->schreibe($bild, 1600, $anzeige) || !$this->schreibe($bild, 400, $vorschau)) {
                @unlink($anzeige);
                @unlink($vorschau);
                return false;
            }
            return true;
        } finally {
            unset($bild);
        }
    }

    private function orientierung(string $quelle, int $typ): int
    {
        if ($typ !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($quelle);
        $o = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;
        return is_int($o) && $o >= 1 && $o <= 8 ? $o : 1;
    }

    private function richteAus(\GdImage $bild, int $o): \GdImage
    {
        if (in_array($o, [2, 4, 5, 7], true)) {
            imageflip($bild, $o === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $grad = match ($o) {
            3, 4 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };
        if ($grad === 0) {
            return $bild;
        }
        $weiss = imagecolorallocate($bild, 255, 255, 255);
        $gedreht = imagerotate($bild, $grad, (int) $weiss);
        return $gedreht === false ? $bild : $gedreht;
    }

    private function schreibe(\GdImage $bild, int $maxSeite, string $ziel): bool
    {
        $b = imagesx($bild);
        $h = imagesy($bild);
        $faktor = min(1.0, $maxSeite / max($b, $h));
        $nb = max(1, (int) round($b * $faktor));
        $nh = max(1, (int) round($h * $faktor));
        $neu = imagecreatetruecolor($nb, $nh);
        if ($neu === false) {
            return false;
        }
        imagefill($neu, 0, 0, (int) imagecolorallocate($neu, 255, 255, 255)); // transparente PNG/WebP auf Weiß
        imagecopyresampled($neu, $bild, 0, 0, 0, 0, $nb, $nh, $b, $h);
        $ok = imagejpeg($neu, $ziel, self::QUALITAET);
        if ($ok) {
            @chmod($ziel, 0640);
        }
        return $ok;
    }
}
