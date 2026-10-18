<?php

declare(strict_types=1);

namespace Emc\Digital;

use Emc\Core\Request;

final class AdressValidator
{
    /** @var array<string,array{0:int,1:int}> */
    private const BEREICHE = [
        'dcc' => [1, 10239],
        'motorola' => [1, 255],
        'selectrix' => [0, 111],
        'mfx' => [1, 16383],
    ];

    /** Fehlermeldung oder null, wenn die Adresse zulässig ist. */
    public static function pruefe(Protokoll $p, ?int $adresse): ?string
    {
        if ($adresse === null) {
            return $p === Protokoll::Mfx ? null : 'Bitte eine Adresse eingeben.';
        }
        [$min, $max] = self::BEREICHE[$p->value];
        if ($adresse < $min || $adresse > $max) {
            return sprintf('Für %s sind Adressen von %d bis %d möglich.', $p->label(), $min, $max);
        }
        return null;
    }

    /** 'kurz' oder 'lang' (nur DCC), sonst null. */
    public static function adressart(Protokoll $p, int $adresse): ?string
    {
        if ($p !== Protokoll::Dcc) {
            return null;
        }
        return $adresse <= 127 ? 'kurz' : 'lang';
    }

    /**
     * Liest die Adresse aus Formular oder URL (Feld `$feld`) und prüft sie.
     * Leer = keine Adresse; Unlesbares oder Unsinniges wird mit der Bereichsmeldung abgelehnt.
     *
     * @return array{0:?int,1:?string} Adresse und Fehlermeldung
     */
    public static function lies(Request $r, Protokoll $p, string $feld = 'adresse'): array
    {
        if (trim($r->text($feld)) === '') {
            return [null, self::pruefe($p, null)];
        }
        $adresse = $r->ganzzahl($feld, 0, 2147483647);
        if ($adresse === null) {
            return [null, self::pruefe($p, -1)];
        }
        return [$adresse, self::pruefe($p, $adresse)];
    }
}
