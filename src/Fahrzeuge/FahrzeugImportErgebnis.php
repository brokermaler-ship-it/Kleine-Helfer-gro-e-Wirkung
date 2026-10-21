<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

/**
 * Ergebnis der Prüfung einer Fahrzeugliste: je Zeile Status „anlegen“, „fehler“ oder „zugverband“ (wird nicht
 * importiert), dazu Hinweis „doppelt“ und Fehler, die die ganze Datei betreffen (Kopfzeile, Grenzen, schon importiert).
 */
final class FahrzeugImportErgebnis
{
    /**
     * @param list<array<string,mixed>> $zeilen
     * @param list<string> $fehler
     */
    public function __construct(
        public readonly array $zeilen,
        public readonly array $fehler,
        /** Meldung „Diese Datei wurde am … bereits importiert“, sonst null (zählt nicht zu $fehler). */
        public readonly ?string $bereitsImportiert = null,
    ) {
    }

    /** @param string $status anlegen, fehler oder zugverband */
    public function anzahl(string $status): int
    {
        return count(array_filter($this->zeilen, fn(array $z): bool => $z['status'] === $status));
    }

    public function anzahlDoppelt(): int
    {
        return count(array_filter($this->zeilen, fn(array $z): bool => $z['doppelt'] === true));
    }

    /** Nur ohne Datei- und Zeilenfehler und mit mindestens einem anzulegenden Fahrzeug. */
    public function istImportierbar(): bool
    {
        return $this->fehler === [] && $this->bereitsImportiert === null && $this->anzahl('fehler') === 0 && $this->anzahl('anlegen') > 0;
    }

    /** @return list<array<string,mixed>> */
    public function anzulegende(): array
    {
        return array_values(array_filter($this->zeilen, fn(array $z): bool => $z['status'] === 'anlegen'));
    }

    /** Dieselben Zeilen mit dem Hinweis, dass die Datei schon importiert wurde (blockiert den Import). */
    public function alsBereitsImportiert(string $meldung): self
    {
        return new self($this->zeilen, $this->fehler, $meldung);
    }
}
