<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

final class FahrzeugValidator
{
    public const GATTUNGEN = ['lok', 'triebwagen', 'wagen', 'sonderfahrzeug'];
    public const STROMSYSTEME = ['2L', '3L'];
    public const STATUS = ['einsatzbereit', 'in_wartung', 'defekt', 'ausgeliehen', 'ausgemustert'];
    public const EPOCHEN = ['I', 'II', 'III', 'IV', 'V', 'VI'];

    private const TEXTFELDER = ['bezeichnung' => 150, 'baureihe' => 100, 'bahnverwaltung' => 100, 'hersteller' => 100, 'artikelnummer' => 100, 'notiz' => 65000];
    private const MAX_STUNDEN = 9999999.9;
    private const MAX_ID = 4294967295;

    /**
     * @param array<string,mixed> $d Rohdaten aus dem Formular
     * @return array<string,mixed>
     */
    public static function normalisiere(array $d): array
    {
        $aus = [];
        foreach (array_keys(self::TEXTFELDER) as $feld) {
            $aus[$feld] = self::text($d[$feld] ?? null);
        }
        $aus['epoche'] = self::text($d['epoche'] ?? null);
        $aus['gattung'] = self::text($d['gattung'] ?? null) ?? 'lok';
        $aus['stromsystem'] = self::text($d['stromsystem'] ?? null) ?? '2L';
        $aus['status'] = self::text($d['status'] ?? null) ?? 'einsatzbereit';
        $aus['spurweite_id'] = self::id($d['spurweite_id'] ?? null);
        $aus['eigentuemer_person_id'] = self::id($d['eigentuemer_person_id'] ?? null);

        $stunden = self::text($d['betriebsstunden'] ?? null);
        if ($stunden === null) {
            $aus['betriebsstunden'] = 0.0;
        } else {
            $zahl = str_replace(',', '.', $stunden);
            $aus['betriebsstunden'] = is_numeric($zahl) ? (float) $zahl : $stunden;
        }
        return $aus;
    }

    /**
     * @param array<string,mixed> $d normalisierte Daten
     * @return array<string,string> Feld => Fehlermeldung
     */
    public static function pruefe(array $d): array
    {
        $f = [];
        $bez = $d['bezeichnung'] ?? null;
        if (!is_string($bez) || $bez === '') {
            $f['bezeichnung'] = 'Bitte eine Bezeichnung eingeben.';
        }
        foreach (self::TEXTFELDER as $feld => $max) {
            if (isset($d[$feld]) && is_string($d[$feld]) && mb_strlen($d[$feld]) > $max) {
                $f[$feld] = 'Bitte höchstens ' . $max . ' Zeichen eingeben.';
            }
        }
        if (!isset($d['spurweite_id']) || !is_int($d['spurweite_id'])) {
            $f['spurweite_id'] = 'Bitte eine Spurweite wählen.';
        }
        if (!in_array($d['gattung'] ?? null, self::GATTUNGEN, true)) {
            $f['gattung'] = 'Bitte eine Gattung wählen.';
        }
        if (!in_array($d['stromsystem'] ?? null, self::STROMSYSTEME, true)) {
            $f['stromsystem'] = 'Bitte 2-Leiter oder 3-Leiter wählen.';
        }
        if (!in_array($d['status'] ?? null, self::STATUS, true)) {
            $f['status'] = 'Bitte einen Status wählen.';
        }
        $epoche = $d['epoche'] ?? null;
        if ($epoche !== null && $epoche !== '' && !in_array($epoche, self::EPOCHEN, true)) {
            $f['epoche'] = 'Bitte eine Epoche von I bis VI wählen.';
        }
        $h = $d['betriebsstunden'] ?? null;
        if ((!is_int($h) && !is_float($h)) || $h < 0 || $h > self::MAX_STUNDEN) {
            $f['betriebsstunden'] = 'Bitte eine Zahl von 0 bis 9999999,9 eingeben (z. B. 12,5).';
        }
        return $f;
    }

    private static function text(mixed $w): ?string
    {
        if (!is_string($w)) {
            return null;
        }
        $w = trim($w);
        return $w === '' ? null : $w;
    }

    /** Positive ganze Zahl oder null (leer/ungültig). */
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
