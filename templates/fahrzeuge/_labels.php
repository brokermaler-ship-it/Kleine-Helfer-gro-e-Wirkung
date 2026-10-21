<?php
$gattungen = ['lok' => 'Lok', 'triebwagen' => 'Triebwagen', 'wagen' => 'Wagen', 'sonderfahrzeug' => 'Sonderfahrzeug'];
$stromsysteme = ['2L' => '2-Leiter', '3L' => '3-Leiter'];
$statusLabels = ['einsatzbereit' => 'einsatzbereit', 'in_wartung' => 'in Wartung', 'defekt' => 'defekt', 'ausgeliehen' => 'ausgeliehen', 'ausgemustert' => 'ausgemustert'];
$statusKlassen = ['einsatzbereit' => 'success', 'in_wartung' => 'warning', 'defekt' => 'danger', 'ausgeliehen' => 'info', 'ausgemustert' => 'secondary'];
$epochen = ['I', 'II', 'III', 'IV', 'V', 'VI'];
$eigentumBadge = function (array $fz): string {
    if ($fz['eigentuemer_person_id'] === null) {
        return '<span class="badge text-bg-dark">Verein</span>';
    }
    return '<span class="badge text-bg-warning text-wrap text-start">extern – Eigentum von ' . e((string) $fz['eigentuemer_name']) . '</span>';
};
$statusBadge = function (array $fz) use ($statusLabels, $statusKlassen): string {
    $status = (string) $fz['status'];
    $text = $status === 'ausgemustert' ? 'Abstellgleis' : ($statusLabels[$status] ?? $status);
    return '<span class="badge text-bg-' . e($statusKlassen[$status] ?? 'secondary') . '">' . e($text) . '</span>';
};
$titelbild = fn(array $fz): string => $fz['titelbild_dokument_id'] === null
    ? '<div class="fahrzeug-bild fahrzeug-bild-leer" aria-hidden="true">&#128642;</div>'
    : '<img class="fahrzeug-bild" alt="" loading="lazy" src="' . e(url('/dateien/' . $fz['titelbild_dokument_id'] . '/vorschau')) . '">';
$adressen = fn(array $fz): string => $fz['adressen'] === null ? '–' : e((string) $fz['adressen']);
