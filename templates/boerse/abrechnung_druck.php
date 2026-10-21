<?php
/**
 * Druckansicht der Abrechnung (ohne Menü, ohne Formulare): Verein, Nach Zahlart, Mitglieder mit Auszahlung und Feld
 * „ausgezahlt am“ zum Abzeichnen. Druck aus dem Browser, keine externe Bibliothek.
 *
 * @var array<string,mixed> $boerse
 * @var array<string,mixed> $abrechnung
 * @var array<int, array{betrag:string, ausgezahlt_am:string}> $auszahlungen
 * @var string $heute
 */
include __DIR__ . '/_angebot_labels.php';
$mitFormularen = false;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($titel ?? 'Abrechnung') . ' – ' . ($app_name ?? 'EMC 78 Lokschuppen')) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
    <?php /* Nur auf dieser Seite: A4 mit Rand (sonst gälte es für jeden Ausdruck der App). */ ?>
    <style>@page { size: A4; margin: 15mm 12mm; }</style>
</head>
<body class="abrechnung-druck">
<div class="container py-3">
    <div class="d-print-none d-flex flex-wrap gap-2 align-items-center mb-3">
        <button type="button" class="btn btn-primary" id="abrechnung-drucken">Drucken</button>
        <a class="btn btn-link" href="<?= e(url('/boersen/' . (int) $boerse['id'] . '/abrechnung')) ?>">Zurück zur Abrechnung</a>
    </div>
    <h1 class="h4 mb-1">Abrechnung <?= e((string) $boerse['name']) ?></h1>
    <p class="text-muted mb-3"><?= e($datum((string) $boerse['datum'])) ?><?= $boerse['ort'] !== null ? ' · ' . e((string) $boerse['ort']) : '' ?> · Provision <?= e($provision((string) $boerse['provision_prozent'])) ?> · <?= e($boerseStatusText[(string) $boerse['status']] ?? '') ?> · Stand <?= e(date('d.m.Y H:i')) ?></p>
    <?php include __DIR__ . '/_abrechnung_tabellen.php'; ?>
    <p class="mt-4 mb-0 abrechnung-unterschrift">Geprüft: ______________________________ &nbsp; Datum: ____________</p>
</div>
<script>
document.getElementById('abrechnung-drucken')?.addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
