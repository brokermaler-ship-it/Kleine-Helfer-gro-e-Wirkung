<?php

declare(strict_types=1);

namespace Emc\Codes;

/**
 * Kurze Codes für Fahrzeuge (F<n>, n = Zahl der Inventarnummer EMC-<n> ohne führende Nullen), Anlagen (A<n>,
 * siehe AnlagenCode) und Magazinartikel (M<n>, n = ID des Artikels). Reine Logik ohne Datenbank.
 */
final class Code
{
    /** Code eines Fahrzeugs („EMC-0012“ → „F12“); null, wenn die Inventarnummer nicht EMC-<Zahl> ist (auch EMC-0000). */
    public static function fahrzeug(string $inventarnummer): ?string
    {
        return preg_match('/^EMC-0*([1-9][0-9]*)$/', $inventarnummer, $m) === 1 ? 'F' . $m[1] : null;
    }

    /** Code eines Magazinartikels: „M“ und seine ID (37 → „M37“). */
    public static function magazin(int $id): string
    {
        return 'M' . $id;
    }

    /** Entfernt alle Leerraumzeichen (auch geschützte Leerzeichen) und schreibt groß. */
    public static function normalisiere(string $eingabe): string
    {
        $ohne = preg_replace('/\s+/u', '', $eingabe);
        // Ungültiges UTF-8: dann nur ASCII-Leerraum entfernen.
        $ohne ??= (string) preg_replace('/\s+/', '', $eingabe);
        return mb_strtoupper($ohne, 'UTF-8');
    }

    /**
     * @return ?array{typ:string, nummer:int} „F12“ → fahrzeug/12, „a3“ → anlage/3, „m 007“ → magazin/7; sonst null
     *         (auch F0, M0, F12a)
     */
    public static function zerlege(string $eingabe): ?array
    {
        if (preg_match('/^([FAM])0*([1-9][0-9]{0,8})$/', self::normalisiere($eingabe), $m) !== 1) {
            return null;
        }
        $typ = match ($m[1]) {
            'F' => 'fahrzeug',
            'A' => 'anlage',
            'M' => 'magazin',
        };
        return ['typ' => $typ, 'nummer' => (int) $m[2]];
    }

    /**
     * Reine Zahl (höchstens sechs Ziffern, führende Nullen egal) als Digitaladresse; sonst null. 0 ist keine Adresse
     * (in alten Daten ein Platzhalter) und geht wie anderer Text in die Suche.
     */
    public static function istAdresse(string $eingabe): ?int
    {
        $n = self::normalisiere($eingabe);
        return preg_match('/^(?=[0-9]{1,6}$)0*[1-9][0-9]*$/', $n) === 1 ? (int) $n : null;
    }
}
