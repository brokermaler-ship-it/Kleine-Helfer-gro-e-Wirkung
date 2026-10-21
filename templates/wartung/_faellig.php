<?php
/**
 * „fällig am / bei“: Datum und/oder Stundenstand; „–“, wenn nichts zu berechnen ist.
 *
 * @var \Emc\Wartung\Faelligkeit $faelligkeit
 */
$faelligTeile = [];
if ($faelligkeit->faelligAm !== null) {
    $faelligTeile[] = e($faelligkeit->faelligAm->format('d.m.Y'));
}
if ($faelligkeit->faelligBeiStunden !== null) {
    $faelligTeile[] = 'bei ' . e(number_format($faelligkeit->faelligBeiStunden, 1, ',', '.')) . ' h';
}
echo $faelligTeile === [] ? '–' : implode(' oder ', $faelligTeile);
