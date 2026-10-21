<?php
/**
 * Gemeinsame Felder der öffentlichen Formulare: Name, E-Mail, Telefon, Einwilligung, Fangfeld und Zeitmarke
 * (das Nachrichtenfeld und Besonderheiten setzt das jeweilige Formular).
 *
 * @var array<string,string> $eingabe
 * @var array<string,string> $fehler
 * @var string $zeitmarke
 * @var ?string $datenschutz_url
 */
$feldFehler = function (string $feld) use ($fehler): string {
    return isset($fehler[$feld]) ? '<div class="invalid-feedback d-block">' . e($fehler[$feld]) . '</div>' : '';
};
$ungueltig = fn(string $feld): string => isset($fehler[$feld]) ? ' is-invalid' : '';
?>
<?php include dirname(__DIR__, 2) . '/partials/csrf.php'; ?>
<input type="hidden" name="zeitmarke" value="<?= e($zeitmarke) ?>">
<div class="boerse-fang" aria-hidden="true"><label>Webseite <input name="webseite" tabindex="-1" autocomplete="off"></label></div>
<div class="mb-3">
    <label for="a-name" class="form-label">Dein Name</label>
    <input type="text" class="form-control<?= $ungueltig('name') ?>" id="a-name" name="name" maxlength="100" autocomplete="name" required value="<?= e($eingabe['name'] ?? '') ?>">
    <?= $feldFehler('name') ?>
</div>
<div class="row g-2 mb-1">
    <div class="col-12 col-md-6">
        <label for="a-email" class="form-label">E-Mail</label>
        <input type="email" class="form-control<?= $ungueltig('email') . $ungueltig('kontakt') ?>" id="a-email" name="email" maxlength="190" autocomplete="email" value="<?= e($eingabe['email'] ?? '') ?>">
        <?= $feldFehler('email') ?>
    </div>
    <div class="col-12 col-md-6">
        <label for="a-telefon" class="form-label">Telefon</label>
        <input type="tel" class="form-control<?= $ungueltig('telefon') . $ungueltig('kontakt') ?>" id="a-telefon" name="telefon" maxlength="40" autocomplete="tel" value="<?= e($eingabe['telefon'] ?? '') ?>">
        <?= $feldFehler('telefon') ?>
    </div>
</div>
<?= $feldFehler('kontakt') ?>
<div class="form-text mb-3">E-Mail oder Telefon genügt.</div>
