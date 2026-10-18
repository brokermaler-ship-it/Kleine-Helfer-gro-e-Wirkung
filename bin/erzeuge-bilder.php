<?php

declare(strict_types=1);

/**
 * Erzeugt die App-Symbole (aus public/assets/marke/logo-512.png) und die Demo-Fotos mit GD.
 * Nur bei Bedarf von Hand ausführen: php bin/erzeuge-bilder.php
 * Die erzeugten Dateien sind eingecheckt; das Skript wird zum Betrieb nicht gebraucht.
 */

$wurzel = dirname(__DIR__);

function schrift(): ?string
{
    foreach (['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf'] as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}

function zentriert(GdImage $bild, string $text, int $mitteX, int $mitteY, float $groesse, int $farbe): void
{
    $font = schrift();
    if ($font === null) {
        $b = imagefontwidth(5) * strlen($text);
        imagestring($bild, 5, (int) ($mitteX - $b / 2), $mitteY - 8, $text, $farbe);
        return;
    }
    $box = imagettfbbox($groesse, 0, $font, $text);
    $breite = $box[2] - $box[0];
    $hoehe = $box[1] - $box[7];
    imagettftext($bild, $groesse, 0, (int) ($mitteX - $breite / 2 - $box[0]), (int) ($mitteY + $hoehe / 2 - $box[1]), $farbe, $font, $text);
}

/**
 * App-Symbol aus dem Vereinslogo: Logo auf weißem Grund, zentriert.
 * $anteil = Breite des Logos relativ zur Symbolgröße (0,8 = ca. 10 % Rand; maskable: 0,70 für die Safe-Zone).
 */
function symbol(int $px, string $ziel, float $anteil = 0.8): void
{
    $logo = imagecreatefrompng(dirname(__DIR__) . '/public/assets/marke/logo-512.png');
    if ($logo === false) {
        fwrite(STDERR, "public/assets/marke/logo-512.png fehlt.\n");
        exit(1);
    }
    imagealphablending($logo, true);
    $b = imagecreatetruecolor($px, $px);
    imagefilledrectangle($b, 0, 0, $px, $px, imagecolorallocate($b, 255, 255, 255));
    imagealphablending($b, true);
    $lw = imagesx($logo);
    $lh = imagesy($logo);
    $zielBreite = (int) round($px * $anteil);
    $zielHoehe = (int) round($zielBreite * $lh / $lw);
    imagecopyresampled($b, $logo, intdiv($px - $zielBreite, 2), intdiv($px - $zielHoehe, 2), 0, 0, $zielBreite, $zielHoehe, $lw, $lh);
    imagepng($b, $ziel, 9);
}

function foto(string $ziel, string $titel, array $karosserie, array $himmel, string $art): void
{
    $w = 640;
    $h = 427;
    $b = imagecreatetruecolor($w, $h);
    $c = fn(array $rgb) => imagecolorallocate($b, $rgb[0], $rgb[1], $rgb[2]);
    imagefilledrectangle($b, 0, 0, $w, $h, $c($himmel));
    imagefilledrectangle($b, 0, 300, $w, $h, $c([90, 110, 80]));
    imagefilledrectangle($b, 0, 318, $w, 330, $c([70, 60, 50]));
    $k = $c($karosserie);
    $dunkel = $c([40, 40, 45]);
    $weiss = $c([255, 255, 255]);
    if ($art === 'diesel') {
        imagefilledrectangle($b, 90, 210, 550, 290, $k);
        imagefilledrectangle($b, 90, 190, 180, 210, $k);
        imagefilledrectangle($b, 460, 190, 550, 210, $k);
        imagefilledrectangle($b, 110, 225, 170, 250, $c([210, 230, 245]));
        imagefilledrectangle($b, 470, 225, 530, 250, $c([210, 230, 245]));
        imagefilledrectangle($b, 90, 275, 550, 285, $c([200, 40, 40]));
    } elseif ($art === 'dampf') {
        imagefilledrectangle($b, 110, 225, 330, 285, $dunkel);
        imagefilledellipse($b, 330, 255, 40, 60, $dunkel);
        imagefilledrectangle($b, 150, 190, 175, 225, $dunkel);
        imagefilledrectangle($b, 340, 190, 520, 285, $dunkel);
        imagefilledrectangle($b, 360, 205, 440, 245, $c([210, 230, 245]));
        imagefilledrectangle($b, 340, 180, 520, 192, $k);
        imagefilledrectangle($b, 100, 280, 520, 290, $k);
    } else {
        imagefilledrectangle($b, 60, 215, 580, 290, $k);
        for ($x = 80; $x < 560; $x += 60) {
            imagefilledrectangle($b, $x, 232, $x + 36, 262, $c([210, 230, 245]));
        }
        imagefilledrectangle($b, 60, 270, 580, 280, $weiss);
    }
    foreach ($art === 'wagen' ? [110, 180, 460, 530] : [120, 200, 440, 520] as $x) {
        imagefilledellipse($b, $x, 298, 38, 38, $dunkel);
        imagefilledellipse($b, $x, 298, 14, 14, $c([180, 180, 180]));
    }
    zentriert($b, $titel, intdiv($w, 2), 60, 22, $dunkel);
    zentriert($b, 'Beispielfoto', intdiv($w, 2), 385, 12, $weiss);
    imagejpeg($b, $ziel, 72);
}

$icons = $wurzel . '/public/assets/icons';
@mkdir($icons, 0775, true);
symbol(192, $icons . '/icon-192.png');
symbol(512, $icons . '/icon-512.png');
symbol(512, $icons . '/icon-maskable-512.png', 0.70);
symbol(180, $icons . '/apple-touch-icon.png');

$demo = $wurzel . '/tests/fixtures/demo';
@mkdir($demo, 0775, true);
foreach ([
    ['diesel', 'V 200', [160, 40, 50], [190, 215, 235], 'diesel'],
    ['dampf', 'BR 01', [60, 60, 70], [200, 205, 215], 'dampf'],
    ['wagen', 'Silberling', [150, 150, 160], [175, 205, 230], 'wagen'],
] as [$datei, $titel, $farbe, $himmel, $art]) {
    foto($demo . '/' . $datei . '.jpg', $titel, $farbe, $himmel, $art);
}
echo "Fertig.\n";
