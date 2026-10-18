<?php
$klassen = ['erfolg' => 'success', 'warnung' => 'warning', 'fehler' => 'danger'];
foreach (($meldungen ?? []) as $meldung): ?>
    <div class="alert alert-<?= e($klassen[$meldung['typ'] ?? ''] ?? 'info') ?>" role="alert"><?= e($meldung['text'] ?? '') ?></div>
<?php endforeach; ?>
