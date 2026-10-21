<?php

declare(strict_types=1);

namespace Emc\Dateien;

/**
 * Größengrenzen für Uploads, wie Formulare sie anzeigen und das Skript vor dem Absenden prüft: je Datei das Kleinste
 * aus upload_max_mb (config.php), upload_max_filesize und post_max_size (php.ini), für die ganze Anfrage post_max_size (in Tests
 * überschreibbar mit post_max_bytes). 0 = keine Grenze.
 */
final class Uploadgrenzen
{
    private const MB = 1048576;
    /** Platz in der Anfrage für Text und Formularfelder neben den Fotos. */
    public const RESERVE = 65536;

    public function __construct(public readonly int $dateiBytes, public readonly int $gesamtBytes)
    {
    }

    /**
     * @param array<string,mixed> $config
     * @param ?string $uploadMaxIni bzw. $postMaxIni php.ini-Werte (Standard: aktuelle Einstellung; nur Tests geben sie vor)
     */
    public static function ausConfig(array $config, ?string $uploadMaxIni = null, ?string $postMaxIni = null): self
    {
        $datei = max(1, (int) ($config['upload_max_mb'] ?? 64)) * self::MB;
        $ini = UploadService::iniBytes($uploadMaxIni ?? (string) ini_get('upload_max_filesize'));
        if ($ini > 0) {
            $datei = min($datei, $ini);
        }
        $gesamt = isset($config['post_max_bytes'])
            ? max(0, (int) $config['post_max_bytes'])
            : UploadService::iniBytes($postMaxIni ?? (string) ini_get('post_max_size'));
        // Eine einzelne Datei kann nie größer sein als die ganze Anfrage.
        return new self($gesamt > 0 ? min($datei, $gesamt) : $datei, $gesamt);
    }

    /** Höchstsumme aller Fotos einer Anfrage (0 = keine Grenze). */
    public function fotoBytes(): int
    {
        if ($this->gesamtBytes <= 0) {
            return 0;
        }
        return max(intdiv($this->gesamtBytes, 2), $this->gesamtBytes - self::RESERVE);
    }

    public static function mb(int $bytes): int
    {
        return max(1, intdiv($bytes, self::MB));
    }

    /** z. B. „höchstens 8 MB je Foto, zusammen höchstens 8 MB“ */
    public function text(): string
    {
        $text = 'höchstens ' . self::mb($this->dateiBytes) . ' MB je Foto';
        return $this->gesamtBytes > 0 ? $text . ', zusammen höchstens ' . self::mb($this->gesamtBytes) . ' MB' : $text;
    }
}
