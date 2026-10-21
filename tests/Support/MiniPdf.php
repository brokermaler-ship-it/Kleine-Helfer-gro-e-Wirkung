<?php

declare(strict_types=1);

namespace Emc\Tests\Support;

/**
 * Erzeugt kleine, gültige PDF-1.4-Dateien für Tests (keine eingecheckten Binärdateien).
 */
final class MiniPdf
{
    /**
     * Eine Seite je innerer Liste, jede Zeichenkette eine Textzeile (Helvetica, WinAnsiEncoding).
     *
     * @param list<list<string>> $seiten
     */
    public static function erzeuge(array $seiten): string
    {
        $inhalte = [];
        foreach ($seiten as $zeilen) {
            $strom = "BT\n/F1 11 Tf\n14 TL\n50 800 Td\n";
            foreach ($zeilen as $zeile) {
                $strom .= '(' . self::maskiere($zeile) . ") Tj T*\n";
            }
            $inhalte[] = $strom . "ET\n";
        }
        return self::baue($inhalte);
    }

    /** Eine Seite nur mit einem gefüllten Rechteck, ohne Text (wie ein Scan ohne Texterkennung). */
    public static function ohneText(): string
    {
        return self::baue(["0.5 g\n50 50 400 600 re f\n"]);
    }

    /**
     * Eine Textseite, deren Inhalt zusätzlich einen FlateDecode-Strom mit $entpacktBytes Nullbytes enthält
     * (weitere Inhaltsströme der Seite; „Zip-Bombe“ für Grenztests).
     *
     * @param list<string> $zeilen
     * @param int $anzahl so viele solche Ströme
     */
    public static function mitGepacktemStrom(array $zeilen, int $entpacktBytes, int $anzahl = 1): string
    {
        $text = "BT\n/F1 11 Tf\n14 TL\n50 800 Td\n";
        foreach ($zeilen as $zeile) {
            $text .= '(' . self::maskiere($zeile) . ") Tj T*\n";
        }
        $gepackt = (string) gzcompress(str_repeat("\0", $entpacktBytes), 9);
        $strom = '<< /Length ' . strlen($gepackt) . " /Filter /FlateDecode >>\nstream\n" . $gepackt . "\nendstream";
        return self::baue([$text . "ET\n"], array_fill(0, $anzahl, $strom));
    }

    private static function maskiere(string $text): string
    {
        $text = str_replace("\t", ' ', $text);
        $text = (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    /**
     * @param list<string> $inhalte Inhaltsströme, einer je Seite
     * @param list<string> $zusatz weitere Inhaltsströme (fertige Objektkörper) der ersten Seite
     */
    private static function baue(array $inhalte, array $zusatz = []): string
    {
        $objekte = [];
        $objekte[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objekte[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $kinder = [];
        $nr = 4;
        foreach ($inhalte as $i => $strom) {
            $seite = $nr++;
            $inhalt = $nr++;
            $kinder[] = $seite . ' 0 R';
            $verweise = [$inhalt . ' 0 R'];
            $objekte[$inhalt] = '<< /Length ' . strlen($strom) . " >>\nstream\n" . $strom . "endstream";
            if ($i === 0) {
                foreach ($zusatz as $koerper) {
                    $verweise[] = $nr . ' 0 R';
                    $objekte[$nr++] = $koerper;
                }
            }
            $objekte[$seite] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ['
                . implode(' ', $verweise) . '] >>';
        }
        $objekte[2] = '<< /Type /Pages /Kids [' . implode(' ', $kinder) . '] /Count ' . count($kinder) . ' >>';
        return self::ausObjekten($objekte);
    }

    /**
     * Fertiges PDF aus Objektkörpern (Schlüssel = Objektnummer, Objekt 1 ist der Katalog); fehlende Nummern werden
     * in der Querverweistabelle als frei eingetragen. Für Tests mit selbst gebauten (auch bösartigen) Objekten.
     *
     * @param array<int,string> $objekte
     */
    public static function ausObjekten(array $objekte, string $trailerZusatz = ''): string
    {
        ksort($objekte);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objekte as $id => $koerper) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $koerper . "\nendobj\n";
        }
        $anzahl = ($objekte === [] ? 0 : max(array_keys($objekte))) + 1;
        $xref = strlen($pdf);
        $teile = ["xref\n0 " . $anzahl . "\n0000000000 65535 f \n"];
        for ($i = 1; $i < $anzahl; $i++) {
            $teile[] = isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $pdf .= implode('', $teile);
        $pdf .= "trailer\n<< /Size " . $anzahl . " /Root 1 0 R" . $trailerZusatz . " >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    /** Katalog, Seitenbaum und Schrift (Objekte 1–3) für eine Seite 4 mit Inhalt 5 und eigenen Ressourcen. */
    public static function seitenObjekte(string $inhalt, string $ressourcenZusatz = ''): array
    {
        return [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [4 0 R] /Count 1 >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >>' . $ressourcenZusatz
                . ' >> /Contents 5 0 R >>',
            5 => self::strom($inhalt),
        ];
    }

    /** Ungepackter Strom als Objektkörper. */
    public static function strom(string $daten, string $woerterbuch = ''): string
    {
        return '<< /Length ' . strlen($daten) . $woerterbuch . " >>\nstream\n" . $daten . "\nendstream";
    }

    /** Text-Inhaltsstrom aus Zeilen (wie erzeuge()). @param list<string> $zeilen */
    public static function text(array $zeilen): string
    {
        $strom = "BT\n/F1 11 Tf\n14 TL\n50 800 Td\n";
        foreach ($zeilen as $zeile) {
            $strom .= '(' . self::maskiere($zeile) . ") Tj T*\n";
        }
        return $strom . "ET\n";
    }
}
