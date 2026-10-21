<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Magazin\ArtikelValidator;
use Emc\Reparatur\ReparaturValidator;

/**
 * Prüft die Felder eines Angebots. Ein Mitglied bietet immer als Mitglied mit der eigenen Person an (was das Formular
 * sonst schickt, zählt nicht); der Börsenwart wählt die Herkunft und bei „Mitglied“ die Person.
 */
final class AngebotValidator
{
    public const BEZEICHNUNG_MAX = 200;
    public const BESCHREIBUNG_MAX = 3000;
    public const NOTIZ_MAX = 5000;
    public const MENGE_MAX = 9999;
    public const KEIN_MITGLIED = 'Bitte das Mitglied wählen.';
    public const KEIN_PREIS = 'Bitte den Betrag angeben, z. B. 12,50.';
    public const KEINE_BOERSE = 'Bitte eine offene Börse wählen oder „dauerhaft“.';

    /**
     * @param array<string,mixed> $eingabe Rohdaten aus dem Formular
     * @param list<int> $personIds wählbare Personen (beim Mitglied nur die eigene)
     * @param list<int> $boersenIds nicht abgeschlossene Börsen
     * @param ?list<int> $kategorieIds vorhandene Kategorien (null: nicht prüfen)
     * @param ?list<int> $spurweitenIds vorhandene Spurweiten (null: nicht prüfen)
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    public static function pruefe(array $eingabe, bool $boersenwart, array $personIds, array $boersenIds, ?array $kategorieIds = null, ?array $spurweitenIds = null): array
    {
        $d = [
            'bezeichnung' => self::text($eingabe['bezeichnung'] ?? null),
            'beschreibung' => self::text($eingabe['beschreibung'] ?? null),
            'notiz' => self::text($eingabe['notiz'] ?? null),
            'kategorie_id' => self::id($eingabe['kategorie_id'] ?? null),
            'spurweite_id' => self::id($eingabe['spurweite_id'] ?? null),
            'zustand' => self::text($eingabe['zustand'] ?? null),
        ];
        $f = [];
        if ($d['bezeichnung'] === null) {
            $f['bezeichnung'] = 'Bitte eine Bezeichnung eingeben.';
        } elseif (mb_strlen($d['bezeichnung']) > self::BEZEICHNUNG_MAX) {
            $f['bezeichnung'] = 'Bitte höchstens ' . self::BEZEICHNUNG_MAX . ' Zeichen eingeben.';
        }
        if ($d['beschreibung'] !== null && mb_strlen($d['beschreibung']) > self::BESCHREIBUNG_MAX) {
            $f['beschreibung'] = 'Bitte höchstens ' . self::BESCHREIBUNG_MAX . ' Zeichen eingeben.';
        }
        if ($d['notiz'] !== null && mb_strlen($d['notiz']) > self::NOTIZ_MAX) {
            $f['notiz'] = 'Bitte höchstens ' . self::NOTIZ_MAX . ' Zeichen eingeben.';
        }
        if ($d['kategorie_id'] === null || ($kategorieIds !== null && !in_array($d['kategorie_id'], $kategorieIds, true))) {
            $f['kategorie_id'] = 'Bitte eine Kategorie wählen.';
        }
        if (self::text($eingabe['spurweite_id'] ?? null) !== null
            && ($d['spurweite_id'] === null || ($spurweitenIds !== null && !in_array($d['spurweite_id'], $spurweitenIds, true)))
        ) {
            $f['spurweite_id'] = 'Bitte eine Spurweite aus der Liste wählen.';
        }
        if (!in_array($d['zustand'], ArtikelValidator::ZUSTAENDE, true)) {
            $f['zustand'] = 'Bitte einen Zustand wählen.';
        }

        $preis = ReparaturValidator::betragWert((string) ($eingabe['wunschpreis'] ?? ''));
        $d['wunschpreis'] = $preis;
        if ($preis === null) {
            $f['wunschpreis'] = self::KEIN_PREIS;
        }

        $boerse = trim((string) ($eingabe['boerse_id'] ?? ''));
        $d['boerse_id'] = null;
        if ($boerse !== '' && $boerse !== 'dauerhaft') {
            $id = self::id($boerse);
            if ($id === null || !in_array($id, $boersenIds, true)) {
                $f['boerse_id'] = self::KEINE_BOERSE;
            } else {
                $d['boerse_id'] = $id;
            }
        }

        $menge = trim((string) ($eingabe['menge'] ?? '1'));
        $menge = $menge === '' ? '1' : $menge;
        if (preg_match('/^\d{1,4}$/', $menge) !== 1 || (int) $menge < 1 || (int) $menge > self::MENGE_MAX) {
            $f['menge'] = 'Bitte eine Menge zwischen 1 und ' . self::MENGE_MAX . ' angeben.';
            $d['menge'] = 1;
        } else {
            $d['menge'] = (int) $menge;
        }

        if ($boersenwart) {
            $herkunft = (string) ($eingabe['herkunft'] ?? '');
            if (!in_array($herkunft, ['verein', 'mitglied'], true)) {
                $f['herkunft'] = 'Bitte die Herkunft wählen.';
                $herkunft = 'verein';
            }
            $d['herkunft'] = $herkunft;
            $d['person_id'] = null;
            if ($herkunft === 'mitglied') {
                $person = self::id($eingabe['person_id'] ?? null);
                if ($person === null || !in_array($person, $personIds, true)) {
                    $f['person_id'] = self::KEIN_MITGLIED;
                } else {
                    $d['person_id'] = $person;
                }
            }
        } else {
            // Mitglieder bieten nur eigene Sachen an – Herkunft und Person nie aus dem Formular.
            $d['herkunft'] = 'mitglied';
            $d['person_id'] = $personIds[0] ?? null;
            if ($d['person_id'] === null) {
                $f['person_id'] = self::KEIN_MITGLIED;
            }
        }
        $d['erloes_spende'] = $d['herkunft'] === 'mitglied' && in_array((string) ($eingabe['erloes_spende'] ?? ''), ['1', 'on'], true) ? 1 : 0;
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
        return $n >= 1 && $n <= 4294967295 ? $n : null;
    }
}
