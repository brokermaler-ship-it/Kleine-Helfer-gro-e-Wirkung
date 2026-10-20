<?php
/** @var list<array<string,mixed>> $fahrzeuge */ /** @var \Emc\Fahrzeuge\FahrzeugFilter $filter */
/** @var list<array<string,mixed>> $spurweiten */ /** @var list<array<string,mixed>> $personen */
include __DIR__ . '/_labels.php';
?>
<div class="mb-3">
    <h1 class="h3 mb-1">Abstellgleis</h1>
    <p class="text-muted mb-0">Ausgemusterte Fahrzeuge. Sie bleiben mit Decodern, Fotos und Dokumenten erhalten, erscheinen aber nicht mehr in der Fahrzeugliste und zählen bei der Adressprüfung nicht mit.</p>
</div>

<?php $filterZiel = '/abstellgleis'; $mitStatusfilter = false; include __DIR__ . '/_filter.php'; ?>

<?php $mitSeit = true; include __DIR__ . '/_tabelle.php'; ?>

<p class="mt-3"><a href="<?= e(url('/fahrzeuge')) ?>">&larr; Zur Fahrzeugliste</a></p>
