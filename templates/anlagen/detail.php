<?php
/** @var array<string,mixed> $anlage */ /** @var list<array<string,mixed>> $dateien */ /** @var list<array<string,mixed>> $verlauf */
include __DIR__ . '/_labels.php';
$a = $anlage;
$darfBearbeiten = $darf(\Emc\Auth\Berechtigung::ANLAGE_BEARBEITEN);
$zeile = function (string $label, ?string $wert): void { ?>
    <dt class="col-sm-4 col-lg-3"><?= e($label) ?></dt>
    <dd class="col-sm-8 col-lg-9"><?= $wert === null || $wert === '' ? '–' : nl2br(e($wert)) ?></dd>
<?php };
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1"><span class="anlage-code display-6 fw-bold me-2"><?= e($a['code']) ?></span><?= e($a['name']) ?></h1>
        <div><?= $anlageStatusBadge($a) ?></div>
    </div>
    <?php if ($darfBearbeiten): ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="<?= e(url('/anlagen/' . $a['id'] . '/bearbeiten')) ?>">Bearbeiten</a>
            <form method="post" action="<?= e(url('/anlagen/' . $a['id'] . '/loeschen')) ?>"
                  onsubmit="return confirm('Anlage samt Fotos und Dokumenten endgültig löschen?');">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <button type="submit" class="btn btn-outline-danger">Löschen</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<dl class="row">
    <?php $zeile('Code', (string) $a['code']); ?>
    <?php $zeile('Spurweite', (string) $a['spurweite_name']); ?>
    <?php $zeile('Standort', $a['standort']); ?>
    <?php $zeile('Status', $anlageStatusLabels[$a['status']] ?? (string) $a['status']); ?>
    <?php $zeile('Kurzbeschreibung', $a['kurzbeschreibung']); ?>
</dl>

<section id="dateien" class="mt-4" aria-labelledby="dateien-titel">
    <h2 class="h4" id="dateien-titel">Fotos und Dokumente</h2>
    <?php
    $uploadUrl = url('/anlagen/' . $a['id'] . '/dateien');
    $titelbildUrl = url('/anlagen/' . $a['id'] . '/titelbild');
    $darfDateien = $darfBearbeiten;
    $titelbildId = $a['titelbild_dokument_id'];
    include dirname(__DIR__) . '/partials/dateien.php';
    ?>
</section>

<?php if (($wartung ?? null) !== null): ?>
<section id="wartung" class="mt-4" aria-labelledby="wartung-titel">
    <h2 class="h4" id="wartung-titel">Wartung</h2>
    <?php $block = $wartung; include dirname(__DIR__) . '/wartung/_block.php'; ?>
</section>
<?php endif; ?>

<?php include dirname(__DIR__) . '/partials/verlauf.php'; ?>
<p class="mt-3"><a href="<?= e(url('/anlagen')) ?>">&larr; Zurück zur Liste</a></p>
