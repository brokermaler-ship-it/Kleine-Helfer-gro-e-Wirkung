<?php

declare(strict_types=1);

namespace Emc\Reparatur;

/** Ablauf einer Reparatur: gemeldet → geplant → in Arbeit → wartet auf Teile → erledigt, außerdem verworfen. */
enum ReparaturStatus: string
{
    case Gemeldet = 'gemeldet';
    case Geplant = 'geplant';
    case InArbeit = 'in_arbeit';
    case WartetAufTeile = 'wartet_auf_teile';
    case Erledigt = 'erledigt';
    case Verworfen = 'verworfen';

    /** SQL-Liste der offenen Status (für „status IN …“); muss zu istOffen() passen. */
    public const OFFEN_SQL = "('gemeldet','geplant','in_arbeit','wartet_auf_teile')";

    public function label(): string
    {
        return match ($this) {
            self::Gemeldet => 'gemeldet',
            self::Geplant => 'geplant',
            self::InArbeit => 'in Arbeit',
            self::WartetAufTeile => 'wartet auf Teile',
            self::Erledigt => 'erledigt',
            self::Verworfen => 'verworfen',
        };
    }

    /** Offen sind alle außer erledigt und verworfen. */
    public function istOffen(): bool
    {
        return $this !== self::Erledigt && $this !== self::Verworfen;
    }
}
