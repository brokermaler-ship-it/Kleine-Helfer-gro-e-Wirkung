<?php

declare(strict_types=1);

namespace Emc\Auth;

final class Berechtigung
{
    public const LESEN = 'lesen';
    public const FAHRZEUG_BEARBEITEN = 'fahrzeug_bearbeiten';
    public const PERSON_BEARBEITEN = 'person_bearbeiten';
    public const DATEI_BEARBEITEN = 'datei_bearbeiten';
    public const BENUTZER_VERWALTEN = 'benutzer_verwalten';
    public const STAMMDATEN_VERWALTEN = 'stammdaten_verwalten';
    public const BETRIEB = 'betrieb';

    /** Mindestrang je Aktion: 1 Mitglied, 2 Bearbeiter, 3 Admin. */
    private const MINDESTRANG = [
        self::LESEN => 1,
        self::FAHRZEUG_BEARBEITEN => 2,
        self::PERSON_BEARBEITEN => 2,
        self::DATEI_BEARBEITEN => 2,
        self::BENUTZER_VERWALTEN => 3,
        self::STAMMDATEN_VERWALTEN => 3,
        self::BETRIEB => 3,
    ];

    public static function darf(?Benutzer $b, string $aktion): bool
    {
        if ($b === null || !isset(self::MINDESTRANG[$aktion])) {
            return false;
        }
        $rang = match ($b->rolle) {
            Rolle::Mitglied => 1,
            Rolle::Bearbeiter => 2,
            Rolle::Admin => 3,
        };
        return $rang >= self::MINDESTRANG[$aktion];
    }
}
