<?php

declare(strict_types=1);

namespace Emc\Wartung;

use Emc\Core\Request;

/** Filter der Übersicht „Wartung“: Objekttyp, Spurweite, Eigentum (Anlagen zählen als Verein). */
final class WartungFilter
{
    public const TYPEN = ['fahrzeug', 'anlage'];
    public const EIGENTUEMER = ['verein', 'mitglied'];

    public function __construct(
        public readonly ?string $typ = null,
        public readonly ?int $spurweiteId = null,
        public readonly ?string $eigentuemer = null,
    ) {
    }

    public static function ausRequest(Request $r): self
    {
        $typ = $r->text('typ');
        $eigentuemer = $r->text('eigentuemer');
        return new self(
            in_array($typ, self::TYPEN, true) ? $typ : null,
            $r->ganzzahl('spurweite', 1, 4294967295),
            in_array($eigentuemer, self::EIGENTUEMER, true) ? $eigentuemer : null,
        );
    }

    public function istGesetzt(): bool
    {
        return $this->typ !== null || $this->spurweiteId !== null || $this->eigentuemer !== null;
    }

    public function mitFahrzeugen(): bool
    {
        return $this->typ !== 'anlage';
    }

    public function mitAnlagen(): bool
    {
        return $this->typ !== 'fahrzeug' && $this->eigentuemer !== 'mitglied';
    }
}
