<?php

declare(strict_types=1);

namespace Emc\Auth;

enum Rolle: string
{
    case Mitglied = 'mitglied';
    case Bearbeiter = 'bearbeiter';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Mitglied => 'Mitglied',
            self::Bearbeiter => 'Bearbeiter',
            self::Admin => 'Administrator',
        };
    }
}
