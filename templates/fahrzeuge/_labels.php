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
    return '<span class="badge text-bg-warning">extern – Eigentum von ' . e((string) $fz['eigentuemer_name']) . '</span>';
};
