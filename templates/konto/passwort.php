<?php /** @var array<string,string> $fehler */ /** @var bool $erzwungen */
$feld = function (string $name, string $label, string $auto) use ($fehler): void { ?>
    <div class="mb-3">
        <label for="<?= e($name) ?>" class="form-label"><?= e($label) ?></label>
        <input type="password" class="form-control<?= isset($fehler[$name]) ? ' is-invalid' : '' ?>" id="<?= e($name) ?>"
               name="<?= e($name) ?>" autocomplete="<?= e($auto) ?>" required>
        <?php if (isset($fehler[$name])): ?><div class="invalid-feedback"><?= e($fehler[$name]) ?></div><?php endif; ?>
    </div>
<?php }; ?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-5">
        <h1 class="h3 mb-3">Passwort ändern</h1>
        <?php if ($erzwungen): ?>
            <div class="alert alert-info" role="alert">Bitte vergib ein eigenes Passwort, bevor du weitermachst.</div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/konto/passwort')) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php $feld('altes_passwort', 'Bisheriges Passwort', 'current-password'); ?>
            <?php $feld('neues_passwort', 'Neues Passwort (mindestens 10 Zeichen)', 'new-password'); ?>
            <?php $feld('wiederholung', 'Neues Passwort wiederholen', 'new-password'); ?>
            <button type="submit" class="btn btn-primary">Passwort ändern</button>
        </form>
    </div>
</div>
