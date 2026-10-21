<?php

declare(strict_types=1);

namespace Emc\Digital;

/** Ergebnis eines CSV- oder PDF-Imports: gültige Zeilen, deutsche Fehlermeldungen und Hinweise. */
final class CsvErgebnis
{
    /**
     * @param list<array{taste:int,beschreibung:string,schaltart:string}> $zeilen
     * @param list<string> $fehler
     * @param list<string> $hinweise nicht blockierende Hinweise (nur der PDF-Import füllt sie)
     */
    public function __construct(
        public readonly array $zeilen,
        public readonly array $fehler,
        public readonly array $hinweise = [],
    ) {
    }
}
