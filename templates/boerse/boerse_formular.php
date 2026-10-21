<?php
/**
 * Börse anlegen bzw. bearbeiten.
 *
 * @var ?array<string,mixed> $boerse
 * @var array<string,string> $werte
 * @var array<string,string> $fehler
 * @var ?string $meldung
 * @var string $stand
 */
$neu = $boerse === null;
$aktion = $neu ? url('/boersen') : url('/boersen/' . (int) $boerse['id']);
$abbrechen = $neu ? url('/boersen') : url('/boersen/' . (int) $boerse['id']);
$wert = fn(string $n): string => (string) ($werte[$n] ?? '');
$klasse = fn(string $n): string => isset($fehler[$n]) ? ' is-invalid' : '';
$fehlerFeld = function (string $n) use ($fehler): void {
    if (isset($fehler[$n])) { ?><div class="invalid-feedback d-block"><?= e($fehler[$n]) ?></div><?php }
};
$gesperrt = !$neu && (int) $boerse['anzahl_verkaeufe'] > 0;
?>
<div class="row">
    <div class="col-lg-6">
        <h1 class="h3 mb-3"><?= e($titel) ?></h1>
        <?php if ($meldung !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($meldung) ?></div>
        <?php elseif ($fehler !== []): ?>
            <div class="alert alert-danger" role="alert">Bitte prüfe die markierten Felder.</div>
        <?php endif; ?>
        <form method="post" action="<?= e($aktion) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php if (!$neu): ?><input type="hidden" name="stand" value="<?= e($stand) ?>"><?php endif; ?>
            <div class="mb-3">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control<?= $klasse('name') ?>" id="name" name="name" value="<?= e($wert('name')) ?>" maxlength="150" required placeholder="z. B. Herbstbörse">
                <?php $fehlerFeld('name'); ?>
            </div>
            <div class="row">
                <div class="col-sm-6 mb-3">
                    <label for="datum" class="form-label">Datum</label>
                    <input type="date" class="form-control<?= $klasse('datum') ?>" id="datum" name="datum" value="<?= e($wert('datum')) ?>" required>
                    <?php $fehlerFeld('datum'); ?>
                </div>
                <div class="col-sm-6 mb-3">
                    <label for="provision_prozent" class="form-label">Provision (%)</label>
                    <input type="text" inputmode="decimal" class="form-control<?= $klasse('provision_prozent') ?>" id="provision_prozent" name="provision_prozent" value="<?= e($wert('provision_prozent')) ?>" maxlength="6"<?= $gesperrt ? ' readonly' : '' ?>>
                    <div class="form-text"><?= $gesperrt ? 'Nach dem ersten Verkauf fest.' : 'Je verkauftem Mitgliedsstück, 0 bis 100.' ?></div>
                    <?php $fehlerFeld('provision_prozent'); ?>
                </div>
            </div>
            <div class="mb-3">
                <label for="ort" class="form-label">Ort</label>
                <input type="text" class="form-control<?= $klasse('ort') ?>" id="ort" name="ort" value="<?= e($wert('ort')) ?>" maxlength="150" placeholder="z. B. Vereinsheim">
                <?php $fehlerFeld('ort'); ?>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Speichern</button>
                <a class="btn btn-link" href="<?= e($abbrechen) ?>">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
