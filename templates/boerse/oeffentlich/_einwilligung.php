<?php
/**
 * Pflicht-Häkchen zur Einwilligung mit Link auf die Datenschutzerklärung.
 *
 * @var array<string,string> $eingabe
 * @var array<string,string> $fehler
 * @var ?string $datenschutz_url
 */
?>
<div class="form-check mb-3">
    <input class="form-check-input<?= isset($fehler['einwilligung']) ? ' is-invalid' : '' ?>" type="checkbox" id="a-einwilligung" name="einwilligung" value="1" required<?= ($eingabe['einwilligung'] ?? '') === '1' ? ' checked' : '' ?>>
    <label class="form-check-label" for="a-einwilligung">
        Ich bin einverstanden, dass der Verein meine Angaben zur Bearbeitung dieser Anfrage speichert. Sie werden spätestens
        nach 12 Monaten gelöscht. Mehr dazu in der <a href="<?= e($datenschutz_url ?? '') ?>" rel="noopener">Datenschutzerklärung</a>.
    </label>
    <?php if (isset($fehler['einwilligung'])): ?><div class="invalid-feedback d-block"><?= e($fehler['einwilligung']) ?></div><?php endif; ?>
</div>
