<?php
/** @var array<string,mixed> $werte */ /** @var array<string,string> $fehler */ /** @var ?array<string,mixed> $anlage */
/** @var ?string $konflikt */ /** @var string $stand */ /** @var list<array<string,mixed>> $spurweiten */
include __DIR__ . '/_labels.php';
$aktion = $anlage === null ? url('/anlagen') : url('/anlagen/' . $anlage['id']);
$abbrechen = $anlage === null ? url('/anlagen') : url('/anlagen/' . $anlage['id']);
$wert = fn(string $n): string => (string) ($werte[$n] ?? '');
$fehlerFeld = function (string $n) use ($fehler): void {
    if (isset($fehler[$n])) { ?><div class="invalid-feedback d-block"><?= e($fehler[$n]) ?></div><?php }
};
$klasse = fn(string $n): string => isset($fehler[$n]) ? ' is-invalid' : '';
$text = function (string $n, string $label, int $max, bool $pflicht = false, string $hinweis = '') use ($wert, $klasse, $fehlerFeld): void { ?>
    <div class="mb-3">
        <label for="<?= e($n) ?>" class="form-label"><?= e($label) ?></label>
        <input type="text" class="form-control<?= $klasse($n) ?>" id="<?= e($n) ?>" name="<?= e($n) ?>" value="<?= e($wert($n)) ?>" maxlength="<?= $max ?>"<?= $pflicht ? ' required' : '' ?>>
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
?>
<div class="row">
    <div class="col-lg-8">
        <h1 class="h3 mb-3"><?= e($titel) ?><?php if ($anlage !== null): ?> <small class="text-muted"><?= e($anlage['code']) ?></small><?php endif; ?></h1>
        <?php if (!empty($konflikt)): ?>
            <div class="alert alert-danger" role="alert"><?= e($konflikt) ?></div>
        <?php elseif ($fehler !== []): ?>
            <div class="alert alert-danger" role="alert">Bitte prüfe die markierten Felder.</div>
        <?php endif; ?>
        <?php if ($anlage === null): ?>
            <p class="text-muted small">Der Code (A1, A2 …) wird beim Speichern automatisch vergeben.</p>
        <?php endif; ?>
        <form method="post" action="<?= e($aktion) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php if ($anlage !== null): ?><input type="hidden" name="_stand" value="<?= e($stand) ?>"><?php endif; ?>
            <?php $text('name', 'Name', 150, true, 'z. B. Clubanlage H0'); ?>
            <div class="row">
                <div class="col-md-6"><?php $auswahl('spurweite_id', 'Spurweite', $spurOptionen, true, '– bitte wählen –'); ?></div>
                <div class="col-md-6"><?php $auswahl('status', 'Status', $anlageStatusLabels); ?></div>
            </div>
            <?php $text('standort', 'Standort', 150, false, 'z. B. Vereinsheim, Raum 2'); ?>
            <div class="mb-3">
                <label for="kurzbeschreibung" class="form-label">Kurzbeschreibung</label>
                <textarea class="form-control<?= $klasse('kurzbeschreibung') ?>" id="kurzbeschreibung" name="kurzbeschreibung" rows="4" maxlength="1000"><?= e($wert('kurzbeschreibung')) ?></textarea>
                <?php $fehlerFeld('kurzbeschreibung'); ?>
            </div>
            <button type="submit" class="btn btn-primary">Speichern</button>
            <a class="btn btn-link" href="<?= e($abbrechen) ?>">Abbrechen</a>
        </form>
    </div>
</div>
