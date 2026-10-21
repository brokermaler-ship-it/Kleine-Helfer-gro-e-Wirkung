<?php

declare(strict_types=1);

namespace Emc\Wartung;

final class WartungsartValidator
{
    public const NAME_MAX = 100;
    private const INTERVALL_MAX = 999;
    private const SORTIERUNG_MAX = 999999;

    /**
     * Normalisiert und prüft die Formulareingabe.
     *
     * @param array<string,mixed> $eingabe Rohdaten aus dem Formular (name, gilt_fuer, intervall_monate, intervall_stunden, aktiv, sortierung)
     * @return array{0: array{name:?string,gilt_fuer:?string,intervall_monate:?int,intervall_stunden:?int,aktiv:int,sortierung:int}, 1: array<string,string>}
     */
    public static function pruefe(array $eingabe): array
    {
        $f = [];
        $name = self::text($eingabe['name'] ?? null);
        if ($name === null) {
            $f['name'] = 'Bitte einen Namen eingeben.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $f['name'] = 'Bitte höchstens ' . self::NAME_MAX . ' Zeichen eingeben.';
        }

        $giltFuer = GiltFuer::tryFrom(self::text($eingabe['gilt_fuer'] ?? null) ?? '');
        if ($giltFuer === null) {
            $f['gilt_fuer'] = 'Bitte wählen, wofür die Wartungsart gilt.';
        }

        $intervalle = [];
        foreach (['intervall_monate', 'intervall_stunden'] as $feld) {
            $roh = self::text($eingabe[$feld] ?? null);
            $intervalle[$feld] = null;
            if ($roh === null) {
                continue;
            }
            if (preg_match('/^\d{1,3}$/', $roh) !== 1 || (int) $roh < 1 || (int) $roh > self::INTERVALL_MAX) {
                $f[$feld] = 'Bitte eine ganze Zahl von 1 bis ' . self::INTERVALL_MAX . ' angeben.';
                continue;
            }
            $intervalle[$feld] = (int) $roh;
        }
        if (!isset($f['intervall_stunden']) && $giltFuer === GiltFuer::Anlage && $intervalle['intervall_stunden'] !== null) {
            $f['intervall_stunden'] = 'Anlagen haben keine Betriebsstunden – bitte ein Intervall in Monaten angeben.';
        } elseif (!isset($f['intervall_monate']) && !isset($f['intervall_stunden'])
            && $intervalle['intervall_monate'] === null && $intervalle['intervall_stunden'] === null
        ) {
            $f['intervall_monate'] = 'Bitte ein Intervall in Monaten oder Betriebsstunden angeben.';
        }

        $sortierung = 0;
        $roh = self::text($eingabe['sortierung'] ?? null);
        if ($roh !== null) {
            if (preg_match('/^-?\d{1,6}$/', $roh) !== 1 || abs((int) $roh) > self::SORTIERUNG_MAX) {
                $f['sortierung'] = 'Bitte eine ganze Zahl angeben.';
            } else {
                $sortierung = (int) $roh;
            }
        }

        $aktiv = $eingabe['aktiv'] ?? null;
        return [[
            'name' => $name,
            'gilt_fuer' => $giltFuer?->value,
            'intervall_monate' => $intervalle['intervall_monate'],
            'intervall_stunden' => $intervalle['intervall_stunden'],
            'aktiv' => $aktiv === '1' || $aktiv === 1 || $aktiv === true ? 1 : 0,
            'sortierung' => $sortierung,
        ], $f];
    }

    /** Anzeige eines Intervalls: „6 Monate oder 20 h“, „12 Monate“, „1 Monat“, „50 h“ (ohne beides „–“). */
    public static function intervallText(?int $monate, ?int $stunden): string
    {
        $teile = [];
        if ($monate !== null) {
            $teile[] = $monate . ($monate === 1 ? ' Monat' : ' Monate');
        }
        if ($stunden !== null) {
            $teile[] = $stunden . ' h';
        }
        return $teile === [] ? '–' : implode(' oder ', $teile);
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
