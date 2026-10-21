<?php
/**
 * Layout des öffentlichen Bereichs /boerse: ohne Menü, ohne Hinweisleisten, ohne Meldungen und ohne Benutzerdaten –
 * auch wenn jemand angemeldet ist. Fußzeile mit Link auf die Datenschutzerklärung.
 *
 * @var string $inhalt
 * @var ?string $datenschutz_url
 * @var bool $noindex
 * @var bool $testumgebung
 */
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!empty($noindex)): ?>
    <meta name="robots" content="noindex">
<?php endif; ?>
    <title><?= e((isset($titel) ? $titel . ' – ' : '') . 'Modellbahnbörse – ' . ($app_name ?? 'EMC 78 Lokschuppen')) ?></title>
    <meta name="theme-color" content="#E30311">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
</head>
<body class="boerse-oeffentlich">
<?php if (!empty($testumgebung)): ?>
<div class="bg-warning text-dark text-center small py-1 px-2" role="status">Testinstanz – hier gibt es nichts zu kaufen, Anfragen werden nicht bearbeitet.</div>
<?php endif; ?>
<header class="emc-navbar navbar navbar-dark">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(url('/boerse')) ?>">
            <span class="navbar-logo"><img src="<?= e(url('assets/marke/logo-128.png')) ?>" alt="" width="36" height="36"></span>
            <span class="boerse-marke"><span class="d-block small fw-normal"><?= e($app_name ?? 'EMC 78 Lokschuppen') ?></span>Modellbahnbörse</span>
        </a>
    </div>
</header>
<main class="container mt-4">
    <?= $inhalt ?>
</main>
<footer class="emc-fuss text-center small py-3">
    Eisenbahn Modellbahn Club 78 e.V. Halle · <span class="slogan">Die Modellbahnfamilie</span>
<?php if (!empty($datenschutz_url)): ?>
    · <a href="<?= e($datenschutz_url) ?>" rel="noopener">Datenschutz</a>
<?php endif; ?>
</footer>
<script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
