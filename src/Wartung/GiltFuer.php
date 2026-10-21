<?php

declare(strict_types=1);

namespace Emc\Wartung;

/** Wofür eine Wartungsart gilt (Spalte wartungsart.gilt_fuer). */
enum GiltFuer: string
{
    case FahrzeugAlle = 'fahrzeug_alle';
    case FahrzeugAntrieb = 'fahrzeug_antrieb';
    case FahrzeugWagen = 'fahrzeug_wagen';
    case Anlage = 'anlage';

    public function label(): string
    {
        return match ($this) {
            self::FahrzeugAlle => 'Fahrzeuge (alle)',
            self::FahrzeugAntrieb => 'Lok/Triebwagen',
            self::FahrzeugWagen => 'Wagen',
            self::Anlage => 'Anlagen',
        };
    }

    /**
     * Passt die Wartungsart zum Objekt? Sonderfahrzeuge bekommen nur die Arten für alle Fahrzeuge.
     *
     * @param string $objektTyp fahrzeug oder anlage
     * @param ?string $gattung Gattung des Fahrzeugs (lok, triebwagen, wagen, sonderfahrzeug); bei Anlagen null
     */
    public function passt(string $objektTyp, ?string $gattung): bool
    {
        if ($objektTyp === 'anlage') {
            return $this === self::Anlage;
        }
        if ($objektTyp !== 'fahrzeug') {
            return false;
        }
        return match ($this) {
            self::FahrzeugAlle => true,
            self::FahrzeugAntrieb => $gattung === 'lok' || $gattung === 'triebwagen',
            self::FahrzeugWagen => $gattung === 'wagen',
            self::Anlage => false,
        };
    }
}
