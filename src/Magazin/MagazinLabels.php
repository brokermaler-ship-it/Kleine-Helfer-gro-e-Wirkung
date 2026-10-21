<?php

declare(strict_types=1);

namespace Emc\Magazin;

/** Anzeigetexte des Magazins (Oberfläche und Änderungsprotokoll). */
final class MagazinLabels
{
    public const ART = ['eingang' => 'Eingang', 'ausgang' => 'Ausgang', 'umlagerung' => 'Umlagerung'];
    public const HERKUNFT = ['spende' => 'Spende', 'nachlass' => 'Nachlass', 'kauf' => 'Kauf', 'fund' => 'Fund', 'sonstiges' => 'Sonstiges'];
    public const AUSGANG = [
        'gegen_spende' => 'gegen Spende',
        'verkauft_boerse' => 'verkauft (Börse)',
        'verschrottet' => 'verschrottet',
        'ausgeliehen' => 'ausgeliehen',
        'sonstiges' => 'Sonstiges',
    ];
    public const ZUSTAND = ['neu' => 'neu', 'neuwertig' => 'neuwertig', 'gebraucht' => 'gebraucht', 'defekt' => 'defekt'];
}
