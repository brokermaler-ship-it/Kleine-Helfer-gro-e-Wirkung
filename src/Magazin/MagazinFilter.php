<?php

declare(strict_types=1);

namespace Emc\Magazin;

use Emc\Core\Request;

/**
 * Filter der Magazinliste: Text (Bezeichnung, Hersteller, Artikelnummer, Autor/Verlag, ISBN oder Code M<n>), Kategorie,
 * Spurweite, Lagerort (genau) und – Standard – nur Artikel mit Bestand. Unbrauchbare Werte fallen auf „alle“ zurück.
 */
final class MagazinFilter
{
    public function __construct(
        public readonly ?string $text = null,
        public readonly ?int $kategorieId = null,
        public readonly ?int $spurweiteId = null,
        public readonly ?string $lagerort = null,
        public readonly bool $nurMitBestand = true,
    ) {
    }

    public static function ausRequest(Request $r): self
    {
        $text = mb_substr(trim($r->text('q')), 0, 100);
        $lagerort = mb_substr(trim($r->text('lagerort')), 0, 100);
        return new self(
            $text === '' ? null : $text,
            $r->ganzzahl('kategorie', 1, 4294967295),
            $r->ganzzahl('spurweite', 1, 4294967295),
            $lagerort === '' ? null : $lagerort,
            $r->text('alle') !== '1',
        );
    }

    /** Weicht vom Standard ab (Filterleiste am Handy aufgeklappt zeigen, „Zurücksetzen“ anbieten). */
    public function istGesetzt(): bool
    {
        return $this->text !== null || $this->kategorieId !== null || $this->spurweiteId !== null || $this->lagerort !== null || !$this->nurMitBestand;
    }
}
