<?php

declare(strict_types=1);

namespace Emc\Wartung;

final class WartungValidator
{
    public const NOTIZ_MAX = 2000;
    /** Größter Wert einer DECIMAL(8,1)-Spalte. */
    public const MAX_STUNDEN = 9999999.9;

    /**
     * Normalisiert und prüft die Eingabe „Wartung eintragen“.
     *
     * @param array<string,mixed> $eingabe datum, arten (Liste), betriebsstunden, ausgefuehrt_von, notiz
     * @param list<int> $erlaubteArtIds aktive, zum Objekt passende, nicht abgeschaltete Wartungsarten
     * @param list<int> $personIds wählbare Personen (aktive)
     * @param bool $mitStunden nur Fahrzeuge haben Betriebsstunden; sonst wird die Eingabe ignoriert
     * @param string $heute Y-m-d
     * @return array{0: array{datum:?string,arten:list<int>,betriebsstunden:?float,ausgefuehrt_von_person_id:?int,notiz:?string}, 1: array<string,string>}
     */
    public static function pruefe(array $eingabe, array $erlaubteArtIds, array $personIds, bool $mitStunden, string $heute): array
    {
        $f = [];

        $datum = self::datum(self::text($eingabe['datum'] ?? null));
        if ($datum === null) {
            $f['datum'] = 'Bitte ein gültiges Datum angeben.';
        } elseif ($datum > $heute) {
            $f['datum'] = 'Das Datum darf nicht in der Zukunft liegen.';
        }

        $arten = [];
        $roh = $eingabe['arten'] ?? [];
        foreach (is_array($roh) ? $roh : [] as $a) {
            $a = is_string($a) || is_int($a) ? trim((string) $a) : '';
            if ($a === '') {
                continue;
            }
            if (preg_match('/^\d{1,10}$/', $a) !== 1 || !in_array((int) $a, $erlaubteArtIds, true)) {
                $f['arten'] = 'Diese Wartungsart passt nicht zu diesem Objekt.';
                continue;
            }
            if (!in_array((int) $a, $arten, true)) {
                $arten[] = (int) $a;
            }
        }
        if (!isset($f['arten']) && $arten === []) {
            $f['arten'] = 'Bitte mindestens eine Wartungsart wählen.';
        }

        $stunden = null;
        $rohStunden = $mitStunden ? self::text($eingabe['betriebsstunden'] ?? null) : null;
        if ($rohStunden !== null) {
            if (preg_match('/^\d{1,7}(?:[.,]\d)?$/', $rohStunden) !== 1 || (float) str_replace(',', '.', $rohStunden) > self::MAX_STUNDEN) {
                $f['betriebsstunden'] = 'Bitte einen Stundenstand ab 0 angeben.';
            } else {
                $stunden = (float) str_replace(',', '.', $rohStunden);
            }
        }

        $person = self::text($eingabe['ausgefuehrt_von'] ?? null);
        $personId = $person !== null && preg_match('/^\d{1,10}$/', $person) === 1 && in_array((int) $person, $personIds, true) ? (int) $person : null;
        if ($personId === null) {
            $f['ausgefuehrt_von'] = 'Bitte auswählen, wer die Wartung ausgeführt hat.';
        }

        $notiz = self::text($eingabe['notiz'] ?? null);
        if ($notiz !== null && mb_strlen($notiz) > self::NOTIZ_MAX) {
            $f['notiz'] = 'Bitte höchstens ' . self::NOTIZ_MAX . ' Zeichen eingeben.';
        }

        return [[
            'datum' => $datum,
            'arten' => $arten,
            'betriebsstunden' => $stunden,
            'ausgefuehrt_von_person_id' => $personId,
            'notiz' => $notiz,
        ], $f];
    }

    /** Y-m-d (Datumsfeld) oder d.m.Y; null bei ungültigem Datum. */
    private static function datum(?string $w): ?string
    {
        if ($w === null) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $w, $m) === 1) {
            [$j, $mo, $t] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $w, $m) === 1) {
            [$t, $mo, $j] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if ($j < 1900 || !checkdate($mo, $t, $j)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $j, $mo, $t);
    }

    private static function text(mixed $w): ?string
    {
        if (!is_string($w)) {
            return null;
        }
        $w = trim($w);
        return $w === '' ? null : $w;
    }
}
