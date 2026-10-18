<?php /** @var string $inhalt */ ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($titel) ? $titel . ' – ' . ($app_name ?? '') : ($app_name ?? '')) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
</head>
<body>
<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom">
    <div class="container">
        <a class="navbar-brand" href="<?= e(url('/')) ?>"><?= e($app_name ?? '') ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#hauptmenue"
                aria-controls="hauptmenue" aria-expanded="false" aria-label="Menü umschalten">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="hauptmenue">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php /* Navigation: Task 3 */ ?>
            </ul>
            <div class="d-flex">
                <?php /* Benutzermenü: Task 3 */ ?>
            </div>
        </div>
    </div>
</nav>
<main class="container mt-4">
    <?php foreach (($meldungen ?? []) as $meldung): ?>
        <div class="alert alert-<?= e($meldung['art'] ?? 'info') ?>" role="alert"><?= e($meldung['text'] ?? '') ?></div>
    <?php endforeach; ?>
    <?= $inhalt ?>
</main>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
