<?php

declare(strict_types=1);

namespace Emc\Auth;

use Emc\Core\HttpFehler;

final class Berechtigung
{
    public const LESEN = 'lesen';
    public const FAHRZEUG_ANLEGEN = 'fahrzeug_anlegen';
    public const FAHRZEUG_BEARBEITEN = 'fahrzeug_bearbeiten';
    public const PERSON_BEARBEITEN = 'person_bearbeiten';
    public const DATEI_BEARBEITEN = 'datei_bearbeiten';
    public const BENUTZER_VERWALTEN = 'benutzer_verwalten';
    public const STAMMDATEN_VERWALTEN = 'stammdaten_verwalten';
    public const BETRIEB = 'betrieb';
    public const PERSON_DSGVO_LOESCHEN = 'person_dsgvo_loeschen';
    public const ANLAGE_BEARBEITEN = 'anlage_bearbeiten';
    public const WARTUNGSART_VERWALTEN = 'wartungsart_verwalten';

    /** Mindestrang je Aktion: 1 Mitglied, 2 Fahrzeugwart, 3 Bearbeiter, 4 Admin. */
    private const MINDESTRANG = [
        self::LESEN => 1,
        self::FAHRZEUG_ANLEGEN => 1,
        self::FAHRZEUG_BEARBEITEN => 2,
        self::PERSON_BEARBEITEN => 3,
        self::DATEI_BEARBEITEN => 3,
        self::BENUTZER_VERWALTEN => 4,
        self::STAMMDATEN_VERWALTEN => 4,
        self::BETRIEB => 4,
        self::PERSON_DSGVO_LOESCHEN => 4,
        self::ANLAGE_BEARBEITEN => 3,
        self::WARTUNGSART_VERWALTEN => 2,
    ];

    public static function darf(?Benutzer $b, string $aktion): bool
    {
        if ($b === null || !isset(self::MINDESTRANG[$aktion])) {
            return false;
        }
        $rang = match ($b->rolle) {
            Rolle::Mitglied => 1,
            Rolle::Fahrzeugwart => 2,
            Rolle::Bearbeiter => 3,
            Rolle::Admin => 4,
        };
        return $rang >= self::MINDESTRANG[$aktion];
    }

    /**
     * Zentrale Objektprüfung: Fahrzeugwart und höher dürfen jedes Fahrzeug bearbeiten (auch private),
     * Mitglieder nur die von ihnen selbst angelegten (NULL = Altbestand, gilt als von einem Bearbeiter angelegt).
     * Das Löschen bleibt davon unberührt (nur FAHRZEUG_BEARBEITEN).
     *
     * @param array<string,mixed> $fahrzeug
     */
    public static function darfFahrzeugBearbeiten(?Benutzer $b, array $fahrzeug): bool
    {
        if ($b === null) {
            return false;
        }
        if (self::darf($b, self::FAHRZEUG_BEARBEITEN)) {
            return true;
        }
        $anleger = $fahrzeug['angelegt_von_benutzer_id'] ?? null;
        return $anleger !== null && (int) $anleger === $b->id;
    }

    /**
     * @param array<string,mixed> $fahrzeug
     * @throws HttpFehler 403, wenn der Benutzer dieses Fahrzeug nicht bearbeiten darf
     */
    public static function verlangeFahrzeugBearbeiten(?Benutzer $b, array $fahrzeug): void
    {
        if (!self::darfFahrzeugBearbeiten($b, $fahrzeug)) {
            throw new HttpFehler(403);
        }
    }
}
