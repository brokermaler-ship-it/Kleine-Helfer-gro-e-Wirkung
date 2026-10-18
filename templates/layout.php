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
<?php include __DIR__ . '/partials/navigation.php'; ?>
<main class="container mt-4">
    <?php include __DIR__ . '/partials/betriebshinweise.php'; ?>
    <?php include __DIR__ . '/partials/meldungen.php'; ?>
    <?= $inhalt ?>
</main>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
