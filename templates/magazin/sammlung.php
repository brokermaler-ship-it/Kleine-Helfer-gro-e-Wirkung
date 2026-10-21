<?php
/**
 * Eine Sammlung mit ihren Artikeln; Börsenwart: Bearbeiten und alle Artikel umlagern.
 *
 * @var array<string,mixed> $sammlung aus MagazinRepository::sammlung
 * @var list<array<string,mixed>> $verlauf
 * @var bool $istBoersenwart
 * @var string $umlagernWert
 * @var ?string $umlagernFehler
 */
use Emc\Magazin\BuchungValidator;

include __DIR__ . '/_labels.php';
$s = $sammlung;
$summe = array_sum(array_map(fn(array $a): int => (int) $a['bestand'], $s['artikel']));
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div class="min-w-0">
        <h1 class="h3 mb-1 text-break"><?= e((string) $s['name']) ?></h1>
        <div class="text-muted">Sammlung · <?= count($s['artikel']) === 1 ? '1 Artikel' : count($s['artikel']) . ' Artikel' ?> · Bestand <?= $summe ?></div>
    </div>
    <?php if ($istBoersenwart): ?>
        <a class="btn btn-primary" href="<?= e(url('/magazin/sammlungen/' . (int) $s['id'], ['bearbeiten' => '1'])) ?>">Bearbeiten</a>
    <?php endif; ?>
</div>
<?php if ($s['notiz'] !== null && $s['notiz'] !== ''): ?><p class="text-break"><?= nl2br(e((string) $s['notiz'])) ?></p><?php endif; ?>

<?php if ($s['artikel'] === []): ?>
    <?php if ($umlagernFehler !== null): ?><div class="alert alert-danger" role="alert"><?= e($umlagernFehler) ?></div><?php endif; ?>
    <p class="text-muted">Die Sammlung enthält noch keine Artikel.</p>
<?php else: ?>
    <div class="list-group mb-4">
        <?php foreach ($s['artikel'] as $a): ?>
            <a class="list-group-item list-group-item-action d-flex gap-3 align-items-start" href="<?= e(url('/magazin/' . (int) $a['id'])) ?>">
                <?= $magazinTitelbild($a) ?>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="fw-semibold text-break"><span class="fw-bold me-1"><?= e((string) $a['code']) ?></span> <?= e((string) $a['bezeichnung']) ?></div>
                        <div class="text-nowrap" title="Bestand"><?= $magazinBestandBadge((int) $a['bestand']) ?></div>
                    </div>
                    <div class="small text-muted"><?= e((string) $a['kategorie_name']) ?><?= $a['lagerort'] !== null ? ' · ' . e((string) $a['lagerort']) : '' ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($istBoersenwart && $s['artikel'] !== []): ?>
    <section class="card mb-4" aria-labelledby="umlagern-titel">
        <div class="card-body">
            <h2 class="h5" id="umlagern-titel">Ganze Sammlung umlagern</h2>
            <form method="post" action="<?= e(url('/magazin/sammlungen/' . (int) $s['id'] . '/umlagern')) ?>" class="row g-2 align-items-end">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <div class="col-12 col-md-6">
                    <label for="lagerort" class="form-label small mb-0">Neuer Lagerort</label>
                    <input type="text" class="form-control<?= $umlagernFehler !== null ? ' is-invalid' : '' ?>" id="lagerort" name="lagerort" maxlength="<?= BuchungValidator::LAGERORT_MAX ?>" value="<?= e($umlagernWert) ?>" required>
                    <?php if ($umlagernFehler !== null): ?><div class="invalid-feedback d-block"><?= e($umlagernFehler) ?></div><?php endif; ?>
                </div>
                <div class="col-12 col-md-auto"><button type="submit" class="btn btn-outline-primary">Alle umlagern</button></div>
            </form>
        </div>
    </section>
<?php endif; ?>

<?php $verlaufTitel = 'Änderungen'; include dirname(__DIR__) . '/partials/verlauf.php'; ?>
<p class="mt-3"><a href="<?= e(url('/magazin/sammlungen')) ?>">&larr; Alle Sammlungen</a></p>
