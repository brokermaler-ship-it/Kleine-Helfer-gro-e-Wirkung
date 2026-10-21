<?php

declare(strict_types=1);

namespace Emc\Digital\Pdf;

use Smalot\PdfParser\RawData\FilterHelper;

/**
 * Zählt alles, was die Bibliothek entpackt (Inhalts-, Querverweis- und Objektströme, jeder Filter einer Kette),
 * an der einzigen Stelle, an der sie entpackt. Über dem Budget wird abgebrochen.
 */
final class ZaehlenderFilterHelper extends FilterHelper
{
    public int $summe = 0;
    public bool $ueber = false;

    public function __construct(private int $budget)
    {
    }

    public function decodeFilter(string $filter, string $data, int $decodeMemoryLimit = 0): string
    {
        if ($this->ueber) {
            throw new GrenzeUeberschritten('Entpack-Budget überschritten');
        }
        $ergebnis = parent::decodeFilter($filter, $data, $decodeMemoryLimit);
        $this->summe += strlen($ergebnis);
        if ($this->summe > $this->budget) {
            $this->ueber = true;
            throw new GrenzeUeberschritten('Entpack-Budget überschritten');
        }
        return $ergebnis;
    }
}
