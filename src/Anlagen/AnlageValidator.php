<?php

declare(strict_types=1);

namespace Emc\Anlagen;

final class AnlageValidator
{
    public const STATUS = ['in_ordnung', 'defekt', 'in_arbeit'];
    public const STATUS_LABELS = ['in_ordnung' => 'in Ordnung', 'defekt' => 'defekt', 'in_arbeit' => 'in Arbeit'];

    /** Feld => Höchstlänge in Zeichen. */
    private const TEXTFELDER = ['name' => 150, 'kurzbeschreibung' => 1000, 'standort' => 150];
    private const MAX_ID = 4294967295;

    /**
     * Normalisiert und prüft die Formulareingabe.
     *
     * @param array<string,mixed> $eingabe Rohdaten aus dem Formular
     * @param list<int> $spurweitenIds vorhandene Spurweiten
     * @return array{0: array{name:?string,spurweite_id:?int,kurzbeschreibung:?string,standort:?string,status:string}, 1: array<string,string>}
     */
    public static function pruefe(array $eingabe, array $spurweitenIds): array
    {
        $d = [];
        foreach (array_keys(self::TEXTFELDER) as $feld) {
            $d[$feld] = self::text($eingabe[$feld] ?? null);
        }
        $d = [
            'name' => $d['name'],
            'spurweite_id' => self::id($eingabe['spurweite_id'] ?? null),
            'kurzbeschreibung' => $d['kurzbeschreibung'],
            'standort' => $d['standort'],
            'status' => self::text($eingabe['status'] ?? null) ?? 'in_ordnung',
        ];

        $f = [];
        if ($d['name'] === null) {
            $f['name'] = 'Bitte einen Namen eingeben.';
        }
        foreach (self::TEXTFELDER as $feld => $max) {
            if ($d[$feld] !== null && mb_strlen($d[$feld]) > $max) {
                $f[$feld] = 'Bitte höchstens ' . $max . ' Zeichen eingeben.';
            }
        }
        if ($d['spurweite_id'] === null || !in_array($d['spurweite_id'], $spurweitenIds, true)) {
            $f['spurweite_id'] = 'Bitte eine Spurweite wählen.';
        }
        if (!in_array($d['status'], self::STATUS, true)) {
            $f['status'] = 'Bitte einen Status wählen.';
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
