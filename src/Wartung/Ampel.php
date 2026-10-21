<?php

declare(strict_types=1);

namespace Emc\Wartung;

/** Ampel der Fälligkeit einer Wartungsart an einem Objekt. */
enum Ampel: string
{
    case Rot = 'rot';
    case Gelb = 'gelb';
    case Gruen = 'gruen';

    /** Dringlichkeit für die Sortierung: Rot zuerst. */
    public function rang(): int
    {
        return match ($this) {
            self::Rot => 0,
            self::Gelb => 1,
            self::Gruen => 2,
        };
    }

    /** Bootstrap-Farbe (text-bg-…). */
    public function farbe(): string
    {
        return match ($this) {
            self::Rot => 'danger',
            self::Gelb => 'warning',
            self::Gruen => 'success',
        };
    }

    public function text(): string
    {
        return match ($this) {
            self::Rot => 'überfällig',
            self::Gelb => 'bald fällig',
            self::Gruen => 'in Ordnung',
        };
    }
}
