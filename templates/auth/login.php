<?php /** @var string $ziel */ /** @var string $email */ /** @var ?string $fehler */ ?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <h1 class="h3 mb-3">Anmelden</h1>
        <?php if (!empty($fehler)): ?>
            <div class="alert alert-danger" role="alert"><?= e($fehler) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/login')) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <input type="hidden" name="ziel" value="<?= e($ziel) ?>">
            <div class="mb-3">
                <label for="email" class="form-label">E-Mail</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="mb-3">
                <label for="passwort" class="form-label">Passwort</label>
                <input type="password" class="form-control" id="passwort" name="passwort" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary">Anmelden</button>
        </form>
        <?php if (!empty($testumgebung)): ?>
            <div class="alert alert-warning mt-4 small" role="note">
                <strong>Testumgebung</strong> – hier gibt es nur Beispieldaten. Test-Zugänge (Passwort: <code>Test-EMC78!</code>):
                <ul class="mb-0 mt-1">
                    <li><code>bearbeiter@test.emc78.de</code> – Bearbeiter</li>
                    <li><code>mitglied@test.emc78.de</code> – Mitglied</li>
                    <li>Admin-Zugang: beim Vorstand erfragen</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
