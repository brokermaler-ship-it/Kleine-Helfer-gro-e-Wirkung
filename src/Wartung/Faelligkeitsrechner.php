<?php

declare(strict_types=1);

namespace Emc\Wartung;

/**
 * Reine Berechnung der Fälligkeit (ohne Datenbank): ab der letzten Wartung; fällig ist, was zuerst erreicht wird
 * (Zeit oder Betriebsstunden). Rot = überfällig, Gelb = bald fällig (≤ 1 Monat bzw. ≤ 10 % des Stundenintervalls)
 * oder noch nie erfasst, Grün = in Ordnung.
 */
final class Faelligkeitsrechner
{
    /** Anteil des Stundenintervalls, ab dem eine Wartung „bald fällig“ ist. */
    public const STUNDEN_VORWARNUNG = 0.1;

    public static function berechne(
        ?int $monate,
        ?int $stunden,
        ?\DateTimeImmutable $letzteAm,
        ?float $letzteStunden,
        ?float $aktuelleStunden,
        \DateTimeImmutable $heute,
    ): Faelligkeit {
        if ($letzteAm === null) {
            return new Faelligkeit(Ampel::Gelb, true, null, null, 0.0);
        }
        $heute = $heute->setTime(0, 0);
        $letzteAm = $letzteAm->setTime(0, 0);
        $ampel = Ampel::Gruen;
        $rest = null;
        $faelligAm = null;
        $faelligBei = null;
        $stundenstandFehlt = false;

        if ($monate !== null && $monate > 0) {
            $faelligAm = self::plusMonate($letzteAm, $monate);
            if ($heute > $faelligAm) {
                $zeit = Ampel::Rot;
            } elseif ($heute >= self::plusMonate($faelligAm, -1)) {
                $zeit = Ampel::Gelb;
            } else {
                $zeit = Ampel::Gruen;
            }
            $ampel = self::schlechter($ampel, $zeit);
            $intervallTage = max(1, self::tage($letzteAm, $faelligAm));
            $rest = self::tage($heute, $faelligAm) / $intervallTage;
        }

        if ($stunden !== null && $stunden > 0 && $aktuelleStunden !== null) {
            if ($letzteStunden === null) {
                // Das Fahrzeug führt Betriebsstunden, aber keine Wartung dieser Art hat einen Stundenstand:
                // dieses Kriterium ist wie „noch nie erfasst“ (Gelb, Rest 0.0) – ein Rot aus der Zeit bleibt Rot.
                $stundenstandFehlt = $ampel === Ampel::Gruen;
                $ampel = self::schlechter($ampel, Ampel::Gelb);
                $rest = min($rest ?? PHP_FLOAT_MAX, 0.0);
            } else {
                $faelligBei = round($letzteStunden + $stunden, 1);
                $differenz = round($faelligBei - $aktuelleStunden, 1);
                if ($differenz < 0) {
                    $std = Ampel::Rot;
                } elseif ($differenz <= round(self::STUNDEN_VORWARNUNG * $stunden, 1)) {
                    $std = Ampel::Gelb;
                } else {
                    $std = Ampel::Gruen;
                }
                $ampel = self::schlechter($ampel, $std);
                $rest = min($rest ?? PHP_FLOAT_MAX, $differenz / $stunden);
            }
        } elseif ($stunden !== null && $stunden > 0 && $letzteStunden !== null) {
            // Ohne aktuellen Stand nur zur Anzeige.
            $faelligBei = round($letzteStunden + $stunden, 1);
        }

        if ($rest === null) {
            // Kein Kriterium berechenbar (nur Stundenintervall, Fahrzeug ohne Betriebsstunden), aber es gibt Wartungen:
            // gelb „Stundenstand fehlt“ wie beim fehlenden Stundenbezug – nicht grün und nicht „noch nie erfasst“.
            return new Faelligkeit(Ampel::Gelb, false, $faelligAm, $faelligBei, 0.0, true);
        }
        return new Faelligkeit($ampel, false, $faelligAm, $faelligBei, $rest, $stundenstandFehlt);
    }

    /** Addiert Monate; der Tag wird auf das Monatsende begrenzt (31.08. + 6 Monate → 28.02. bzw. 29.02.). */
    public static function plusMonate(\DateTimeImmutable $d, int $n): \DateTimeImmutable
    {
        $index = (int) $d->format('Y') * 12 + (int) $d->format('n') - 1 + $n;
        $jahr = intdiv($index, 12);
        $monat = $index % 12 + 1;
        $erster = $d->setDate($jahr, $monat, 1);
        $tag = min((int) $d->format('j'), (int) $erster->format('t'));
        return $d->setDate($jahr, $monat, $tag);
    }

    /** Tage von $von bis $bis (negativ, wenn $bis vor $von liegt). */
    private static function tage(\DateTimeImmutable $von, \DateTimeImmutable $bis): int
    {
        return (int) $von->diff($bis)->format('%r%a');
    }

    private static function schlechter(Ampel $a, Ampel $b): Ampel
    {
        return $a->rang() <= $b->rang() ? $a : $b;
    }
}
