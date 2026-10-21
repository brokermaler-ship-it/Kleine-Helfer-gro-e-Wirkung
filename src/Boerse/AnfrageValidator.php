<?php

declare(strict_types=1);

namespace Emc\Boerse;

/** Prüft die öffentlichen Formulare „Kaufanfrage“ und „Sammlung anbieten“ (Anrede „du“). */
final class AnfrageValidator
{
    public const MAX_FOTOS = 5;
    public const ARTEN = ['kaufanfrage', 'sammlung'];
    public const WUENSCHE = ['verkaufen', 'spenden'];

    /**
     * @param array<string,mixed> $eingabe Rohdaten aus dem Formular
     * @param string $art kaufanfrage oder sammlung
     * @param int $fotoAnzahl Anzahl ausgewählter Dateien (nur bei der Sammlung)
     * @return array{0: array{name:string, email:?string, telefon:?string, nachricht:string, wunsch:?string}, 1: array<string,string>}
     */
    public static function pruefe(array $eingabe, string $art, int $fotoAnzahl = 0): array
    {
        $name = self::text($eingabe['name'] ?? null);
        $email = self::text($eingabe['email'] ?? null);
        $telefon = self::text($eingabe['telefon'] ?? null);
        $nachricht = self::text($eingabe['nachricht'] ?? null, true);
        $wunsch = $art === 'sammlung' ? self::text($eingabe['wunsch'] ?? null) : '';
        $f = [];
        if ($name === '') {
            $f['name'] = 'Bitte gib deinen Namen an.';
        } elseif (mb_strlen($name) > 100) {
            $f['name'] = 'Bitte höchstens 100 Zeichen eingeben.';
        }
        if ($email !== '' && (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $f['email'] = 'Bitte gib eine gültige E-Mail-Adresse an.';
        }
        if ($telefon !== '' && preg_match('~^[0-9 +()/-]{5,40}$~', $telefon) !== 1) {
            $f['telefon'] = 'Bitte gib eine gültige Telefonnummer an.';
        }
        if ($email === '' && $telefon === '') {
            $f['kontakt'] = 'Bitte gib eine E-Mail-Adresse oder eine Telefonnummer an.';
        }
        if ($nachricht === '') {
            $f['nachricht'] = $art === 'sammlung' ? 'Bitte beschreib die Sammlung.' : 'Bitte schreib uns kurz, worum es geht.';
        } elseif (mb_strlen($nachricht) > 3000) {
            $f['nachricht'] = 'Bitte höchstens 3000 Zeichen eingeben.';
        }
        if ($art === 'sammlung' && !in_array($wunsch, self::WUENSCHE, true)) {
            $f['wunsch'] = 'Bitte wähle, ob du verkaufen oder spenden möchtest.';
        }
        if (($eingabe['einwilligung'] ?? null) !== '1') {
            $f['einwilligung'] = 'Bitte stimme der Verarbeitung deiner Angaben zu.';
        }
        if ($fotoAnzahl > self::MAX_FOTOS) {
            $f['fotos'] = 'Bitte höchstens ' . self::MAX_FOTOS . ' Fotos auswählen.';
        }
        return [[
            'name' => $name,
            'email' => $email === '' ? null : $email,
            'telefon' => $telefon === '' ? null : $telefon,
            'nachricht' => $nachricht,
            'wunsch' => $art === 'sammlung' && in_array($wunsch, self::WUENSCHE, true) ? $wunsch : null,
        ], $f];
    }

    /** Getrimmter Text ohne Steuerzeichen (Zeilenumbrüche nur in mehrzeiligen Feldern); Nicht-Text wird leer. */
    private static function text(mixed $wert, bool $mehrzeilig = false): string
    {
        if (!is_string($wert)) {
            return '';
        }
        $wert = str_replace(["\r\n", "\r"], "\n", $wert);
        $wert = (string) preg_replace($mehrzeilig ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $mehrzeilig ? '' : ' ', $wert);
        return trim($wert);
    }
}
