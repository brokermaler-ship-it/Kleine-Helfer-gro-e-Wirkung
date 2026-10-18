<?php
/** @var array<string,mixed> $fahrzeug */
include __DIR__ . '/_labels.php';
$fz = $fahrzeug;
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1"><?= e($fz['bezeichnung']) ?></h1>
        <div>
            <span class="text-muted me-2"><?= e($fz['inventarnummer']) ?></span>
            <span class="badge text-bg-<?= e($statusKlassen[$fz['status']] ?? 'secondary') ?>"><?= e($statusLabels[$fz['status']] ?? $fz['status']) ?></span>
            <?= $eigentumBadge($fz) ?>
        </div>
    </div>
    <?php if ($darfFz($fz)): ?>
        <div class="d-flex gap-2">
            <a class="btn btn-primary" href="<?= e(url('/fahrzeuge/' . $fz['id'] . '/bearbeiten')) ?>">Bearbeiten</a>
            <?php if ($darf('fahrzeug_bearbeiten')): ?>
            <form method="post" action="<?= e(url('/fahrzeuge/' . $fz['id'] . '/loeschen')) ?>"
                  onsubmit="return confirm('Fahrzeug endgültig löschen? Besser: Status ‚ausgemustert‘.');">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <button type="submit" class="btn btn-outline-danger">Löschen</button>
            </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<ul class="nav nav-tabs" id="fahrzeug-tabs" role="tablist">
    <?php foreach (['uebersicht' => 'Übersicht', 'digital' => 'Digital', 'dateien' => 'Dateien', 'verlauf' => 'Verlauf'] as $schluessel => $beschriftung): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $schluessel === 'uebersicht' ? ' active' : '' ?>" id="tab-<?= e($schluessel) ?>" type="button" role="tab"
                    data-bs-toggle="tab" data-bs-target="#pane-<?= e($schluessel) ?>" aria-controls="pane-<?= e($schluessel) ?>"
                    aria-selected="<?= $schluessel === 'uebersicht' ? 'true' : 'false' ?>"><?= e($beschriftung) ?></button>
        </li>
    <?php endforeach; ?>
</ul>
<div class="tab-content pt-3">
    <div class="tab-pane fade show active" id="pane-uebersicht" role="tabpanel" aria-labelledby="tab-uebersicht" tabindex="0">
        <?php include __DIR__ . '/tab_uebersicht.php'; ?>
    </div>
    <div class="tab-pane fade" id="pane-digital" role="tabpanel" aria-labelledby="tab-digital" tabindex="0">
        <?php include __DIR__ . '/tab_digital.php'; ?>
    </div>
    <div class="tab-pane fade" id="pane-dateien" role="tabpanel" aria-labelledby="tab-dateien" tabindex="0">
        <?php include __DIR__ . '/tab_dateien.php'; ?>
    </div>
    <div class="tab-pane fade" id="pane-verlauf" role="tabpanel" aria-labelledby="tab-verlauf" tabindex="0">
        <?php include __DIR__ . '/tab_verlauf.php'; ?>
    </div>
</div>
<p class="mt-3"><a href="<?= e(url('/fahrzeuge')) ?>">&larr; Zurück zur Liste</a></p>
