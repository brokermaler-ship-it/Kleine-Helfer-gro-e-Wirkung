<?php
/** @var list<array<string,mixed>> $fahrzeuge */ /** @var \Emc\Fahrzeuge\FahrzeugFilter $filter */
/** @var list<array<string,mixed>> $meine */ /** @var list<array<string,mixed>> $spurweiten */ /** @var list<array<string,mixed>> $personen */
include __DIR__ . '/_labels.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Fahrzeuge</h1>
    <?php if ($darf('fahrzeug_bearbeiten')): ?>
        <a class="btn btn-primary" href="<?= e(url('/fahrzeuge/neu')) ?>">+ Fahrzeug anlegen</a>
    <?php elseif ($darf('fahrzeug_anlegen')): ?>
        <a class="btn btn-primary" href="<?= e(url('/fahrzeuge/neu')) ?>">+ Eigenes Fahrzeug anlegen</a>
    <?php endif; ?>
</div>

<?php if (($meine ?? []) !== []): ?>
    <section class="meine-fahrzeuge mb-4" aria-labelledby="meine-fahrzeuge-titel">
        <h2 class="h5 abschnitt-titel" id="meine-fahrzeuge-titel">Meine Fahrzeuge</h2>
        <div class="row g-2">
            <?php foreach ($meine as $fz): ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <a class="card h-100 text-decoration-none text-reset meine-karte" href="<?= e(url('/fahrzeuge/' . $fz['id'])) ?>">
                        <div class="card-body d-flex gap-3">
                            <?= $titelbild($fz) ?>
                            <div class="flex-grow-1">
                                <h3 class="h6 mb-1"><?= e($fz['bezeichnung']) ?></h3>
                                <div class="small text-muted"><?= e($fz['inventarnummer']) ?> · <?= e($fz['spurweite_name']) ?></div>
                                <div class="small">Adresse: <?= $adressen($fz) ?></div>
                                <div class="mt-1"><?= $statusBadge($fz) ?></div>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <h2 class="h5 abschnitt-titel">Alle Fahrzeuge</h2>
<?php endif; ?>

<?php $filterZiel = '/fahrzeuge'; $mitStatusfilter = true; include __DIR__ . '/_filter.php'; ?>

<?php $mitSeit = false; include __DIR__ . '/_tabelle.php'; ?>
