<?php

declare(strict_types=1);

namespace Emc\Reparatur;

use Emc\Core\Request;

/**
 * Filter der Seite „Reparaturen“: Status (ein Statuswert, „offen“ = alle offenen, Standard, oder „alle“), Objekttyp und
 * zuständige Person. Unbekannte Werte fallen auf den Standard zurück.
 */
final class ReparaturFilter
{
    public const OFFEN = 'offen';
    public const ALLE = 'alle';
    public const TYPEN = ['fahrzeug' => 'Fahrzeuge', 'anlage' => 'Anlagen'];

    public function __construct(
        public readonly ?string $status = self::OFFEN,
        public readonly ?string $typ = null,
        public readonly ?int $zustaendigId = null,
    ) {
    }

    public static function ausRequest(Request $r): self
    {
        $status = $r->text('status');
        $typ = $r->text('typ');
        return new self(
            $status === self::ALLE || ReparaturStatus::tryFrom($status) !== null ? $status : self::OFFEN,
            isset(self::TYPEN[$typ]) ? $typ : null,
            $r->ganzzahl('zustaendig', 1, 4294967295),
        );
    }

    /** Weicht vom Standard ab (Filterleiste am Handy aufgeklappt zeigen). */
    public function istGesetzt(): bool
    {
        return ($this->status ?? self::OFFEN) !== self::OFFEN || $this->typ !== null || $this->zustaendigId !== null;
    }
}
