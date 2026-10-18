<?php

declare(strict_types=1);

namespace Emc\Digital;

/** Ergebnis eines CSV-Imports: gültige Zeilen und deutsche Fehlermeldungen. */
final class CsvErgebnis
{
    /**
     * @param list<array{taste:int,beschreibung:string,schaltart:string}> $zeilen
     * @param list<string> $fehler
     */
    public function __construct(
        public readonly array $zeilen,
        public readonly array $fehler,
    ) {
    }
}
