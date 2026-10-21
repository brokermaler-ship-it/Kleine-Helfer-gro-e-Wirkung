<?php

declare(strict_types=1);

namespace Emc\Magazin;

/**
 * Prüft die Felder eines Magazinartikels. Autor/Verlag, Jahr und ISBN gelten nur bei der Kategorie „Literatur“ und
 * werden sonst geleert (ohne JavaScript sind die Felder immer sichtbar). Der Lagerort wird nur bei der Neuanlage
 * gesetzt, danach ändert ihn nur eine Umlagerung.
 */
final class ArtikelValidator
{
    public const ZUSTAENDE = ['neu', 'neuwertig', 'gebraucht', 'defekt'];
    /** Feld => Höchstlänge in Zeichen. */
    private const TEXTFELDER = [
        'bezeichnung' => 200,
        'hersteller' => 100,
        'artikelnummer' => 60,
        'lagerort' => 100,
        'notiz' => 5000,
        'autor_verlag' => 200,
        'isbn' => 20,
    ];
    private const MAX_ID = 4294967295;

    /**
     * @param array<string,mixed> $eingabe Rohdaten aus dem Formular
     * @param list<int> $kategorieIds vorhandene Kategorien
     * @param list<int> $spurweitenIds vorhandene Spurweiten
     * @param ?int $literaturId Kategorie mit dem Schlüssel „literatur“
     * @param bool $mitLagerort Neuanlage (Lagerort gehört dazu)
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    public static function pruefe(array $eingabe, array $kategorieIds, array $spurweitenIds, ?int $literaturId, bool $mitLagerort = true): array
    {
        $d = [];
        foreach (array_keys(self::TEXTFELDER) as $feld) {
            $d[$feld] = self::text($eingabe[$feld] ?? null);
        }
        $d['kategorie_id'] = self::id($eingabe['kategorie_id'] ?? null);
        $d['spurweite_id'] = self::id($eingabe['spurweite_id'] ?? null);
        $d['zustand'] = self::text($eingabe['zustand'] ?? null) ?? 'gebraucht';
        $jahr = self::text($eingabe['jahr'] ?? null);

        $f = [];
        if ($d['bezeichnung'] === null) {
            $f['bezeichnung'] = 'Bitte eine Bezeichnung eingeben.';
        }
        foreach (self::TEXTFELDER as $feld => $max) {
            if ($d[$feld] !== null && mb_strlen($d[$feld]) > $max) {
                $f[$feld] = 'Bitte höchstens ' . $max . ' Zeichen eingeben.';
            }
        }
        if ($d['kategorie_id'] === null || !in_array($d['kategorie_id'], $kategorieIds, true)) {
            $f['kategorie_id'] = 'Bitte eine Kategorie wählen.';
        }
        $spurRoh = self::text($eingabe['spurweite_id'] ?? null);
        if ($spurRoh !== null && ($d['spurweite_id'] === null || !in_array($d['spurweite_id'], $spurweitenIds, true))) {
            $f['spurweite_id'] = 'Bitte eine Spurweite aus der Liste wählen.';
        }
        if (!in_array($d['zustand'], self::ZUSTAENDE, true)) {
            $f['zustand'] = 'Bitte einen Zustand wählen.';
        }
        $d['jahr'] = null;
        if ($jahr !== null) {
            if (preg_match('/^\d{4}$/', $jahr) !== 1 || (int) $jahr < 1500 || (int) $jahr > (int) date('Y') + 1) {
                $f['jahr'] = 'Bitte ein Jahr wie 1987 angeben.';
            } else {
                $d['jahr'] = (int) $jahr;
            }
        }
        if ($d['isbn'] !== null && !isset($f['isbn'])) {
            $ziffern = (string) preg_replace('/[\s-]/', '', $d['isbn']);
            if (preg_match('/^(\d{9}[\dXx]|\d{13})$/', $ziffern) !== 1) {
                $f['isbn'] = 'Bitte eine ISBN mit 10 oder 13 Ziffern angeben.';
            }
        }
        if ($literaturId === null || $d['kategorie_id'] !== $literaturId) {
            // Nur bei Literatur: sonst nicht speichern (und keine Fehler für versteckte Felder).
            $d['autor_verlag'] = $d['jahr'] = $d['isbn'] = null;
            unset($f['autor_verlag'], $f['jahr'], $f['isbn']);
        }
        if (!$mitLagerort) {
            unset($d['lagerort'], $f['lagerort']);
        }
        return [$d, $f];
    }

    private static function text(mixed $w): ?string
    {
        if (!is_string($w)) {
            return null;
        }
        $w = trim($w);
        return $w === '' ? null : $w;
    }

    private static function id(mixed $w): ?int
    {
        $s = self::text($w);
        if ($s === null || preg_match('/^\d{1,10}$/', $s) !== 1) {
            return null;
        }
        $n = (int) $s;
        return $n >= 1 && $n <= self::MAX_ID ? $n : null;
    }
}
