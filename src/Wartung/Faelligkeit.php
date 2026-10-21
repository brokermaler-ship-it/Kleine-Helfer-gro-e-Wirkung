<?php

declare(strict_types=1);

namespace Emc\Wartung;

/** Ergebnis von Faelligkeitsrechner::berechne. */
final class Faelligkeit
{
    /**
     * @param ?\DateTimeImmutable $faelligAm fällig an diesem Tag (nur mit Intervall in Monaten und letzter Wartung)
     * @param ?float $faelligBeiStunden fällig bei diesem Betriebsstundenstand (nur mit Stundenintervall und Stundenstand der letzten Wartung)
     * @param float $rest kleinster verbleibender Anteil des Intervalls über alle Kriterien (negativ = überfällig; nie erfasst: 0.0)
     */
    public function __construct(
        public readonly Ampel $ampel,
        public readonly bool $nochNieErfasst,
        public readonly ?\DateTimeImmutable $faelligAm,
        public readonly ?float $faelligBeiStunden,
        public readonly float $rest,
    ) {
    }

    /** Anzeigetext: „überfällig“, „bald fällig“, „in Ordnung“ oder „noch nie erfasst“. */
    public function text(): string
    {
        return $this->nochNieErfasst ? 'noch nie erfasst' : $this->ampel->text();
    }
}
