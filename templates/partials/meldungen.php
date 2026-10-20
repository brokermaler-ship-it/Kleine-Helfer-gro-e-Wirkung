<?php
$klassen = ['erfolg' => 'success', 'warnung' => 'warning', 'fehler' => 'danger'];
foreach (($meldungen ?? []) as $meldung): ?>
    <div class="alert alert-<?= e($klassen[$meldung['typ'] ?? ''] ?? 'info') ?>" role="alert"><?= e($meldung['text'] ?? '') ?><?php if (isset($meldung['link_url'], $meldung['link_text'])): ?> <a class="alert-link" href="<?= e((string) $meldung['link_url']) ?>"><?= e((string) $meldung['link_text']) ?></a><?php endif; ?></div>
<?php endforeach; ?>
