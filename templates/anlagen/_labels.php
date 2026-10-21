<?php
$anlageStatusLabels = \Emc\Anlagen\AnlageValidator::STATUS_LABELS;
$anlageStatusKlassen = ['in_ordnung' => 'success', 'defekt' => 'danger', 'in_arbeit' => 'warning'];
$anlageStatusBadge = function (array $a) use ($anlageStatusLabels, $anlageStatusKlassen): string {
    $status = (string) $a['status'];
    return '<span class="badge text-bg-' . e($anlageStatusKlassen[$status] ?? 'secondary') . '">' . e($anlageStatusLabels[$status] ?? $status) . '</span>';
};
$anlageTitelbild = fn(array $a): string => $a['titelbild_dokument_id'] === null
    ? '<div class="fahrzeug-bild fahrzeug-bild-leer" aria-hidden="true">&#128740;</div>'
    : '<img class="fahrzeug-bild" alt="" loading="lazy" src="' . e(url('/dateien/' . $a['titelbild_dokument_id'] . '/vorschau')) . '">';
