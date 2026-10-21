<?php /** @var ?string $token */ /** @var array<string,string> $fehler */
$feld = function (string $name, string $label) use ($fehler): void { ?>
    <div class="mb-3">
        <label for="<?= e($name) ?>" class="form-label"><?= e($label) ?></label>
        <input type="password" class="form-control<?= isset($fehler[$name]) ? ' is-invalid' : '' ?>" id="<?= e($name) ?>"
               name="<?= e($name) ?>" autocomplete="new-password" minlength="<?= \Emc\Personen\BenutzerRepository::MIN_PASSWORT ?>" required>
        <?php if (isset($fehler[$name])): ?><div class="invalid-feedback"><?= e($fehler[$name]) ?></div><?php endif; ?>
    </div>
<?php }; ?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="text-center login-kopf">
            <span class="login-logo"><img src="<?= e(url('assets/marke/logo-512.png')) ?>" alt="<?= e($app_name ?? 'EMC 78 Lokschuppen') ?>" width="512" height="512"></span>
            <p class="login-name h5 mb-0"><?= e($app_name ?? '') ?></p>
        </div>
        <div class="card login-karte"><div class="card-body">
        <h1 class="h4 mb-3">Neues Kennwort</h1>
        <?php if ($token === null): ?>
            <div class="alert alert-danger" role="alert"><?= e(\Emc\Auth\KennwortVergessen::UNGUELTIG) ?></div>
            <a class="btn btn-primary" href="<?= e(url('/kennwort/vergessen')) ?>">Neuen Link anfordern</a>
            <a class="btn btn-link" href="<?= e(url('/login')) ?>">Zur Anmeldung</a>
        <?php else: ?>
            <form method="post" action="<?= e(url('/kennwort/neu')) ?>">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <input type="hidden" name="t" value="<?= e($token) ?>">
                <?php $feld('neues_passwort', 'Neues Kennwort (mindestens ' . \Emc\Personen\BenutzerRepository::MIN_PASSWORT . ' Zeichen)'); ?>
                <?php $feld('wiederholung', 'Neues Kennwort wiederholen'); ?>
                <button type="submit" class="btn btn-primary">Kennwort speichern</button>
            </form>
        <?php endif; ?>
        </div></div>
    </div>
</div>
