<?php

declare(strict_types=1);

namespace Emc\Wartung;

use Emc\Core\Request;

/**
 * Filter der Übersicht „Wartung“: Objekttyp, Spurweite, Eigentum (Anlagen zählen als Verein) und Stand
 * (rot = überfällig, gelb = bald fällig, nie = noch nie erfasst, gruen = in Ordnung).
 */
final class WartungFilter
{
    public const TYPEN = ['fahrzeug', 'anlage'];
    public const EIGENTUEMER = ['verein', 'mitglied'];
    /** Stand-Filter mit Anzeigenamen (Schlüssel wie Faelligkeit::kategorie). */
    public const STAENDE = ['rot' => 'überfällig', 'gelb' => 'bald fällig', 'nie' => 'noch nie erfasst', 'gruen' => 'in Ordnung'];

    public function __construct(
        public readonly ?string $typ = null,
        public readonly ?int $spurweiteId = null,
        public readonly ?string $eigentuemer = null,
        public readonly ?string $stand = null,
    ) {
    }

    public static function ausRequest(Request $r): self
    {
        $typ = $r->text('typ');
        $eigentuemer = $r->text('eigentuemer');
        $stand = $r->text('stand');
        return new self(
            in_array($typ, self::TYPEN, true) ? $typ : null,
            $r->ganzzahl('spurweite', 1, 4294967295),
            in_array($eigentuemer, self::EIGENTUEMER, true) ? $eigentuemer : null,
            isset(self::STAENDE[$stand]) ? $stand : null,
        );
    }

    public function istGesetzt(): bool
    {
        return $this->typ !== null || $this->spurweiteId !== null || $this->eigentuemer !== null || $this->stand !== null;
    }

    /** Derselbe Filter ohne Stand (für die Zählung je Stand). */
    public function ohneStand(): self
    {
        return new self($this->typ, $this->spurweiteId, $this->eigentuemer);
    }

    public function passtStand(Faelligkeit $f): bool
    {
        return $this->stand === null || $f->kategorie() === $this->stand;
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
