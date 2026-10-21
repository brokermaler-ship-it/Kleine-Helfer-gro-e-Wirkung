<?php
/** @var array<string,mixed> $fahrzeug */
include __DIR__ . '/_labels.php';
$fz = $fahrzeug;
$abgestellt = $fz['status'] === 'ausgemustert';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1"><?= e($fz['bezeichnung']) ?></h1>
        <div class="d-flex flex-wrap align-items-center gap-1">
            <?php $fzCode = \Emc\Codes\Code::fahrzeug((string) $fz['inventarnummer']); ?>
            <?php if ($fzCode !== null): ?><span class="fahrzeug-code me-2" title="Code für das Feld „Code“ und die Etiketten"><?= e($fzCode) ?></span><?php endif; ?>
            <span class="text-muted me-2"><?= e($fz['inventarnummer']) ?></span>
            <?= $statusBadge($fz) ?>
            <?= $eigentumBadge($fz) ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-danger" href="<?= e(url('/fahrzeuge/' . $fz['id'] . '/defekt')) ?>">Defekt melden</a>
        <?php if ($fzCode !== null && $darf(\Emc\Auth\Berechtigung::ETIKETTEN_DRUCKEN)): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('/etiketten', ['art' => 'klebchen', 'auswahl' => 'einzeln', 'ids' => [(int) $fz['id']]])) ?>">Etikett drucken</a>
        <?php endif; ?>
        <?php if ($darfFz($fz)): ?>
            <a class="btn btn-primary" href="<?= e(url('/fahrzeuge/' . $fz['id'] . '/bearbeiten')) ?>">Bearbeiten</a>
            <?php if ($abgestellt && ($magazin['artikel'] ?? null) !== null): ?>
            <?php /* ans Magazin übergeben: bleibt auf dem Abstellgleis */ ?>
            <?php elseif ($abgestellt): ?>
            <form method="post" action="<?= e(url('/fahrzeuge/' . $fz['id'] . '/zurueckholen')) ?>">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <button type="submit" class="btn btn-outline-success">Zurück in Betrieb</button>
            </form>
            <?php else: ?>
            <form method="post" action="<?= e(url('/fahrzeuge/' . $fz['id'] . '/abstellen')) ?>">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <button type="submit" class="btn btn-outline-secondary">Aufs Abstellgleis</button>
            </form>
            <?php endif; ?>
            <?php if ($darf('fahrzeug_bearbeiten')): ?>
            <form method="post" action="<?= e(url('/fahrzeuge/' . $fz['id'] . '/loeschen')) ?>"
                  onsubmit="return confirm('Fahrzeug endgültig löschen? Besser: aufs Abstellgleis stellen.');">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <button type="submit" class="btn btn-outline-danger">Löschen</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($abgestellt): ?>
    <div class="alert alert-secondary" role="status">
        Auf dem Abstellgleis<?= ($fz['ausgemustert_am'] ?? null) === null ? '' : ' seit ' . e(date('d.m.Y', (int) strtotime((string) $fz['ausgemustert_am']))) ?>.
        <a href="<?= e(url('/abstellgleis')) ?>">Zum Abstellgleis</a>
    </div>
<?php endif; ?>
<?php if (($magazin ?? null) !== null) { include __DIR__ . '/_magazin.php'; } ?>

<ul class="nav nav-tabs" id="fahrzeug-tabs" role="tablist">
    <?php
    $tabs = ['uebersicht' => 'Übersicht', 'digital' => 'Digital', 'dateien' => 'Dateien'];
    if (($wartung ?? null) !== null) {
        $tabs['wartung'] = 'Wartung'; // fehlt nur, solange das Datenbank-Update aussteht
    }
    if (($reparaturen ?? null) !== null) {
        $tabs['reparaturen'] = 'Reparaturen'; // ebenso (Migration 014)
    }
    $tabs['verlauf'] = 'Verlauf';
    foreach ($tabs as $schluessel => $beschriftung): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $schluessel === 'uebersicht' ? ' active' : '' ?>" id="tab-<?= e($schluessel) ?>" type="button" role="tab"
                    data-bs-toggle="tab" data-bs-target="#pane-<?= e($schluessel) ?>" aria-controls="pane-<?= e($schluessel) ?>"
                    aria-selected="<?= $schluessel === 'uebersicht' ? 'true' : 'false' ?>"><?= e($beschriftung) ?><?php if ($schluessel === 'reparaturen' && $reparaturen['offene'] > 0): ?> <span class="badge rounded-pill text-bg-danger" title="offene Reparaturen"><?= (int) $reparaturen['offene'] ?></span><?php endif; ?></button>
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
    <?php if (($wartung ?? null) !== null): ?>
    <div class="tab-pane fade" id="pane-wartung" role="tabpanel" aria-labelledby="tab-wartung" tabindex="0">
        <?php $block = $wartung; include dirname(__DIR__) . '/wartung/_block.php'; ?>
    </div>
    <?php endif; ?>
    <?php if (($reparaturen ?? null) !== null): ?>
    <div class="tab-pane fade" id="pane-reparaturen" role="tabpanel" aria-labelledby="tab-reparaturen" tabindex="0">
        <?php $block = $reparaturen; include dirname(__DIR__) . '/reparatur/_block.php'; ?>
    </div>
    <?php endif; ?>
    <div class="tab-pane fade" id="pane-verlauf" role="tabpanel" aria-labelledby="tab-verlauf" tabindex="0">
        <?php include __DIR__ . '/tab_verlauf.php'; ?>
    </div>
</div>
<p class="mt-3"><a href="<?= e(url('/fahrzeuge')) ?>">&larr; Zurück zur Liste</a></p>
