<?php
/**
 * @var array<string,mixed> $decoder
 * @var array<string,mixed> $fahrzeug
 * @var \Emc\Digital\CsvErgebnis $ergebnis
 * @var int $vorhanden
 * @var string|null $quelle „csv“ (Standard) oder „pdf“
 */
$quelle = ($quelle ?? 'csv') === 'pdf' ? 'pdf' : 'csv';
$basis = url('/decoder/' . $decoder['id'] . '/funktionen');
$anzahl = count($ergebnis->zeilen);
?>
<h1 class="h3 mb-1"><?= $quelle === 'pdf' ? 'PDF' : 'CSV' ?>-Vorschau: <?= e($decoder['bezeichnung']) ?></h1>
<p class="text-muted"><?= e($fahrzeug['inventarnummer']) ?> <?= e($fahrzeug['bezeichnung']) ?></p>

<?php if ($ergebnis->fehler !== []): ?>
    <div class="alert alert-danger" role="alert">
        <p class="mb-1"><strong>Die Datei enthält Fehler und kann nicht übernommen werden.</strong></p>
        <ul class="mb-0">
            <?php foreach ($ergebnis->fehler as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($ergebnis->hinweise !== []): ?>
    <div class="alert alert-info">
        <p class="mb-1"><strong>Hinweise</strong></p>
        <ul class="mb-0">
            <?php foreach ($ergebnis->hinweise as $h): ?><li><?= e($h) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($quelle === 'pdf'): ?>
    <p><?= $anzahl === 1 ? '1 Taste erkannt.' : e($anzahl . ' Tasten erkannt.') ?> Nicht erkannte Tasten bitte nach dem Übernehmen von Hand ergänzen.</p>
<?php endif; ?>

<?php if ($ergebnis->zeilen !== []): ?>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th scope="col">Taste</th><th scope="col">Beschreibung</th><th scope="col">Schaltart</th></tr></thead>
            <tbody>
            <?php foreach ($ergebnis->zeilen as $z): ?>
                <tr><th scope="row">F<?= (int) $z['taste'] ?></th><td><?= e($z['beschreibung']) ?></td><td><?= $z['schaltart'] === 'moment' ? 'Moment' : 'Dauer' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($ergebnis->fehler === []): ?>
    <p class="alert alert-warning">Die bisherige Belegung wird ersetzt<?= $vorhanden > 0 ? ' (derzeit ' . $vorhanden . ' Tasten belegt)' : '' ?>.</p>
    <form method="post" action="<?= e($basis . '/' . $quelle . '/uebernehmen') ?>" class="d-inline">
        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
        <button type="submit" class="btn btn-primary">Übernehmen</button>
    </form>
<?php endif; ?>
<a class="btn btn-outline-secondary" href="<?= e($basis) ?>">Abbrechen</a>
