<?php

declare(strict_types=1);

namespace Emc\Boerse;

/**
 * Schalter des öffentlichen Bereichs /boerse (ohne Anmeldung). Aktiv nur mit `boerse_oeffentlich` genau true und einer
 * Datenschutzerklärung unter https://… (`datenschutz_url`); sonst antwortet jeder öffentliche Pfad mit 404.
 * Achtung Präfix: /boersen… ist die interne Börse und bleibt hinter der Anmeldung.
 */
final class OeffentlicherBereich
{
    /** @param array<string,mixed> $config */
    public static function aktiv(array $config): bool
    {
        return ($config['boerse_oeffentlich'] ?? false) === true && self::datenschutzUrl($config) !== null;
    }

    /**
     * Adresse der Datenschutzerklärung, nur https:// mit Host und ohne Leerzeichen, Anführungszeichen oder spitze
     * Klammern; sonst null (dann bleibt der Bereich aus).
     *
     * @param array<string,mixed> $config
     */
    public static function datenschutzUrl(array $config): ?string
    {
        $url = $config['datenschutz_url'] ?? null;
        if (!is_string($url)) {
            return null;
        }
        $url = trim($url);
        if (preg_match('~^https://[^\s/?#"\'<>\\\\]+(?:[/?#][^\s"\'<>\\\\]*)?$~', $url) !== 1) {
            return null;
        }
        return $url;
    }

    public static function istPfad(string $pfad): bool
    {
        return $pfad === '/boerse' || str_starts_with($pfad, '/boerse/');
    }
}
