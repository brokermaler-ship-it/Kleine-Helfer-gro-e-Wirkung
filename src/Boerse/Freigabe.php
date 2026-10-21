<?php

declare(strict_types=1);

namespace Emc\Boerse;

/**
 * Freigabe-Workflow für die Veröffentlichung eines Angebots (nein / vorgeschlagen / freigegeben). Mitglieder schlagen
 * vor, der Börsenwart gibt frei. Ändert ein Mitglied ein freigegebenes Angebot (Text, Preis, Fotos …), fällt es auf
 * „vorgeschlagen“ zurück. Reine Logik.
 */
final class Freigabe
{
    public const WERTE = ['nein', 'vorgeschlagen', 'freigegeben'];
    /** Öffentlich sichtbare Felder: eine Änderung daran verlangt eine neue Freigabe. */
    public const FELDER = ['bezeichnung', 'beschreibung', 'wunschpreis', 'kategorie_id', 'spurweite_id', 'zustand'];

    /**
     * @param array<string,mixed> $alt
     * @param array<string,mixed> $neu
     */
    public static function inhaltGeaendert(array $alt, array $neu): bool
    {
        foreach (self::FELDER as $feld) {
            $a = $alt[$feld] ?? null;
            $b = $neu[$feld] ?? null;
            if ($feld === 'wunschpreis' && $a !== null && $b !== null) {
                if (Geld::cent((string) $a) !== Geld::cent((string) $b)) {
                    return true;
                }
                continue;
            }
            if (($a === null) !== ($b === null) || (string) $a !== (string) $b) {
                return true;
            }
        }
        return false;
    }

    /** Neuer Wert von „oeffentlich“ nach einer Änderung: nur freigegeben + Mitglied + Inhalt geändert → vorgeschlagen. */
    public static function nachAenderung(string $oeffentlich, bool $durchBoersenwart, bool $geaendert): string
    {
        return $oeffentlich === 'freigegeben' && !$durchBoersenwart && $geaendert ? 'vorgeschlagen' : $oeffentlich;
    }

    /**
     * Darf der Wert von $von auf $nach wechseln? Mitglied (eigenes Angebot): nein → vorgeschlagen, vorgeschlagen → nein,
     * freigegeben → nein. Börsenwart: alles nach freigegeben oder nein. Vereinsangebote sind nie „vorgeschlagen“.
     */
    public static function erlaubt(string $von, string $nach, bool $boersenwart, string $herkunft): bool
    {
        if (!in_array($von, self::WERTE, true) || !in_array($nach, self::WERTE, true) || $von === $nach) {
            return false;
        }
        if ($herkunft === 'verein' && $nach === 'vorgeschlagen') {
            return false;
        }
        if ($boersenwart) {
            return $nach === 'freigegeben' || $nach === 'nein';
        }
        if ($herkunft !== 'mitglied') {
            return false;
        }
        return ($von === 'nein' && $nach === 'vorgeschlagen') || $nach === 'nein';
    }
}
