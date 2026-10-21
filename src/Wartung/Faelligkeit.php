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
     * @param bool $stundenstandFehlt Gelb kommt allein daher, dass das Fahrzeug Betriebsstunden führt, aber keine Wartung
     *        dieser Art einen Stundenstand hat (das Stundenkriterium gilt dann wie „noch nie erfasst“: Gelb, Rest 0.0)
     */
    public function __construct(
        public readonly Ampel $ampel,
        public readonly bool $nochNieErfasst,
        public readonly ?\DateTimeImmutable $faelligAm,
        public readonly ?float $faelligBeiStunden,
        public readonly float $rest,
        public readonly bool $stundenstandFehlt = false,
    ) {
    }

    /** Gruppe für Zählung und Filter: rot, gelb (bald fällig), nie (noch nie erfasst) oder gruen. */
    public function kategorie(): string
    {
        return $this->nochNieErfasst ? 'nie' : $this->ampel->value;
    }

    /**
     * Anzeigetext: „überfällig“, „bald fällig“, „in Ordnung“, „noch nie erfasst“ oder „Stundenstand fehlt“
     * (Gelb allein wegen des fehlenden Stundenstands).
     */
    public function text(): string
    {
        if ($this->nochNieErfasst) {
            return 'noch nie erfasst';
        }
        if ($this->stundenstandFehlt && $this->ampel === Ampel::Gelb) {
            return 'Stundenstand fehlt';
        }
        return $this->ampel->text();
    }
}
