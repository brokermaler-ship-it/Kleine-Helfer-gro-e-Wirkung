<?php /** @var string $inhalt */ ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($titel) ? $titel . ' – ' . ($app_name ?? '') : ($app_name ?? '')) ?></title>
    <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
    <meta name="theme-color" content="#1f4d3a">
    <link rel="apple-touch-icon" href="<?= e(url('assets/icons/apple-touch-icon.png')) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="EMC 78">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
</head>
<body>
<?php if (!empty($testumgebung)): ?>
<div class="bg-warning text-dark text-center small py-1 px-2" role="status">Testumgebung – Daten können jederzeit zurückgesetzt werden.</div>
<?php endif; ?>
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
