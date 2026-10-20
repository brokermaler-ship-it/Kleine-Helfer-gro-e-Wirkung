<?php

declare(strict_types=1);

namespace Emc\Auth;

/** Reihenfolge der Fälle = Rangfolge und Reihenfolge der Auswahlliste im Benutzerformular. */
enum Rolle: string
{
    case Mitglied = 'mitglied';
    case Fahrzeugwart = 'fahrzeugwart';
    case Bearbeiter = 'bearbeiter';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Mitglied => 'Mitglied',
            self::Fahrzeugwart => 'Fahrzeugwart',
            self::Bearbeiter => 'Bearbeiter',
            self::Admin => 'Administrator',
        };
    }
}
