<?php
/** @var array<string,mixed> $fz */
include_once __DIR__ . '/_labels.php';
$zeile = function (string $label, ?string $wert): void { ?>
    <dt class="col-sm-4 col-lg-3"><?= e($label) ?></dt>
    <dd class="col-sm-8 col-lg-9"><?= $wert === null || $wert === '' ? '–' : e($wert) ?></dd>
<?php };
?>
<dl class="row">
    <?php $zeile('Inventarnummer', $fz['inventarnummer']); ?>
    <?php $zeile('Baureihe', $fz['baureihe']); ?>
    <?php $zeile('Bahnverwaltung', $fz['bahnverwaltung']); ?>
    <?php $zeile('Gattung', $gattungen[$fz['gattung']] ?? $fz['gattung']); ?>
    <?php $zeile('Epoche', $fz['epoche']); ?>
    <?php $zeile('Hersteller', $fz['hersteller']); ?>
    <?php $zeile('Artikelnummer', $fz['artikelnummer']); ?>
    <?php $zeile('Spurweite', $fz['spurweite_name']); ?>
    <?php $zeile('Stromsystem', $stromsysteme[$fz['stromsystem']] ?? $fz['stromsystem']); ?>
    <?php $zeile('Eigentümer', $fz['eigentuemer_person_id'] === null ? 'Verein' : (string) $fz['eigentuemer_name']); ?>
    <?php $zeile('Status', $statusLabels[$fz['status']] ?? $fz['status']); ?>
    <?php $zeile('Betriebsstunden', str_replace('.', ',', (string) (float) $fz['betriebsstunden'])); ?>
    <?php $zeile('Notiz', $fz['notiz']); ?>
</dl>
