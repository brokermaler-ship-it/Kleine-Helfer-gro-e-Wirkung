<?php /** @var bool $gesendet */ /** @var ?string $testlink */ ?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="text-center login-kopf">
            <span class="login-logo"><img src="<?= e(url('assets/marke/logo-512.png')) ?>" alt="<?= e($app_name ?? 'EMC 78 Lokschuppen') ?>" width="512" height="512"></span>
            <p class="login-name h5 mb-0"><?= e($app_name ?? '') ?></p>
        </div>
        <div class="card login-karte"><div class="card-body">
        <h1 class="h4 mb-3">Kennwort vergessen</h1>
        <?php if ($gesendet): ?>
            <div class="alert alert-info" role="status"><?= e(\Emc\Auth\KennwortVergessen::ANTWORT) ?></div>
            <?php if ($testlink !== null): ?>
                <div class="alert alert-warning small" role="note">
                    <strong>Testumgebung</strong> – ohne Postfach steht der Link hier statt in einer E-Mail:
                    <a class="alert-link text-break" href="<?= e($testlink) ?>"><?= e($testlink) ?></a>
                </div>
            <?php endif; ?>
            <a href="<?= e(url('/login')) ?>">Zur Anmeldung</a>
        <?php else: ?>
            <p>Gib die E-Mail-Adresse deines Zugangs ein. Du bekommst dann einen Link, mit dem du ein neues Kennwort vergeben kannst.</p>
            <form method="post" action="<?= e(url('/kennwort/vergessen')) ?>">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <div class="mb-3">
                    <label for="email" class="form-label">E-Mail</label>
                    <input type="email" class="form-control" id="email" name="email" autocomplete="username" maxlength="190" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary">Link anfordern</button>
                <a class="btn btn-link" href="<?= e(url('/login')) ?>">Zur Anmeldung</a>
            </form>
        <?php endif; ?>
        </div></div>
    </div>
</div>
