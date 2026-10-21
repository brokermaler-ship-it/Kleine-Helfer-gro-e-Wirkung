<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Abrechnung einer Börse aus den nicht stornierten Verkaufspositionen (rein, nur ganze Cent). Die Provision kommt aus
 * der Position (beim Verkauf festgeschrieben) und wird hier nicht neu berechnet. Magazinbuchungen spielen keine Rolle.
 *
 * Kontrolle: Σ Zahlarten = Vereinsstücke + Provisionen + Spenden + Σ Auszahlungen (jeder Cent eines Verkaufs gehört
 * genau einem dieser Töpfe) und – mit $summeVerkaeufe – Σ Zahlarten = Summe der Verkaufsköpfe (boerse_verkauf.summe der
 * nicht stornierten Verkäufe): die echte Gegenprobe zwischen Kopf und Positionen.
 */
final class Abrechnung
{
    public const ZAHLARTEN = ['bar', 'karte_ueberweisung'];

    /**
     * @param list<array{preis:string, provision:string, herkunft:string, person_id:?int, person_name:?string, erloes_spende:bool, zahlart:string, person_sortname?:?string}> $positionen
     * @param ?int $summeVerkaeufe Summe der Verkaufsköpfe in Cent (AbrechnungRepository::summeVerkaeufe), null = ohne Gegenprobe
     * @return array{
     *     verein: array{vereinsstuecke:int, provisionen:int, spenden:int, gesamt:int},
     *     zahlarten: array<string,int>,
     *     mitglieder: list<array{person_id:?int, name:string, stuecke:int, erloes:int, provision:int, auszahlung:int, spende:int}>,
     *     kontrolle: bool
     * }
     */
    public static function berechne(array $positionen, ?int $summeVerkaeufe = null): array
    {
        $verein = ['vereinsstuecke' => 0, 'provisionen' => 0, 'spenden' => 0, 'gesamt' => 0];
        $zahlarten = array_fill_keys(self::ZAHLARTEN, 0);
        $mitglieder = [];
        $sortierung = [];
        foreach ($positionen as $p) {
            $preis = Geld::cent((string) $p['preis']);
            $provision = Geld::cent((string) $p['provision']);
            if (!isset($zahlarten[$p['zahlart']])) {
                throw new \InvalidArgumentException('Unbekannte Zahlart: ' . $p['zahlart']);
            }
            if ($preis < 0 || $provision < 0 || $provision > $preis) {
                throw new \InvalidArgumentException('Preis und Provision müssen ≥ 0 sein, die Provision höchstens der Preis.');
            }
            $zahlarten[$p['zahlart']] += $preis;
            if ($p['herkunft'] === 'verein') {
                $verein['vereinsstuecke'] += $preis;
                continue;
            }
            if ($p['herkunft'] !== 'mitglied') {
                throw new \InvalidArgumentException('Unbekannte Herkunft: ' . $p['herkunft']);
            }
            $person = $p['person_id'] === null ? null : (int) $p['person_id'];
            $schluessel = $person === null ? 'ehemalig' : 'p' . $person;
            $mitglieder[$schluessel] ??= [
                'person_id' => $person,
                'name' => $person === null ? Aenderungsprotokoll::EHEMALIGES_MITGLIED : self::name($p['person_name'] ?? null),
                'stuecke' => 0, 'erloes' => 0, 'provision' => 0, 'auszahlung' => 0, 'spende' => 0,
            ];
            // Sortiert wird nach Nachname, Vorname (Schlüssel aus dem Repository), sonst nach dem angezeigten Namen.
            $sortierung[$schluessel] ??= self::sortierschluessel(trim((string) ($p['person_sortname'] ?? '')) !== '' ? (string) $p['person_sortname'] : $mitglieder[$schluessel]['name']);
            $m = &$mitglieder[$schluessel];
            $m['stuecke']++;
            $m['erloes'] += $preis;
            if ($p['erloes_spende']) {
                $m['spende'] += $preis;
                $verein['spenden'] += $preis;
            } else {
                $m['provision'] += $provision;
                $m['auszahlung'] += $preis - $provision;
                $verein['provisionen'] += $provision;
            }
            unset($m);
        }
        $verein['gesamt'] = $verein['vereinsstuecke'] + $verein['provisionen'] + $verein['spenden'];
        $schluessel = array_keys($mitglieder);
        usort($schluessel, fn(string $a, string $b): int => [$mitglieder[$a]['person_id'] === null, $sortierung[$a], $mitglieder[$a]['person_id']]
            <=> [$mitglieder[$b]['person_id'] === null, $sortierung[$b], $mitglieder[$b]['person_id']]);
        $liste = array_map(fn(string $k): array => $mitglieder[$k], $schluessel);
        $auszahlungen = array_sum(array_column($liste, 'auszahlung'));
        $kontrolle = array_sum($zahlarten) === $verein['gesamt'] + $auszahlungen
            && ($summeVerkaeufe === null || array_sum($zahlarten) === $summeVerkaeufe);
        return [
            'verein' => $verein,
            'zahlarten' => $zahlarten,
            'mitglieder' => $liste,
            'kontrolle' => $kontrolle,
        ];
    }

    private static function name(?string $name): string
    {
        $name = trim((string) $name);
        return $name === '' ? Aenderungsprotokoll::EHEMALIGES_MITGLIED : $name;
    }

    /** Deutsche Sortierung ohne intl (DIN 5007-2): ä → ae, ö → oe, ü → ue, ß → ss, ohne Groß/klein. */
    private static function sortierschluessel(string $name): string
    {
        return strtr(mb_strtolower($name), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }
}
