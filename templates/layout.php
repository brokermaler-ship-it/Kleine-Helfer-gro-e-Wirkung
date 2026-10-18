<?php /** @var string $inhalt */ ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(isset($titel) ? $titel . ' – ' . ($app_name ?? 'EMC 78 Lokschuppen') : ($app_name ?? 'EMC 78 Lokschuppen')) ?></title>
    <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
    <meta name="theme-color" content="#E30311">
    <link rel="apple-touch-icon" href="<?= e(url('assets/icons/apple-touch-icon.png')) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Lokschuppen">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
</head>
<body class="<?= !empty($login_seite) ? 'login-seite' : '' ?>">
<?php if (!empty($testumgebung)): ?>
<div class="bg-warning text-dark text-center small py-1 px-2" role="status">Testumgebung – Daten können jederzeit zurückgesetzt werden.</div>
<?php endif; ?>
<?php if (!empty($login_seite)): ?>
<svg class="swoosh" viewBox="0 0 1440 220" preserveAspectRatio="none" aria-hidden="true" focusable="false">
    <path d="M0 0H1440V70C1180 20 820 40 560 130C340 205 130 215 0 150Z" fill="var(--emc-rot)"/>
</svg>
<?php else: ?>
<?php include __DIR__ . '/partials/navigation.php'; ?>
<?php endif; ?>
<main class="container mt-4">
    <?php include __DIR__ . '/partials/betriebshinweise.php'; ?>
    <?php include __DIR__ . '/partials/meldungen.php'; ?>
    <?= $inhalt ?>
</main>
<footer class="emc-fuss text-center small py-3">
    Eisenbahn Modellbahn Club 78 e.V. Halle · <span class="slogan">Die Modellbahnfamilie</span>
</footer>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
