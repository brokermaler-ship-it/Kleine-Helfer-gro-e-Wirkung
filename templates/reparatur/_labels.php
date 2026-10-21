<?php
/**
 * Gemeinsame Anzeigehilfen für Reparaturen: Status- und Prioritäts-Badges, Datum, Beträge.
 */
use Emc\Reparatur\ReparaturStatus;
use Emc\Reparatur\ReparaturValidator;

$reparaturStatusKlassen = [
    'gemeldet' => 'primary', 'geplant' => 'info', 'in_arbeit' => 'warning',
    'wartet_auf_teile' => 'warning', 'erledigt' => 'success', 'verworfen' => 'secondary',
];
$reparaturStatusBadge = function (string $status) use ($reparaturStatusKlassen): string {
    $label = ReparaturStatus::tryFrom($status)?->label() ?? $status;
    return '<span class="badge text-bg-' . e($reparaturStatusKlassen[$status] ?? 'secondary') . '">' . e($label) . '</span>';
};
$prioritaetKlassen = ['dringend' => 'danger', 'normal' => 'light border', 'niedrig' => 'light border text-muted'];
/** Nur „dringend“ fällt auf; mit $immer = true auch normal und niedrig (Detailseite). */
$prioritaetBadge = function (string $prioritaet, bool $immer = false) use ($prioritaetKlassen): string {
    if ($prioritaet !== 'dringend' && !$immer) {
        return '';
    }
    return '<span class="badge text-bg-' . e($prioritaetKlassen[$prioritaet] ?? 'light border') . '">' . e($prioritaet) . '</span>';
};
$reparaturDatum = fn(?string $wert): string => $wert === null || $wert === '' ? '–' : date('d.m.Y', (int) strtotime($wert));
$betrag = fn(?string $wert): string => ReparaturValidator::betrag($wert);
