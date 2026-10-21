<?php

declare(strict_types=1);

namespace Emc\Reparatur;

/** Prüft das Formular „Defekt melden“ (alle Mitglieder, am Handy). */
final class MeldungValidator
{
    public const BESCHREIBUNG_MAX = 2000;
    public const WO_BAUTEIL_MAX = 150;
    public const OHNE_BESCHREIBUNG = 'Bitte beschreibe den Defekt.';

    /** Einstufung beim Melden → Priorität der Reparatur. */
    public const EINSTUFUNGEN = ['dringend' => 'fährt nicht mehr', 'normal' => 'fährt, aber …'];

    /**
     * @param array<string,mixed> $eingabe beschreibung, wo_bauteil (nur Anlagen), einstufung (dringend | normal)
     * @param string $objektTyp fahrzeug oder anlage
     * @return array{0: array{beschreibung:string, wo_bauteil:?string, prioritaet:string}, 1: array<string,string>}
     */
    public static function pruefe(array $eingabe, string $objektTyp): array
    {
        $f = [];
        $beschreibung = self::text($eingabe['beschreibung'] ?? null);
        if ($beschreibung === '') {
            $f['beschreibung'] = self::OHNE_BESCHREIBUNG;
        } elseif (mb_strlen($beschreibung) > self::BESCHREIBUNG_MAX) {
            $f['beschreibung'] = 'Bitte höchstens ' . self::BESCHREIBUNG_MAX . ' Zeichen eingeben.';
        }

        $wo = null;
        if ($objektTyp === 'anlage') {
            $wo = self::text($eingabe['wo_bauteil'] ?? null);
            if (mb_strlen($wo) > self::WO_BAUTEIL_MAX) {
                $f['wo_bauteil'] = 'Bitte höchstens ' . self::WO_BAUTEIL_MAX . ' Zeichen eingeben.';
            }
            $wo = $wo === '' ? null : $wo;
        }

        $einstufung = $eingabe['einstufung'] ?? null;
        $prioritaet = $einstufung === 'dringend' ? 'dringend' : 'normal';

        return [['beschreibung' => $beschreibung, 'wo_bauteil' => $wo, 'prioritaet' => $prioritaet], $f];
    }

    /** Text ohne Leerraum am Rand; kein Text (z. B. ein Array) gilt als leer. */
    private static function text(mixed $wert): string
    {
        if (!is_string($wert)) {
            return '';
        }
        // Ungültiges UTF-8 verwerfen, damit mb_strlen und die Datenbank nicht stolpern.
        return mb_check_encoding($wert, 'UTF-8') ? trim($wert) : '';
    }
}
