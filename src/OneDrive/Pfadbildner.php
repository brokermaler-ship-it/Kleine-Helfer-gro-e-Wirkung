<?php

declare(strict_types=1);

namespace Emc\OneDrive;

/** Baut den lesbaren Zielpfad einer Datei im OneDrive-Ordner des Vereins. */
final class Pfadbildner
{
    private const MAX_SEGMENT = 120;

    /**
     * @param array<string,mixed> $dokument titel, endung
     * @param ?array<string,mixed> $fahrzeug inventarnummer, bezeichnung
     * @param ?array<string,mixed> $anlage nummer, name (nur, wenn kein Fahrzeug angegeben ist)
     */
    public static function fuerDokument(array $dokument, ?array $fahrzeug, ?array $anlage = null): string
    {
        $endung = self::bereinige((string) ($dokument['endung'] ?? ''));
        $endung = $endung === '_' ? '' : $endung;
        $titel = trim((string) ($dokument['titel'] ?? ''));
        if ($endung !== '' && strtolower(mb_substr($titel, -(mb_strlen($endung) + 1))) === '.' . strtolower($endung)) {
            $titel = mb_substr($titel, 0, -(mb_strlen($endung) + 1));
            $schluss = '.' . mb_substr((string) ($dokument['titel'] ?? ''), -mb_strlen($endung));
        } else {
            $schluss = $endung === '' ? '' : '.' . $endung;
        }
        $name = self::bereinige($titel, self::MAX_SEGMENT - mb_strlen($schluss)) . $schluss;

        if ($fahrzeug === null && $anlage !== null) {
            $ordner = self::bereinige(trim('A' . (int) ($anlage['nummer'] ?? 0) . ' ' . (string) ($anlage['name'] ?? '')));
            return 'Anlagen/' . $ordner . '/' . $name;
        }
        if ($fahrzeug === null) {
            return 'Sonstiges/' . $name;
        }
        $ordner = self::bereinige(trim((string) ($fahrzeug['inventarnummer'] ?? '') . ' ' . (string) ($fahrzeug['bezeichnung'] ?? '')));
        return 'Fahrzeuge/' . $ordner . '/' . $name;
    }

    /** Ersetzt unzulässige Zeichen durch „_“, kürzt auf $max Zeichen und entfernt Leerzeichen/Punkte am Ende. */
    public static function bereinige(string $name, int $max = self::MAX_SEGMENT): string
    {
        $name = (string) preg_replace('~[\\\\/:*?"<>|\x00-\x1F\x7F]~u', '_', $name);
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = (string) mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }
        $name = trim($name);
        $name = mb_substr($name, 0, max(1, $max));
        $name = rtrim($name, " .\t");
        $name = ltrim($name);
        return $name === '' ? '_' : $name;
    }
}
