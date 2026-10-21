<?php
/**
 * Börsen (Veranstaltungen), neueste zuerst.
 *
 * @var list<array<string,mixed>> $boersen
 * @var bool $istBoersenwart
 */
include __DIR__ . '/_angebot_labels.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Börsen</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('/angebote')) ?>">Angebote</a>
        <?php if ($istBoersenwart): ?><a class="btn btn-primary" href="<?= e(url('/boersen/neu')) ?>">+ Börse anlegen</a><?php endif; ?>
    </div>
</div>
<?php if ($boersen === []): ?>
    <p class="text-muted">Noch keine Börse angelegt.</p>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($boersen as $b): ?>
            <a class="list-group-item list-group-item-action" href="<?= e(url('/boersen/' . (int) $b['id'])) ?>">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div class="fw-semibold text-break"><?= e((string) $b['name']) ?></div>
                    <div><?= $boerseStatusBadge((string) $b['status']) ?></div>
                </div>
                <div class="small text-muted"><?= e($datum((string) $b['datum'])) ?><?= $b['ort'] !== null ? ' · ' . e((string) $b['ort']) : '' ?> · Provision <?= e($provision((string) $b['provision_prozent'])) ?> · <?= (int) $b['anzahl_offen'] === 1 ? '1 offenes Angebot' : (int) $b['anzahl_offen'] . ' offene Angebote' ?></div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
