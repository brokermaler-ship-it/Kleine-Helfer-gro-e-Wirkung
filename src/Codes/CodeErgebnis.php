<?php

declare(strict_types=1);

namespace Emc\Codes;

/** Ergebnis der Auflösung einer Eingabe im Feld „Code“. */
final class CodeErgebnis
{
    public const DIREKT = 'direkt';
    public const AUSWAHL = 'auswahl';
    public const SUCHE = 'suche';
    public const LEER = 'leer';

    /**
     * @param string $art direkt, auswahl, suche oder leer
     * @param ?string $ziel Pfad ohne Basis-URL (nur bei direkt), z. B. /fahrzeuge/5
     * @param list<array<string,mixed>> $fahrzeuge
     * @param list<array<string,mixed>> $anlagen
     */
    public function __construct(
        public readonly string $art,
        public readonly ?string $ziel = null,
        public readonly array $fahrzeuge = [],
        public readonly array $anlagen = [],
        public readonly ?string $meldung = null,
    ) {
    }
}
