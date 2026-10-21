<?php
/** Gemeinsame Texte der Börsenanfragen (Liste und Anfrage). */
use Emc\Boerse\AnfrageController;

$anfrageStatusBadge = function (string $status): string {
    $klasse = ['offen' => 'text-bg-warning', 'in_bearbeitung' => 'text-bg-info', 'erledigt' => 'text-bg-secondary'][$status] ?? 'text-bg-light';
    return '<span class="badge ' . $klasse . '">' . e(AnfrageController::STATUS_TEXTE[$status] ?? $status) . '</span>';
};
$anfrageArt = function (array $a): string {
    if ($a['art'] === 'sammlung') {
        return 'Sammlung angeboten – möchte ' . ($a['wunsch'] === 'spenden' ? 'spenden' : 'verkaufen');
    }
    return $a['angebot_nummer'] !== null ? 'Kaufanfrage zu ' . $a['angebot_nummer'] : 'Kaufanfrage (Angebot gelöscht)';
};
