<?php
/**
 * Gemeinsame Felder für „Decoder hinzufügen“ und „Decoder bearbeiten“.
 *
 * @var array<string,mixed> $werte
 * @var array<string,string> $fehler
 * @var array<string,mixed> $fahrzeug
 */
$w = fn(string $n): string => (string) ($werte[$n] ?? '');
$k = fn(string $n): string => isset($fehler[$n]) ? ' is-invalid' : '';
$f = function (string $n) use ($fehler): void {
    if (isset($fehler[$n])) { ?><div class="invalid-feedback d-block"><?= e($fehler[$n]) ?></div><?php }
};
$protokolle = [];
foreach (\Emc\Digital\Protokoll::cases() as $pr) {
    $protokolle[$pr->value] = $pr->label();
}
$gewaehlt = $w('protokoll') !== '' ? $w('protokoll') : 'dcc';
?>
<div class="row g-3">
    <div class="col-md-6">
        <label for="bezeichnung" class="form-label">Bezeichnung</label>
        <input type="text" class="form-control<?= $k('bezeichnung') ?>" id="bezeichnung" name="bezeichnung" value="<?= e($w('bezeichnung')) ?>" placeholder="z. B. Motor" required>
        <?php $f('bezeichnung'); ?>
    </div>
    <div class="col-md-3">
        <label for="hersteller" class="form-label">Hersteller</label>
        <input type="text" class="form-control<?= $k('hersteller') ?>" id="hersteller" name="hersteller" value="<?= e($w('hersteller')) ?>">
        <?php $f('hersteller'); ?>
    </div>
    <div class="col-md-3">
        <label for="typ" class="form-label">Typ</label>
        <input type="text" class="form-control<?= $k('typ') ?>" id="typ" name="typ" value="<?= e($w('typ')) ?>">
        <?php $f('typ'); ?>
    </div>
    <div class="col-md-3">
        <label for="firmware" class="form-label">Firmware</label>
        <input type="text" class="form-control<?= $k('firmware') ?>" id="firmware" name="firmware" value="<?= e($w('firmware')) ?>">
        <?php $f('firmware'); ?>
    </div>
    <div class="col-md-3">
        <label for="protokoll" class="form-label">Protokoll</label>
        <select class="form-select<?= $k('protokoll') ?>" id="protokoll" name="protokoll">
            <?php foreach ($protokolle as $wert => $label): ?>
                <option value="<?= e($wert) ?>"<?= $gewaehlt === $wert ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <?php $f('protokoll'); ?>
    </div>
    <div class="col-md-3">
        <label for="adresse" class="form-label">Adresse</label>
        <input type="text" inputmode="numeric" autocomplete="off" class="form-control<?= $k('adresse') ?>" id="adresse" name="adresse" value="<?= e($w('adresse')) ?>">
        <?php $f('adresse'); ?>
        <div class="form-text" data-adress-art aria-live="polite"></div>
        <div class="text-warning-emphasis small" data-adress-warnung aria-live="polite"></div>
    </div>
    <div class="col-md-3 d-flex align-items-end">
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="sound" name="sound" value="1"<?= !empty($werte['sound']) ? ' checked' : '' ?>>
            <label class="form-check-label" for="sound">Sounddecoder</label>
        </div>
    </div>
</div>
