<?php
/**
 * @var string $dateiname
 * @var \Emc\Fahrzeuge\FahrzeugImportErgebnis $ergebnis
 */
include __DIR__ . '/_labels.php';
$importierbar = $ergebnis->istImportierbar();
$anlegen = $ergebnis->anzahl('anlegen');
?>
<h1 class="h3 mb-1">Import-Vorschau</h1>
<p class="text-muted">Datei „<?= e($dateiname) ?>“</p>

<dl class="row mb-3" aria-label="Zusammenfassung">
    <dt class="col-8 col-md-3">Wird angelegt</dt><dd class="col-4 col-md-9" data-anzahl="anlegen"><?= $anlegen ?></dd>
    <dt class="col-8 col-md-3">Fehlerzeilen</dt><dd class="col-4 col-md-9" data-anzahl="fehler"><?= $ergebnis->anzahl('fehler') ?></dd>
    <dt class="col-8 col-md-3">Zugverbände (übersprungen)</dt><dd class="col-4 col-md-9" data-anzahl="zugverband"><?= $ergebnis->anzahl('zugverband') ?></dd>
    <dt class="col-8 col-md-3">Bezeichnung doppelt</dt><dd class="col-4 col-md-9" data-anzahl="doppelt"><?= $ergebnis->anzahlDoppelt() ?></dd>
</dl>

<?php if ($ergebnis->bereitsImportiert !== null): ?>
    <div class="alert alert-danger" role="alert"><strong><?= e($ergebnis->bereitsImportiert) ?></strong> Ein zweiter Import derselben Datei ist nicht möglich.</div>
<?php elseif (!$importierbar): ?>
    <div class="alert alert-danger" role="alert">
        <?php if ($ergebnis->fehler !== []): ?>
            <ul class="mb-2">
                <?php foreach ($ergebnis->fehler as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
            </ul>
        <?php elseif ($ergebnis->anzahl('fehler') > 0): ?>
            <p class="mb-2">Die Datei enthält Fehlerzeilen (rot markiert); es wird nichts importiert.</p>
        <?php else: ?>
            <p class="mb-2">Die Datei enthält keine Fahrzeuge, die angelegt werden könnten.</p>
        <?php endif; ?>
        <strong><?= e(\Emc\Fahrzeuge\FahrzeugImport::KORRIGIEREN) ?></strong>
    </div>
<?php else: ?>
    <p class="alert alert-info">Es werden <?= $anlegen ?> Fahrzeug<?= $anlegen === 1 ? '' : 'e' ?> mit neuen Inventarnummern angelegt (Eigentum Verein, ungeprüft).</p>
<?php endif; ?>

<?php if ($ergebnis->zeilen !== []): ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th scope="col">Zeile</th><th scope="col">Alte Nummer</th><th scope="col">Bezeichnung</th><th scope="col">Gattung</th><th scope="col">Spur</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($ergebnis->zeilen as $z):
                if ($z['status'] === 'fehler') {
                    $klasse = 'table-danger';
                    $text = 'Fehler: ' . implode(' ', $z['fehler']);
                } elseif ($z['status'] === 'zugverband') {
                    $klasse = 'table-secondary';
                    $text = 'Zugverband – wird nicht importiert';
                } elseif ($z['doppelt']) {
                    $klasse = 'table-warning';
                    $text = 'Bezeichnung doppelt (in Datei oder schon vorhanden) – wird trotzdem angelegt';
                } else {
                    $klasse = '';
                    $text = 'wird angelegt';
                }
                $gattung = $z['daten']['gattung'] ?? null;
                ?>
                <tr class="<?= e($klasse) ?>">
                    <th scope="row"><?= (int) $z['nr'] ?></th>
                    <td><?= e($z['alte_nummer']) ?></td>
                    <td><?= e($z['bezeichnung']) ?></td>
                    <td><?= e($gattung !== null ? ($gattungen[$gattung] ?? $gattung) : $z['gattung_text']) ?></td>
                    <td><?= e($z['spur']) ?></td>
                    <td><?= e($text) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2">
    <?php if ($importierbar): ?>
        <form method="post" action="<?= e(url('/fahrzeuge/import/bestaetigen')) ?>"
              onsubmit="this.querySelector('button').disabled = true;">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <button type="submit" class="btn btn-primary">Import bestätigen</button>
        </form>
        <form method="post" action="<?= e(url('/fahrzeuge/import/abbrechen')) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <button type="submit" class="btn btn-outline-secondary">Abbrechen</button>
        </form>
    <?php else: ?>
        <a class="btn btn-primary" href="<?= e(url('/fahrzeuge/import')) ?>">Andere Datei hochladen</a>
    <?php endif; ?>
</div>
