<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Core\DomainFehler;

/**
 * Fehler der Kasse mit allen Meldungen eines Abschlussversuchs (z. B. mehrere Stücke schon verkauft) und den betroffenen
 * Angeboten, damit die Kassenseite deren Karten rot markiert. Es wurde nichts gespeichert.
 */
final class KasseFehler extends DomainFehler
{
    /**
     * @param list<string> $meldungen
     * @param list<int> $angebotIds
     */
    public function __construct(public readonly array $meldungen, public readonly array $angebotIds = [])
    {
        parent::__construct(implode(' ', $meldungen));
    }
}
