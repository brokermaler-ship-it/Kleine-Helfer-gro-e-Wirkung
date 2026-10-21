<?php
/**
 * Eine Börse: Angaben, Starten, Preisschilder (Auswahl und Startfeld), Gesamtliste und die Angebote dieser Börse.
 *
 * @var array<string,mixed> $boerse aus BoerseRepository::finde
 * @var list<array<string,mixed>> $angebote alle Angebote dieser Börse
 * @var list<array<string,mixed>> $dauerhafte offene dauerhafte Angebote
 * @var list<array<string,mixed>> $verlauf
 * @var bool $istBoersenwart
 * @var \Emc\Etiketten\Bogen $preisschild
 */
include __DIR__ . '/_angebot_labels.php';
$b = $boerse;
$id = (int) $b['id'];
$offene = array_values(array_filter($angebote, fn(array $a): bool => in_array($a['status'], ['angeboten', 'reserviert'], true)));
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div class="min-w-0">
        <h1 class="h3 mb-1 text-break"><?= e((string) $b['name']) ?></h1>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?= $boerseStatusBadge((string) $b['status']) ?>
            <span class="text-muted"><?= e($datum((string) $b['datum'])) ?><?= $b['ort'] !== null ? ' · ' . e((string) $b['ort']) : '' ?> · Provision <?= e($provision((string) $b['provision_prozent'])) ?></span>
        </div>
    </div>
    <?php if ($istBoersenwart): ?>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($b['status'] === 'in_vorbereitung'): ?>
                <form method="post" action="<?= e(url('/boersen/' . $id . '/starten')) ?>" class="m-0" onsubmit="return confirm('Börse starten? Dann ist die Kasse offen.');">
                    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                    <button type="submit" class="btn btn-success">Börse starten</button>
                </form>
            <?php endif; ?>
            <?php if ($b['status'] === 'laeuft'): ?>
                <a class="btn btn-success" href="<?= e(url('/boersen/' . $id . '/kasse')) ?>">Kasse</a>
            <?php endif; ?>
            <?php if ($b['status'] !== 'in_vorbereitung'): ?>
                <a class="btn btn-outline-secondary" href="<?= e(url('/boersen/' . $id . '/verkaeufe')) ?>">Verkäufe</a>
            <?php endif; ?>
            <?php if ($b['status'] !== 'abgeschlossen'): ?>
                <a class="btn btn-primary" href="<?= e(url('/boersen/' . $id . '/bearbeiten')) ?>">Bearbeiten</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($istBoersenwart): ?>
<section class="card mb-4" aria-labelledby="druck-titel">
    <div class="card-body">
        <h2 class="h5" id="druck-titel">Preisschilder und Gesamtliste</h2>
        <p class="small text-muted mb-2"><?= (int) $preisschild->proBogen() ?> Preisschilder je A4-Blatt (<?= e(rtrim(rtrim((string) $preisschild->breite, '0'), '.')) ?> × <?= e(rtrim(rtrim((string) $preisschild->hoehe, '0'), '.')) ?> mm) mit Nummer, Bezeichnung, Preis und QR-Code.</p>
        <form method="get" action="<?= e(url('/boersen/' . $id . '/preisschilder')) ?>" class="row g-2 align-items-end" target="_blank">
            <div class="col-12 col-md-5">
                <label for="ps-auswahl" class="form-label small mb-0">Welche</label>
                <select class="form-select" id="ps-auswahl" name="auswahl">
                    <option value="boerse">offene Angebote dieser Börse (<?= count($offene) ?>)</option>
                    <option value="dauerhaft">offene dauerhafte Angebote (<?= count($dauerhafte) ?>)</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="ps-start" class="form-label small mb-0">Start auf Feld</label>
                <input type="number" class="form-control" id="ps-start" name="start" value="1" min="1" max="<?= (int) $preisschild->proBogen() ?>">
            </div>
            <div class="col-6 col-md-auto"><button type="submit" class="btn btn-outline-primary">Preisschilder</button></div>
        </form>
        <p class="mt-3 mb-0"><a href="<?= e(url('/boersen/' . $id . '/liste')) ?>" target="_blank">Gesamtliste zum Drucken</a></p>
    </div>
</section>
<?php endif; ?>

<h2 class="h4">Angebote dieser Börse</h2>
<?php if ($angebote === []): ?>
    <p class="text-muted">Noch keine Angebote.</p>
<?php else: ?>
    <?php if ($istBoersenwart && $offene !== []): ?><form method="get" action="<?= e(url('/boersen/' . $id . '/preisschilder')) ?>" target="_blank"><input type="hidden" name="auswahl" value="einzeln"><?php endif; ?>
    <div class="list-group mb-2">
        <?php foreach ($angebote as $a): ?>
            <?php $offen = in_array($a['status'], ['angeboten', 'reserviert'], true); ?>
            <div class="list-group-item d-flex gap-3 align-items-start">
                <?php if ($istBoersenwart && $offene !== []): ?>
                    <input class="form-check-input mt-2 flex-shrink-0" type="checkbox" name="ids[]" value="<?= (int) $a['id'] ?>" aria-label="Preisschild für <?= e((string) $a['nummer']) ?>"<?= $offen ? '' : ' disabled' ?>>
                <?php endif; ?>
                <?= $angebotTitelbild($a) ?>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between gap-2">
                        <a class="fw-semibold text-break" href="<?= e(url('/angebote/' . (int) $a['id'])) ?>"><span class="fw-bold me-1"><?= e((string) $a['nummer']) ?></span> <?= e((string) $a['bezeichnung']) ?></a>
                        <div class="text-nowrap fw-semibold"><?= e($preis((string) $a['wunschpreis'])) ?></div>
                    </div>
                    <div class="small text-muted"><?= e($angebotHerkunft[(string) $a['herkunft']] ?? '') ?></div>
                    <div class="mt-1 d-flex flex-wrap gap-1"><?= $angebotStatusBadge((string) $a['status']) ?> <?= $angebotOeffentlichBadge((string) $a['oeffentlich']) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($istBoersenwart && $offene !== []): ?>
        <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
            <div>
                <label for="ps-start-einzeln" class="form-label small mb-0">Start auf Feld</label>
                <input type="number" class="form-control" id="ps-start-einzeln" name="start" value="1" min="1" max="<?= (int) $preisschild->proBogen() ?>" style="max-width: 7rem">
            </div>
            <button type="submit" class="btn btn-outline-primary">Preisschilder für die gewählten</button>
        </div>
        </form>
    <?php endif; ?>
<?php endif; ?>
<p class="small text-muted">Dauerhafte Angebote (an jeder Börse verkaufbar): <a href="<?= e(url('/angebote', ['boerse' => 'dauerhaft'])) ?>"><?= count($dauerhafte) ?> offen</a></p>

<?php if ($istBoersenwart) { $verlaufTitel = 'Änderungen'; include dirname(__DIR__) . '/partials/verlauf.php'; } ?>
<p class="mt-3"><a href="<?= e(url('/boersen')) ?>">&larr; Alle Börsen</a></p>
