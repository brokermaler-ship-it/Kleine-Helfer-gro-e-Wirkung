<?php

declare(strict_types=1);

namespace Emc\Digital;

enum Protokoll: string
{
    case Dcc = 'dcc';
    case Motorola = 'motorola';
    case Mfx = 'mfx';
    case Selectrix = 'selectrix';

    public function label(): string
    {
        return match ($this) {
            self::Dcc => 'DCC',
            self::Motorola => 'Motorola',
            self::Mfx => 'mfx',
            self::Selectrix => 'Selectrix',
        };
    }
}
