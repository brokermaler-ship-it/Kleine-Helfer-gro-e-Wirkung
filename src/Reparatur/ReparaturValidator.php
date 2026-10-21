<?php

declare(strict_types=1);

namespace Emc\Reparatur;

/** Prüft das Formular „Reparatur bearbeiten“ samt Ersatzteilen; Beträge bleiben exakt (Strings, keine Gleitkommazahlen). */
final class ReparaturValidator
{
    public const TEXT_MAX = 5000;
    public const MAX_TEILE = 50;
    public const BEZEICHNUNG_MAX = 150;
    public const ARTIKELNUMMER_MAX = 60;
    public const BEZUGSQUELLE_MAX = 150;
    public const OHNE_WAS_GEMACHT = 'Bitte kurz beschreiben, was gemacht wurde.';
    public const OHNE_BEZEICHNUNG = 'Bitte eine Bezeichnung für das Ersatzteil angeben.';
    public const KEIN_BETRAG = 'Bitte die Kosten als Betrag angeben, z. B. 12,50.';
    public const PERSON_WAEHLEN = 'Bitte eine Person aus der Liste wählen.';

    /** Bis 999999,99 (DECIMAL(8,2)), ab 0, höchstens zwei Nachkommastellen, Komma oder Punkt, kein Tausendertrennzeichen. */
    private const BETRAG = '/^(\d{1,6})(?:[.,](\d{1,2}))?$/';

    /**
     * @param array<string,mixed> $eingabe beschreibung, wo_bauteil, prioritaet, status, zustaendig, termin, notizen,
     *        was_gemacht, teile (Liste von Zeilen mit bezeichnung, artikelnummer, bezugsquelle, kosten, bestellt)
     * @param list<int> $personIds wählbare Personen für „zuständig“
     * @param string $objektTyp fahrzeug oder anlage (Wo/Bauteil nur bei Anlagen)
     * @return array{0: array{beschreibung:string, wo_bauteil:?string, prioritaet:string, zustaendig_person_id:?int, termin:?string, status:string, notizen:?string, was_gemacht:?string},
     *               1: array<string,string>,
     *               2: list<array{bezeichnung:string, artikelnummer:?string, bezugsquelle:?string, kosten:?string, bestellt:bool}>}
     *         Fehlerschlüssel der Ersatzteile: teile.<n>.<feld>, n = Position der gesendeten Zeile (ab 0)
     */
    public static function pruefe(array $eingabe, array $personIds, string $objektTyp): array
    {
        $f = [];

        $beschreibung = self::text($eingabe['beschreibung'] ?? null);
        if ($beschreibung === '') {
            $f['beschreibung'] = MeldungValidator::OHNE_BESCHREIBUNG;
        } elseif (mb_strlen($beschreibung) > MeldungValidator::BESCHREIBUNG_MAX) {
            $f['beschreibung'] = self::zuLang(MeldungValidator::BESCHREIBUNG_MAX);
        }

        $wo = null;
        if ($objektTyp === 'anlage') {
            $wo = self::text($eingabe['wo_bauteil'] ?? null);
            if (mb_strlen($wo) > MeldungValidator::WO_BAUTEIL_MAX) {
                $f['wo_bauteil'] = self::zuLang(MeldungValidator::WO_BAUTEIL_MAX);
            }
        }

        $prioritaet = self::text($eingabe['prioritaet'] ?? null);
        if (!isset(ReparaturRepository::PRIORITAETEN[$prioritaet])) {
            $f['prioritaet'] = 'Bitte eine Priorität aus der Liste wählen.';
        }

        $status = ReparaturStatus::tryFrom(self::text($eingabe['status'] ?? null));
        if ($status === null) {
            $f['status'] = 'Bitte einen Status aus der Liste wählen.';
        }

        $zustaendig = null;
        $roh = self::text($eingabe['zustaendig'] ?? null);
        if ($roh !== '') {
            if (preg_match('/^\d{1,10}$/', $roh) === 1 && in_array((int) $roh, $personIds, true)) {
                $zustaendig = (int) $roh;
            } else {
                $f['zustaendig'] = self::PERSON_WAEHLEN;
            }
        }

        $termin = null;
        $roh = self::text($eingabe['termin'] ?? null);
        if ($roh !== '') {
            $termin = self::datum($roh);
            if ($termin === null) {
                $f['termin'] = 'Bitte ein gültiges Datum angeben.';
            }
        }

        $notizen = self::text($eingabe['notizen'] ?? null);
        if (mb_strlen($notizen) > self::TEXT_MAX) {
            $f['notizen'] = self::zuLang(self::TEXT_MAX);
        }
        $wasGemacht = self::text($eingabe['was_gemacht'] ?? null);
        if (mb_strlen($wasGemacht) > self::TEXT_MAX) {
            $f['was_gemacht'] = self::zuLang(self::TEXT_MAX);
        } elseif ($status === ReparaturStatus::Erledigt && $wasGemacht === '') {
            $f['was_gemacht'] = self::OHNE_WAS_GEMACHT;
        }

        $teile = self::teile($eingabe['teile'] ?? [], $f);

        return [[
            'beschreibung' => $beschreibung,
            'wo_bauteil' => self::oderNull($wo ?? ''),
            'prioritaet' => $prioritaet,
            'zustaendig_person_id' => $zustaendig,
            'termin' => $termin,
            'status' => $status?->value ?? '',
            'notizen' => self::oderNull($notizen),
            'was_gemacht' => self::oderNull($wasGemacht),
        ], $f, $teile];
    }

    /**
     * Zeilen ohne jeden Text entfallen (auch wenn nur „bestellt“ angehakt ist).
     *
     * @param array<string,string> $f
     * @return list<array{bezeichnung:string, artikelnummer:?string, bezugsquelle:?string, kosten:?string, bestellt:bool}>
     */
    private static function teile(mixed $roh, array &$f): array
    {
        $zeilen = is_array($roh) ? array_values($roh) : [];
        $teile = [];
        foreach ($zeilen as $n => $z) {
            $z = is_array($z) ? $z : [];
            $t = [
                'bezeichnung' => self::text($z['bezeichnung'] ?? null),
                'artikelnummer' => self::text($z['artikelnummer'] ?? null),
                'bezugsquelle' => self::text($z['bezugsquelle'] ?? null),
                'kosten' => self::text($z['kosten'] ?? null),
            ];
            if (implode('', $t) === '') {
                continue;
            }
            if ($t['bezeichnung'] === '') {
                $f["teile.$n.bezeichnung"] = self::OHNE_BEZEICHNUNG;
            } elseif (mb_strlen($t['bezeichnung']) > self::BEZEICHNUNG_MAX) {
                $f["teile.$n.bezeichnung"] = self::zuLang(self::BEZEICHNUNG_MAX);
            }
            if (mb_strlen($t['artikelnummer']) > self::ARTIKELNUMMER_MAX) {
                $f["teile.$n.artikelnummer"] = self::zuLang(self::ARTIKELNUMMER_MAX);
            }
            if (mb_strlen($t['bezugsquelle']) > self::BEZUGSQUELLE_MAX) {
                $f["teile.$n.bezugsquelle"] = self::zuLang(self::BEZUGSQUELLE_MAX);
            }
            $kosten = null;
            if ($t['kosten'] !== '') {
                $kosten = self::betragWert($t['kosten']);
                if ($kosten === null) {
                    $f["teile.$n.kosten"] = self::KEIN_BETRAG;
                }
            }
            $teile[] = [
                'bezeichnung' => $t['bezeichnung'],
                'artikelnummer' => self::oderNull($t['artikelnummer']),
                'bezugsquelle' => self::oderNull($t['bezugsquelle']),
                'kosten' => $kosten,
                'bestellt' => ($z['bestellt'] ?? null) === '1',
            ];
        }
        if (count($teile) > self::MAX_TEILE) {
            $f['teile'] = 'Bitte höchstens ' . self::MAX_TEILE . ' Ersatzteile angeben.';
        }
        return $teile;
    }

    /** „12,5“ → „12.50“, „1234“ → „1234.00“; null, wenn es kein gültiger Betrag ist. */
    public static function betragWert(string $eingabe): ?string
    {
        if (preg_match(self::BETRAG, trim($eingabe), $m) !== 1) {
            return null;
        }
        $euro = ltrim($m[1], '0');
        return ($euro === '' ? '0' : $euro) . '.' . str_pad($m[2] ?? '', 2, '0');
    }

    /**
     * Anzeige eines DECIMAL-Betrags aus der Datenbank: „1234.5“ → „1.234,50 €“ – nur mit Zeichenketten, ohne Gleitkomma.
     * null → ''.
     */
    public static function betrag(?string $wert): string
    {
        if ($wert === null || $wert === '') {
            return '';
        }
        [$euro, $cent] = self::teileBetrag($wert);
        $euro = strrev(implode('.', str_split(strrev($euro), 3)));
        return $euro . ',' . $cent . ' €';
    }

    /** Betrag fürs Eingabefeld: „12.50“ → „12,50“; null → ''. */
    public static function betragEingabe(?string $wert): string
    {
        if ($wert === null || $wert === '') {
            return '';
        }
        [$euro, $cent] = self::teileBetrag($wert);
        return $euro . ',' . $cent;
    }

    /** @return array{0:string, 1:string} Euro ohne führende Nullen und zwei Stellen Cent */
    private static function teileBetrag(string $wert): array
    {
        $negativ = str_starts_with($wert, '-');
        [$euro, $cent] = array_pad(explode('.', ltrim($wert, '-'), 2), 2, '');
        $euro = ltrim($euro, '0');
        return [($negativ ? '-' : '') . ($euro === '' ? '0' : $euro), substr(str_pad($cent, 2, '0'), 0, 2)];
    }

    /** Y-m-d (Datumsfeld) oder d.m.Y; null bei ungültigem Datum. */
    private static function datum(string $w): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $w, $m) === 1) {
            [$j, $mo, $t] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $w, $m) === 1) {
            [$t, $mo, $j] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if ($j < 1900 || $j > 2999 || !checkdate($mo, $t, $j)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $j, $mo, $t);
    }

    private static function zuLang(int $max): string
    {
        return 'Bitte höchstens ' . $max . ' Zeichen eingeben.';
    }

    private static function oderNull(string $w): ?string
    {
        return $w === '' ? null : $w;
    }

    /** Text ohne Leerraum am Rand, Zeilenumbrüche als \n; kein Text (z. B. ein Array) und ungültiges UTF-8 gelten als leer. */
    private static function text(mixed $wert): string
    {
        // CRLF aus Textfeldern wie im Browser (maxlength) als ein Zeichen zählen.
        return is_string($wert) && mb_check_encoding($wert, 'UTF-8') ? trim(str_replace("\r\n", "\n", $wert)) : '';
    }
}
