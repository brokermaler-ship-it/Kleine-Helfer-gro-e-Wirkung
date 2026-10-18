<?php

declare(strict_types=1);

namespace Emc\Dateien;

/** Positivliste der erlaubten Dateitypen. Alles, was hier fehlt (php, svg, html …), wird abgelehnt. */
final class DateiTyp
{
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @return array<string,array{mimes:list<string>,anzeige:string,bild:bool}> */
    private static function liste(): array
    {
        return [
            'jpg' => ['mimes' => ['image/jpeg'], 'anzeige' => 'inline', 'bild' => true],
            'jpeg' => ['mimes' => ['image/jpeg'], 'anzeige' => 'inline', 'bild' => true],
            'png' => ['mimes' => ['image/png'], 'anzeige' => 'inline', 'bild' => true],
            'webp' => ['mimes' => ['image/webp'], 'anzeige' => 'inline', 'bild' => true],
            'pdf' => ['mimes' => ['application/pdf'], 'anzeige' => 'inline', 'bild' => false],
            'txt' => ['mimes' => ['text/plain'], 'anzeige' => 'inline', 'bild' => false],
            'csv' => ['mimes' => ['text/plain', 'text/csv'], 'anzeige' => 'inline', 'bild' => false],
            'docx' => ['mimes' => [self::DOCX, 'application/zip', 'application/octet-stream'], 'anzeige' => 'download', 'bild' => false],
            'xlsx' => ['mimes' => [self::XLSX, 'application/zip', 'application/octet-stream'], 'anzeige' => 'download', 'bild' => false],
            'odt' => ['mimes' => ['application/vnd.oasis.opendocument.text', 'application/zip'], 'anzeige' => 'download', 'bild' => false],
            'ods' => ['mimes' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'], 'anzeige' => 'download', 'bild' => false],
        ];
    }

    /** @return ?array{mimes:list<string>,anzeige:string,bild:bool} */
    public static function fuerEndung(string $endung): ?array
    {
        return self::liste()[strtolower($endung)] ?? null;
    }

    /** Für das accept-Attribut des Dateifelds, z. B. ".jpg,.png,.pdf". */
    public static function acceptAttribut(): string
    {
        return implode(',', array_map(fn(string $e): string => '.' . $e, array_keys(self::liste())));
    }
}
