<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Auth\Benutzer;
use Emc\Core\Request;

/**
 * Filter der Angebotsliste: Börse (oder nur dauerhafte), Status (Standard „offen“ = angeboten und reserviert, „alle“
 * oder ein einzelner Status), Freigabe, Herkunft, Person (meine=1: die eigene) und Text (Bezeichnung, Beschreibung,
 * Angebotsnummer). Unbrauchbare Werte fallen auf den Standard zurück.
 */
final class AngebotFilter
{
    public const OFFEN = 'offen';
    public const ALLE = 'alle';
    public const STATUS = ['angeboten', 'reserviert', 'verkauft', 'zurueck'];

    /** @param ?list<int> $ids nur diese Angebote (Preisschilder „einzeln“); null = ohne Einschränkung */
    public function __construct(
        public readonly ?int $boerseId = null,
        public readonly bool $dauerhaft = false,
        public readonly ?string $status = self::OFFEN,
        public readonly ?string $oeffentlich = null,
        public readonly ?string $herkunft = null,
        public readonly ?int $personId = null,
        public readonly ?string $text = null,
        public readonly ?array $ids = null,
        /** „Meine Angebote“ (meine=1); ohne Person am Konto bleibt die Liste leer statt alle zu zeigen. */
        public readonly bool $meine = false,
    ) {
    }

    public static function ausRequest(Request $r, ?Benutzer $b): self
    {
        $boerse = $r->text('boerse');
        $status = $r->text('status');
        $oeffentlich = $r->text('oeffentlich');
        $herkunft = $r->text('herkunft');
        $text = mb_substr(trim($r->text('q')), 0, 100);
        $meine = $r->text('meine') === '1';
        return new self(
            preg_match('/^\d{1,10}$/', $boerse) === 1 && (int) $boerse > 0 ? (int) $boerse : null,
            $boerse === 'dauerhaft',
            in_array($status, [...self::STATUS, self::ALLE], true) ? $status : self::OFFEN,
            in_array($oeffentlich, Freigabe::WERTE, true) ? $oeffentlich : null,
            in_array($herkunft, ['verein', 'mitglied'], true) ? $herkunft : null,
            $meine && $b !== null && $b->personId > 0 ? $b->personId : null,
            $text === '' ? null : $text,
            $meine && ($b === null || $b->personId <= 0) ? [] : null,
            $meine,
        );
    }

    /** Wert des Börsenfilters fürs Formular: „dauerhaft“, eine ID oder leer. */
    public function boerseWert(): string
    {
        return $this->dauerhaft ? 'dauerhaft' : ($this->boerseId === null ? '' : (string) $this->boerseId);
    }

    public function istGesetzt(): bool
    {
        return $this->boerseId !== null || $this->dauerhaft || $this->status !== self::OFFEN || $this->oeffentlich !== null
            || $this->herkunft !== null || $this->meine || $this->text !== null;
    }
}
