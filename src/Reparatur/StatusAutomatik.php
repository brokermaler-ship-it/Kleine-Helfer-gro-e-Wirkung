<?php

declare(strict_types=1);

namespace Emc\Reparatur;

/**
 * Automatischer Fahrzeug- bzw. Anlagenstatus aus den offenen Reparaturen (Spezifikation Stufe 2, 6.3) – ohne Datenbank.
 *
 * - „ausgeliehen“ und „ausgemustert“ (nur Fahrzeuge) werden nie angefasst.
 * - Soll: eine offene, dringende Reparatur → „defekt“; sonst eine Reparatur „in Arbeit“ → „in Wartung“ (Anlage
 *   „in_arbeit“). „defekt“ hat Vorrang.
 * - Steht das Objekt schon auf dem Soll, bleibt alles, wie es ist (auch ein von Hand gesetztes „defekt“ bleibt von Hand).
 * - Ohne Soll wird nur ein automatisch gesetzter Status zurückgesetzt („einsatzbereit“ bzw. „in_ordnung“): die letzte
 *   offene Reparatur ist erledigt oder verworfen, oder die dringende wurde herabgestuft.
 */
final class StatusAutomatik
{
    private const GESCHUETZT = ['fahrzeug' => ['ausgeliehen', 'ausgemustert'], 'anlage' => []];
    private const IN_ARBEIT = ['fahrzeug' => 'in_wartung', 'anlage' => 'in_arbeit'];
    private const GRUNDSTATUS = ['fahrzeug' => 'einsatzbereit', 'anlage' => 'in_ordnung'];

    /**
     * @param string $objektTyp fahrzeug oder anlage
     * @param string $aktuell aktueller Status des Objekts
     * @param bool $automatisch aktueller Status wurde von einer Reparatur gesetzt
     * @param list<array{prioritaet:string, status:string}> $offene offene Reparaturen des Objekts
     * @return ?array{status:string, automatisch:bool} null = nichts ändern
     */
    public static function ziel(string $objektTyp, string $aktuell, bool $automatisch, array $offene): ?array
    {
        if (!isset(self::GRUNDSTATUS[$objektTyp]) || in_array($aktuell, self::GESCHUETZT[$objektTyp], true)) {
            return null;
        }
        $soll = null;
        foreach ($offene as $r) {
            if (($r['prioritaet'] ?? '') === 'dringend') {
                $soll = 'defekt';
                break;
            }
            if (($r['status'] ?? '') === ReparaturStatus::InArbeit->value) {
                $soll = self::IN_ARBEIT[$objektTyp];
            }
        }
        if ($soll !== null) {
            return $aktuell === $soll ? null : ['status' => $soll, 'automatisch' => true];
        }
        return $automatisch ? ['status' => self::GRUNDSTATUS[$objektTyp], 'automatisch' => false] : null;
    }
}
