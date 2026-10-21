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
    public const ETIKETTEN_DRUCKEN = 'etiketten_drucken';
    /** Abgeschlossene Börse wieder öffnen (nur Administratoren). */
    public const BOERSE_WIEDER_OEFFNEN = 'boerse_wieder_oeffnen';

    /** Ab diesem Rang darf jemand alles, was ein Börsenwart darf (Bearbeiter). */
    private const RANG_BOERSENWART = 3;

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
        self::ETIKETTEN_DRUCKEN => 2,
        self::BOERSE_WIEDER_OEFFNEN => 4,
    ];

    public static function darf(?Benutzer $b, string $aktion): bool
    {
        if ($b === null || !isset(self::MINDESTRANG[$aktion])) {
            return false;
        }
        return self::rang($b) >= self::MINDESTRANG[$aktion];
    }

    private static function rang(Benutzer $b): int
    {
        return match ($b->rolle) {
            Rolle::Mitglied => 1,
            Rolle::Fahrzeugwart => 2,
            Rolle::Bearbeiter => 3,
            Rolle::Admin => 4,
        };
    }

    /**
     * Börsenwart: Häkchen am Konto (unabhängig von der Rolle) oder Bearbeiter und höher. Ein Fahrzeugwart ohne Häkchen
     * ist kein Börsenwart.
     */
    public static function istBoersenwart(?Benutzer $b): bool
    {
        return $b !== null && ($b->istBoersenwart || self::rang($b) >= self::RANG_BOERSENWART);
    }

    /** @throws HttpFehler 403, wenn der Benutzer kein Börsenwart ist */
    public static function verlangeBoersenwart(?Benutzer $b): void
    {
        if (!self::istBoersenwart($b)) {
            throw new HttpFehler(403);
        }
    }

    /** „An Magazin übergeben“ (ausgemustertes Fahrzeug): Börsenwart oder Fahrzeugwart und höher. */
    public static function darfFahrzeugAnMagazin(?Benutzer $b): bool
    {
        return self::istBoersenwart($b) || self::darf($b, self::FAHRZEUG_BEARBEITEN);
    }

    /**
     * Angebot der Börse bearbeiten: der Börsenwart jedes; ein Mitglied nur sein eigenes Angebot (Herkunft Mitglied,
     * eigene Person), solange es noch angeboten ist. Ob die Börse abgeschlossen oder das Stück verkauft ist, prüft der
     * Dienst für alle.
     *
     * @param array<string,mixed> $angebot
     */
    public static function darfAngebotBearbeiten(?Benutzer $b, array $angebot): bool
    {
        if ($b === null) {
            return false;
        }
        if (self::istBoersenwart($b)) {
            return true;
        }
        $person = $angebot['person_id'] ?? null;
        return ($angebot['herkunft'] ?? null) === 'mitglied'
            && $person !== null && (int) $person === $b->personId
            && ($angebot['status'] ?? null) === 'angeboten';
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
     * Wartungen an einem Fahrzeug eintragen: Fahrzeugwart und höher an allen Fahrzeugen, Mitglieder an ihren eigenen
     * (Eigentümer ist ihre Person oder sie haben das Fahrzeug selbst angelegt).
     *
     * @param array<string,mixed> $fahrzeug
     */
    public static function darfFahrzeugWarten(?Benutzer $b, array $fahrzeug): bool
    {
        if ($b === null) {
            return false;
        }
        if (self::darf($b, self::FAHRZEUG_BEARBEITEN)) {
            return true;
        }
        $eigentuemer = $fahrzeug['eigentuemer_person_id'] ?? null;
        $anleger = $fahrzeug['angelegt_von_benutzer_id'] ?? null;
        return ($eigentuemer !== null && (int) $eigentuemer === $b->personId)
            || ($anleger !== null && (int) $anleger === $b->id);
    }

    /**
     * @param string $objektTyp fahrzeug oder anlage
     * @param array<string,mixed> $objekt
     */
    public static function darfWartungEintragen(?Benutzer $b, string $objektTyp, array $objekt): bool
    {
        return match ($objektTyp) {
            'fahrzeug' => self::darfFahrzeugWarten($b, $objekt),
            'anlage' => self::darf($b, self::ANLAGE_BEARBEITEN),
            default => false,
        };
    }

    /** Ausnahmen im Wartungsplan (abschalten, eigenes Intervall): Fahrzeugwart bei Fahrzeugen, Bearbeiter bei Anlagen. */
    public static function darfWartungsplanAendern(?Benutzer $b, string $objektTyp): bool
    {
        return match ($objektTyp) {
            'fahrzeug' => self::darf($b, self::FAHRZEUG_BEARBEITEN),
            'anlage' => self::darf($b, self::ANLAGE_BEARBEITEN),
            default => false,
        };
    }

    /**
     * Reparaturen bearbeiten (planen, abschließen, verwerfen): gleiche Regel wie Wartungen eintragen – Fahrzeugwart und
     * höher an allen Fahrzeugen, Mitglieder an ihren eigenen, Bearbeiter und höher an Anlagen.
     *
     * @param string $objektTyp fahrzeug oder anlage
     * @param array<string,mixed> $objekt
     */
    public static function darfReparaturBearbeiten(?Benutzer $b, string $objektTyp, array $objekt): bool
    {
        return self::darfWartungEintragen($b, $objektTyp, $objekt);
    }

    /**
     * Hinweise auf neue Defektmeldungen: Fahrzeugwart und höher für Fahrzeuge, Bearbeiter und höher für Anlagen
     * (gleiche Regel wie darfWartungsplanAendern).
     */
    public static function siehtDefekthinweise(?Benutzer $b, string $objektTyp): bool
    {
        return self::darfWartungsplanAendern($b, $objektTyp);
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
