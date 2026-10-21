<?php
/**
 * Kasse: Auswahl der laufenden Börse (bei genau einer leitet /kasse direkt weiter).
 *
 * @var list<array<string,mixed>> $boersen laufende Börsen
 */
include __DIR__ . '/_angebot_labels.php';
?>
<div class="kasse">
    <h1 class="h3">Kasse</h1>
    <?php if ($boersen === []): ?>
        <div class="alert alert-info">Gerade läuft keine Börse.</div>
        <p>Eine Börse in Vorbereitung startest du auf ihrer Seite mit „Börse starten“. <a href="<?= e(url('/boersen')) ?>">Zu den Börsen</a></p>
    <?php else: ?>
        <p class="text-muted">Mehrere Börsen laufen. Für welche kassierst du?</p>
        <div class="d-grid gap-2">
            <?php foreach ($boersen as $b): ?>
                <a class="btn btn-outline-primary btn-lg kasse-knopf text-start" href="<?= e(url('/boersen/' . (int) $b['id'] . '/kasse')) ?>">
                    <span class="fw-semibold"><?= e((string) $b['name']) ?></span>
                    <span class="d-block small"><?= e($datum((string) $b['datum'])) ?><?= $b['ort'] !== null ? ' · ' . e((string) $b['ort']) : '' ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
