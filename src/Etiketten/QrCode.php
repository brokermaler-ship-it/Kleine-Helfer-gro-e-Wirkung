<?php

declare(strict_types=1);

namespace Emc\Etiketten;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode as Erzeuger;
use chillerlan\QRCode\QROptions;

/** QR-Codes als SVG zum Einbetten ins HTML (serverseitig, chillerlan/php-qrcode). */
final class QrCode
{
    /** SVG ohne XML-Kopf; Fehlerkorrektur M, mit Ruhezone, nur dunkle Module (heller Grund bleibt das Etikett). */
    public static function svg(string $text): string
    {
        $optionen = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'svgAddXmlHeader' => false,
            'drawLightModules' => false,
            'connectPaths' => true,
        ]);
        $svg = (new Erzeuger($optionen))->render($text);
        // Falls eine Version den XML-Kopf trotzdem schreibt: abschneiden, damit das SVG inline ins HTML passt.
        $svg = (string) preg_replace('/^\s*<\?xml[^>]*\?>\s*/', '', $svg);
        return trim($svg);
    }
}
