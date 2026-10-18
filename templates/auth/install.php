<?php /** @var array<string,string> $werte */ /** @var array<string,string> $fehler */
$feld = function (string $name, string $label, string $typ, string $auto) use ($werte, $fehler): void { ?>
    <div class="mb-3">
        <label for="<?= e($name) ?>" class="form-label"><?= e($label) ?></label>
        <input type="<?= e($typ) ?>" class="form-control<?= isset($fehler[$name]) ? ' is-invalid' : '' ?>" id="<?= e($name) ?>" name="<?= e($name) ?>"
               value="<?= $typ === 'password' ? '' : e($werte[$name] ?? '') ?>" autocomplete="<?= e($auto) ?>" required>
        <?php if (isset($fehler[$name])): ?><div class="invalid-feedback"><?= e($fehler[$name]) ?></div><?php endif; ?>
    </div>
<?php }; ?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-5">
        <h1 class="h3 mb-3">Installation</h1>
        <p>Lege den ersten Administrator an. Die Datenbank wird dabei eingerichtet.</p>
        <form method="post" action="<?= e(url('/install')) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php $feld('vorname', 'Vorname', 'text', 'given-name'); ?>
            <?php $feld('nachname', 'Nachname', 'text', 'family-name'); ?>
            <?php $feld('email', 'E-Mail', 'email', 'username'); ?>
            <?php $feld('passwort', 'Passwort (mindestens 10 Zeichen)', 'password', 'new-password'); ?>
            <button type="submit" class="btn btn-primary">Installieren</button>
        </form>
    </div>
</div>
