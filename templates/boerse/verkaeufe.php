<?php
/**
 * Verkäufe einer Börse, neueste zuerst; Storno mit Pflichtgrund nur am Tag des Verkaufs (laufende Börse).
 *
 * @var array<string,mixed> $boerse
 * @var list<array<string,mixed>> $verkaeufe aus VerkaufRepository::fuerBoerse, mit stornierbar
 * @var string $heute Y-m-d
 */
use Emc\Boerse\Geld;
use Emc\Boerse\KasseDienst;

include __DIR__ . '/_angebot_labels.php';
$id = (int) $boerse['id'];
$gueltig = array_values(array_filter($verkaeufe, fn(array $v): bool => $v['storniert_am'] === null));
$gesamt = 0;
foreach ($gueltig as $v) {
    $gesamt += Geld::cent((string) $v['summe']);
}
?>
<div class="kasse">
    <div class="d-flex justify-content-between align-items-baseline gap-2 mb-1">
        <h1 class="h4 mb-0 text-break">Verkäufe · <?= e((string) $boerse['name']) ?></h1>
        <?php if ($boerse['status'] === 'laeuft'): ?><a class="text-nowrap" href="<?= e(url('/boersen/' . $id . '/kasse')) ?>">Zur Kasse</a><?php endif; ?>
    </div>
    <p class="text-muted"><?= count($gueltig) ?> <?= count($gueltig) === 1 ? 'Verkauf' : 'Verkäufe' ?> über <?= e($preis(Geld::dezimal($gesamt))) ?> (ohne stornierte)</p>

    <?php if ($verkaeufe === []): ?>
        <p class="text-muted">Noch keine Verkäufe.</p>
    <?php endif; ?>
    <?php foreach ($verkaeufe as $v): ?>
        <?php $vid = (int) $v['id']; $storniert = $v['storniert_am'] !== null; $tag = substr((string) $v['verkauft_am'], 0, 10); ?>
        <div class="card mb-2<?= $storniert ? ' bg-body-tertiary' : '' ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between gap-2">
                    <div class="min-w-0">
                        <div class="fw-semibold"><?= e(date($tag === $heute ? 'H:i' : 'd.m.Y H:i', (int) strtotime((string) $v['verkauft_am']))) ?> · <?= e(KasseDienst::ZAHLARTEN[(string) $v['zahlart']] ?? (string) $v['zahlart']) ?></div>
                        <div class="text-break small"><?= e(implode(', ', $v['nummern'])) ?></div>
                        <?php if ($v['kassiert_von'] !== null): ?><div class="small text-muted">kassiert von <?= e((string) $v['kassiert_von']) ?></div><?php endif; ?>
                    </div>
                    <div class="fs-5 fw-bold text-nowrap<?= $storniert ? ' text-decoration-line-through text-muted' : '' ?>"><?= e($preis((string) $v['summe'])) ?></div>
                </div>
                <?php if ($storniert): ?>
                    <div class="mt-2"><span class="small text-break fw-semibold">storniert: <?= e((string) $v['storno_grund']) ?></span></div>
                <?php elseif ($v['stornierbar']): ?>
                    <details class="mt-2">
                        <summary class="text-danger">Stornieren</summary>
                        <form method="post" action="<?= e(url('/verkaeufe/' . $vid . '/storno')) ?>" class="mt-2" data-senden>
                            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                            <label for="grund-<?= $vid ?>" class="form-label small mb-1">Grund (Pflicht)</label>
                            <input type="text" class="form-control kasse-feld mb-2" id="grund-<?= $vid ?>" name="grund" maxlength="<?= KasseDienst::GRUND_MAX ?>" required>
                            <button type="submit" class="btn btn-outline-danger kasse-knopf w-100">Verkauf stornieren</button>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <p class="mt-4 small"><a href="<?= e(url('/boersen/' . $id)) ?>">&larr; Zur Börse</a></p>
</div>
