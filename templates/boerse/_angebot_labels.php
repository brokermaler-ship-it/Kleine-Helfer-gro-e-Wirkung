<?php
/** Anzeigehilfen der Börse (Status, Freigabe, Preis, Titelbild). */
use Emc\Boerse\BoerseLabels;
use Emc\Boerse\BoerseValidator;
use Emc\Magazin\MagazinLabels;
use Emc\Reparatur\ReparaturValidator;

$angebotStatus = BoerseLabels::STATUS;
$angebotOeffentlich = BoerseLabels::OEFFENTLICH;
$boerseStatusText = BoerseLabels::BOERSE_STATUS;
$angebotHerkunft = BoerseLabels::HERKUNFT;
$angebotZustand = MagazinLabels::ZUSTAND;
$angebotStatusBadge = function (string $s): string {
    $klasse = ['angeboten' => 'text-bg-primary', 'reserviert' => 'text-bg-warning', 'verkauft' => 'text-bg-success', 'zurueck' => 'text-bg-secondary'][$s] ?? 'text-bg-secondary';
    return '<span class="badge ' . $klasse . '">' . e(BoerseLabels::STATUS[$s] ?? $s) . '</span>';
};
$angebotOeffentlichBadge = function (string $o): string {
    $klasse = ['nein' => 'text-bg-light border', 'vorgeschlagen' => 'text-bg-info', 'freigegeben' => 'text-bg-success'][$o] ?? 'text-bg-light border';
    return '<span class="badge fw-normal ' . $klasse . '">' . e(BoerseLabels::OEFFENTLICH[$o] ?? $o) . '</span>';
};
$boerseStatusBadge = function (string $s): string {
    $klasse = ['in_vorbereitung' => 'text-bg-secondary', 'laeuft' => 'text-bg-success', 'abgeschlossen' => 'text-bg-dark'][$s] ?? 'text-bg-secondary';
    return '<span class="badge ' . $klasse . '">' . e(BoerseLabels::BOERSE_STATUS[$s] ?? $s) . '</span>';
};
$preis = fn(?string $w): string => ReparaturValidator::betrag($w);
$provision = fn(string $w): string => BoerseValidator::provisionAnzeige($w) . ' %';
$datum = fn(?string $d): string => $d === null || $d === '' ? '' : date('d.m.Y', (int) strtotime($d));
$angebotTitelbild = fn(array $a): string => ($a['titelbild_dokument_id'] ?? null) === null
    ? '<div class="fahrzeug-bild fahrzeug-bild-leer" aria-hidden="true">&#127991;</div>'
    : '<img class="fahrzeug-bild" alt="" loading="lazy" src="' . e(url('/dateien/' . $a['titelbild_dokument_id'] . '/vorschau')) . '">';
/** Börse eines Angebots als Text: Name bzw. „dauerhaft“. */
$angebotBoerse = fn(array $a): string => $a['boerse_id'] === null ? 'dauerhaft' : (string) $a['boerse_name'];
