<?php
/** @var array<string,mixed> $werte */ /** @var array<string,string> $fehler */ /** @var ?array<string,mixed> $fahrzeug */
/** @var ?string $konflikt */ /** @var string $stand */ /** @var ?string $eigentuemerFest */
/** @var list<array<string,mixed>> $spurweiten */ /** @var list<array<string,mixed>> $personen */
include __DIR__ . '/_labels.php';
$aktion = $fahrzeug === null ? url('/fahrzeuge') : url('/fahrzeuge/' . $fahrzeug['id']);
$abbrechen = $fahrzeug === null ? url('/fahrzeuge') : url('/fahrzeuge/' . $fahrzeug['id']);
$wert = fn(string $n): string => (string) ($werte[$n] ?? '');
$fehlerFeld = function (string $n) use ($fehler): void {
    if (isset($fehler[$n])) { ?><div class="invalid-feedback d-block"><?= e($fehler[$n]) ?></div><?php }
};
$klasse = fn(string $n): string => isset($fehler[$n]) ? ' is-invalid' : '';
$text = function (string $n, string $label, bool $pflicht = false, string $hinweis = '') use ($wert, $klasse, $fehlerFeld): void { ?>
    <div class="mb-3">
        <label for="<?= e($n) ?>" class="form-label"><?= e($label) ?></label>
        <input type="text" class="form-control<?= $klasse($n) ?>" id="<?= e($n) ?>" name="<?= e($n) ?>" value="<?= e($wert($n)) ?>"<?= $pflicht ? ' required' : '' ?>>
        <?php if ($hinweis !== ''): ?><div class="form-text"><?= e($hinweis) ?></div><?php endif; ?>
        <?php $fehlerFeld($n); ?>
    </div>
<?php };
$auswahl = function (string $n, string $label, array $optionen, bool $leer = false, string $leerText = '') use ($wert, $klasse, $fehlerFeld): void { ?>
    <div class="mb-3">
        <label for="<?= e($n) ?>" class="form-label"><?= e($label) ?></label>
        <select class="form-select<?= $klasse($n) ?>" id="<?= e($n) ?>" name="<?= e($n) ?>">
            <?php if ($leer): ?><option value=""><?= e($leerText) ?></option><?php endif; ?>
            <?php foreach ($optionen as $v => $t): ?>
                <option value="<?= e((string) $v) ?>"<?= $wert($n) === (string) $v ? ' selected' : '' ?>><?= e((string) $t) ?></option>
            <?php endforeach; ?>
        </select>
        <?php $fehlerFeld($n); ?>
    </div>
<?php };
$spurOptionen = [];
foreach ($spurweiten as $s) {
    $spurOptionen[$s['id']] = $s['name'] . ($s['massstab'] ? ' (' . $s['massstab'] . ')' : '');
}
$personOptionen = [];
foreach ($personen as $pe) {
    $personOptionen[$pe['id']] = $pe['vorname'] . ' ' . $pe['nachname'];
}
// Eigentümer, der inzwischen inaktiv ist, bleibt wählbar.
if ($fahrzeug !== null && $fahrzeug['eigentuemer_person_id'] !== null && !isset($personOptionen[$fahrzeug['eigentuemer_person_id']])) {
    $personOptionen[$fahrzeug['eigentuemer_person_id']] = (string) $fahrzeug['eigentuemer_name'] . ' (inaktiv)';
}
?>
<div class="row">
    <div class="col-lg-8">
        <h1 class="h3 mb-3"><?= e($titel) ?><?php if ($fahrzeug !== null): ?> <small class="text-muted"><?= e($fahrzeug['inventarnummer']) ?></small><?php endif; ?></h1>
        <?php if (!empty($konflikt)): ?>
            <div class="alert alert-danger" role="alert"><?= e($konflikt) ?></div>
        <?php elseif ($fehler !== []): ?>
            <div class="alert alert-danger" role="alert">Bitte prüfe die markierten Felder.</div>
        <?php endif; ?>
        <form method="post" action="<?= e($aktion) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php if ($fahrzeug !== null): ?><input type="hidden" name="_stand" value="<?= e($stand) ?>"><?php endif; ?>
            <?php $text('bezeichnung', 'Bezeichnung', true); ?>
            <div class="row">
                <div class="col-md-6"><?php $text('baureihe', 'Baureihe'); ?></div>
                <div class="col-md-6"><?php $text('bahnverwaltung', 'Bahnverwaltung', false, 'z. B. DB, ÖBB, SBB'); ?></div>
            </div>
            <div class="row">
                <div class="col-md-6"><?php $auswahl('gattung', 'Gattung', $gattungen); ?></div>
                <div class="col-md-6"><?php $auswahl('epoche', 'Epoche', array_combine($epochen, $epochen), true, '– keine Angabe –'); ?></div>
            </div>
            <div class="row">
                <div class="col-md-6"><?php $text('hersteller', 'Hersteller'); ?></div>
                <div class="col-md-6"><?php $text('artikelnummer', 'Artikelnummer'); ?></div>
            </div>
            <div class="row">
                <div class="col-md-6"><?php $auswahl('spurweite_id', 'Spurweite', $spurOptionen, true, '– bitte wählen –'); ?></div>
                <div class="col-md-6"><?php $auswahl('stromsystem', 'Stromsystem', $stromsysteme); ?></div>
            </div>
            <?php if (($eigentuemerFest ?? null) !== null): ?>
                <p class="mb-3" id="eigentum-fest"><?= e($eigentuemerFest) ?></p>
            <?php else: ?>
                <?php $auswahl('eigentuemer_person_id', 'Eigentümer', $personOptionen, true, 'Verein'); ?>
            <?php endif; ?>
            <div class="row">
                <div class="col-md-6"><?php $auswahl('status', 'Status', $statusLabels); ?></div>
                <div class="col-md-6"><?php $text('betriebsstunden', 'Betriebsstunden', false, 'Dezimalkomma erlaubt, z. B. 12,5'); ?></div>
            </div>
            <div class="mb-3">
                <label for="notiz" class="form-label">Notiz</label>
                <textarea class="form-control<?= $klasse('notiz') ?>" id="notiz" name="notiz" rows="4"><?= e($wert('notiz')) ?></textarea>
                <?php $fehlerFeld('notiz'); ?>
            </div>
            <button type="submit" class="btn btn-primary">Speichern</button>
            <a class="btn btn-link" href="<?= e($abbrechen) ?>">Abbrechen</a>
        </form>
    </div>
</div>
