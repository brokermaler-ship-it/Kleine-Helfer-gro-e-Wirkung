<?php
/**
 * Gesamtliste einer Börse zum Drucken (ohne Menü): Nummer, Bezeichnung, Preis, Herkunft „Verein“/„Mitglied“ – ohne
 * Namen. Zurückgegebene Angebote fehlen; dauerhafte Angebote stehen in einem eigenen Abschnitt.
 *
 * @var array<string,mixed> $boerse
 * @var list<array<string,mixed>> $angebote
 * @var list<array<string,mixed>> $dauerhafte
 */
include __DIR__ . '/_angebot_labels.php';
$sichtbar = fn(array $liste): array => array_values(array_filter($liste, fn(array $a): bool => $a['status'] !== 'zurueck'));
$abschnitte = ['Angebote dieser Börse' => $sichtbar($angebote), 'Dauerhafte Angebote' => $dauerhafte];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($titel ?? 'Gesamtliste') . ' – ' . ($app_name ?? 'EMC 78 Lokschuppen')) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/app.css')) ?>">
</head>
<body class="gesamtliste">
<div class="container py-3">
    <div class="d-print-none d-flex flex-wrap gap-2 align-items-center mb-3">
        <button type="button" class="btn btn-primary" id="liste-drucken">Drucken</button>
        <a class="btn btn-link" href="<?= e(url('/boersen/' . (int) $boerse['id'])) ?>">Zurück zur Börse</a>
    </div>
    <h1 class="h4 mb-1">Gesamtliste <?= e((string) $boerse['name']) ?></h1>
    <p class="text-muted mb-3"><?= e($datum((string) $boerse['datum'])) ?><?= $boerse['ort'] !== null ? ' · ' . e((string) $boerse['ort']) : '' ?> · Stand <?= e(date('d.m.Y H:i')) ?></p>
    <?php foreach ($abschnitte as $ueberschrift => $liste): ?>
        <h2 class="h5 mt-3"><?= e($ueberschrift) ?> (<?= count($liste) ?>)</h2>
        <?php if ($liste === []): ?>
            <p class="text-muted">Keine.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Nummer</th><th>Bezeichnung</th><th class="text-end">Preis</th><th>Herkunft</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($liste as $a): ?>
                        <tr>
                            <td class="fw-bold text-nowrap"><?= e((string) $a['nummer']) ?></td>
                            <td class="text-break"><?= e((string) $a['bezeichnung']) ?><?= (int) $a['menge'] > 1 ? ' (' . (int) $a['menge'] . ' Stück)' : '' ?></td>
                            <td class="text-end text-nowrap"><?= e($preis((string) $a['wunschpreis'])) ?></td>
                            <td><?= e($angebotHerkunft[(string) $a['herkunft']] ?? '') ?></td>
                            <td><?= e($angebotStatus[(string) $a['status']] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<script>
document.getElementById('liste-drucken')?.addEventListener('click', function () { window.print(); });
</script>
</body>
</html>
