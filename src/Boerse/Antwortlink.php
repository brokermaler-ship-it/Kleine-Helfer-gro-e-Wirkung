<?php

declare(strict_types=1);

namespace Emc\Boerse;

/**
 * Antwort-Links zu einer Börsenanfrage (Eingaben aus dem öffentlichen Bereich, also nicht vertrauenswürdig): ein
 * mailto:-Link nur für eine schlichte, gültige E-Mail-Adresse (EINFACHE_ADRESSE und FILTER_VALIDATE_EMAIL); Adresse,
 * Betreff und Text mit rawurlencode, Steuerzeichen aus Adresse und Betreff entfernt (keine zusätzlichen Kopfzeilen wie
 * cc/bcc); ein tel:-Link nur aus Ziffern und führendem „+“.
 * Die Rückgabe wird im Template trotzdem mit e() ausgegeben.
 */
final class Antwortlink
{
    /** Nur schlichte Adressen bekommen einen Link (keine ?, &, %, ' usw. – sonst nur als Text). */
    public const EINFACHE_ADRESSE = '/^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/D';

    public static function betreff(array $anfrage): string
    {
        if (($anfrage['art'] ?? null) === 'sammlung') {
            return 'Deine Sammlung – Anfrage an die Modellbahnbörse';
        }
        $nummer = $anfrage['angebot_nummer'] ?? null;
        return 'Deine Anfrage an die Modellbahnbörse' . (is_string($nummer) && $nummer !== '' ? ' (' . $nummer . ')' : '');
    }

    /** @return ?string null, wenn die Adresse fehlt oder ungültig ist */
    public static function mailto(?string $email, string $betreff, ?string $text = null): ?string
    {
        $email = trim(self::einzeilig((string) $email));
        if ($email === '' || strlen($email) > 190 || preg_match(self::EINFACHE_ADRESSE, $email) !== 1
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        $at = (int) strrpos($email, '@');
        $link = 'mailto:' . rawurlencode(substr($email, 0, $at)) . '@' . rawurlencode(substr($email, $at + 1))
            . '?subject=' . rawurlencode(trim(self::einzeilig($betreff)));
        if ($text !== null && $text !== '') {
            $link .= '&body=' . rawurlencode(str_replace(["\r\n", "\r", "\n"], "\r\n", $text));
        }
        return $link;
    }

    /** Anrede für den Text der Antwort (Name ohne Zeilenumbrüche). */
    public static function anrede(string $name): string
    {
        $name = trim(self::einzeilig($name));
        return ($name === '' ? 'Hallo' : 'Hallo ' . $name) . ",\n\n";
    }

    /** @return ?string null, wenn keine wählbare Nummer übrig bleibt */
    public static function tel(?string $telefon): ?string
    {
        $t = trim((string) $telefon);
        if (str_starts_with($t, '+')) {
            // „+49 (0) 621 …“: die (0) wird bei internationaler Wahl nicht gewählt.
            $t = (string) preg_replace('/\(\s*0\s*\)/', '', $t);
        }
        $nummer = (string) preg_replace('/[^0-9]/', '', $t);
        if (strlen($nummer) < 3 || strlen($nummer) > 20) {
            return null;
        }
        return 'tel:' . (str_starts_with($t, '+') ? '+' : '') . $nummer;
    }

    private static function einzeilig(string $s): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s);
    }
}
