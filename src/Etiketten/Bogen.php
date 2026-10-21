<?php

declare(strict_types=1);

namespace Emc\Etiketten;

/**
 * Maße eines Etikettenbogens (A4, alle Angaben in mm). Die Druckseite setzt jedes Feld absolut aus diesen Maßen;
 * bei einem anderen Bogen nur hier anpassen und zuerst einen Probedruck auf Normalpapier machen.
 */
final readonly class Bogen
{
    public function __construct(
        public float $breite,
        public float $hoehe,
        public int $spalten,
        public int $zeilen,
        public float $randOben,
        public float $randLinks,
        public float $abstandX,
        public float $abstandY,
    ) {
    }

    /**
     * Fahrzeug-Klebchen (auch Verpackung): 25,4 × 10 mm, 7 × 27 = 189 je Bogen (Avery Zweckform L4731).
     * Bei anderem Bogen nur hier anpassen, Probedruck auf Normalpapier.
     */
    public static function klebchen(): self
    {
        return new self(25.4, 10.0, 7, 27, 13.5, 8.48, 2.54, 0.0);
    }

    /**
     * Anlagen-Etikett mit QR-Code: 70 × 37 mm, 3 × 8 = 24 je Bogen, randlos (oben 0,5 mm) – Avery Zweckform 3474.
     * Nicht verwechseln mit 3475 (70 × 36 mm, ebenfalls 24 je Bogen): dafür new self(70.0, 36.0, 3, 8, 4.5, 0.0, 0.0, 0.0)
     * und zuerst einen Probedruck auf Normalpapier.
     */
    public static function anlage(): self
    {
        return new self(70.0, 37.0, 3, 8, 0.5, 0.0, 0.0, 0.0);
    }

    public function proBogen(): int
    {
        return $this->spalten * $this->zeilen;
    }

    /**
     * Linke obere Ecke eines Feldes (0-basiert, zeilenweise von links oben).
     *
     * @return array{links:float, oben:float}
     */
    public function position(int $feld): array
    {
        $zeile = intdiv($feld, $this->spalten);
        $spalte = $feld % $this->spalten;
        return [
            'links' => round($this->randLinks + $spalte * ($this->breite + $this->abstandX), 3),
            'oben' => round($this->randOben + $zeile * ($this->hoehe + $this->abstandY), 3),
        ];
    }

    /**
     * Verteilt die Etiketten auf Bögen; auf dem ersten Bogen bleiben die Felder vor $start leer (null), damit
     * angefangene Bögen weiterverwendet werden können. $start außerhalb 1 … proBogen() gilt als 1. Nur der letzte
     * Bogen kann kürzer als proBogen() sein.
     *
     * @template T
     * @param list<T> $etiketten
     * @return list<list<?T>>
     */
    public function verteile(array $etiketten, int $start): array
    {
        if ($etiketten === []) {
            return [];
        }
        if ($start < 1 || $start > $this->proBogen()) {
            $start = 1;
        }
        $felder = array_merge(array_fill(0, $start - 1, null), array_values($etiketten));
        return array_chunk($felder, $this->proBogen());
    }
}
