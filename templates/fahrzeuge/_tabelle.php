<?php
/**
 * Fahrzeugliste (Karten auf dem Handy, Tabelle ab Tablet) für /fahrzeuge und /abstellgleis.
 *
 * @var list<array<string,mixed>> $fahrzeuge
 * @var bool $mitSeit statt Status „seit <Datum>“ zeigen (Abstellgleis)
 * @var callable $titelbild
 * @var callable $adressen
 */
$seit = fn(array $fz): string => ($fz['ausgemustert_am'] ?? null) === null
    ? 'ausgemustert'
    : 'seit ' . e(date('d.m.Y', (int) strtotime((string) $fz['ausgemustert_am'])));
// Abstellgleis: ans Magazin übergebene Fahrzeuge (Magazinartikel nach Fahrzeug-ID; fehlt vor Migration 016).
$magazinInfo ??= [];
$imMagazin = function (array $fz) use ($magazinInfo): string {
    $m = $magazinInfo[(int) $fz['id']] ?? null;
    if ($m === null) {
        return '';
    }
    return $m['bestand'] > 0
        ? ' <span class="badge text-bg-info">im Magazin ' . e($m['code']) . '</span>'
        : ' <span class="badge text-bg-secondary">abgegeben (' . e($m['code']) . ')</span>';
};
?>
<?php if ($fahrzeuge === []): ?>
    <p class="text-muted">Keine Fahrzeuge gefunden.</p>
<?php else: ?>
    <div class="d-md-none">
        <?php foreach ($fahrzeuge as $fz): ?>
            <div class="card mb-2">
                <div class="card-body d-flex gap-3">
                    <?= $titelbild($fz) ?>
                    <div class="flex-grow-1">
                        <h2 class="h6 mb-1"><a class="stretched-link text-reset text-decoration-none" href="<?= e(url('/fahrzeuge/' . $fz['id'])) ?>"><?= e($fz['bezeichnung']) ?></a></h2>
                        <div class="small text-muted"><?= e($fz['inventarnummer']) ?> · <?= e($fz['spurweite_name']) ?></div>
                        <div class="small">Adresse: <?= $adressen($fz) ?></div>
                        <div class="mt-1">
                            <?= $mitSeit ? '<span class="small">' . $seit($fz) . '</span>' . $imMagazin($fz) : $statusBadge($fz) ?>
                            <?= $eigentumBadge($fz) ?>
                        </div>
                        <?php if (!$mitSeit): ?>
                            <a class="btn btn-sm btn-outline-danger text-nowrap position-relative z-2 mt-2 emc-tippziel" href="<?= e(url('/fahrzeuge/' . $fz['id'] . '/defekt')) ?>"
                               aria-label="Defekt melden: <?= e($fz['bezeichnung']) ?>">Defekt melden</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle">
            <thead><tr><th></th><th>Nummer</th><th>Bezeichnung</th><th>Spurweite</th><th>Adresse(n)</th><th>Eigentum</th><th><?= $mitSeit ? 'Abgestellt' : 'Status' ?></th><?php if (!$mitSeit): ?><th><span class="visually-hidden">Aktionen</span></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($fahrzeuge as $fz): ?>
                <tr>
                    <td><?= $titelbild($fz) ?></td>
                    <td><a href="<?= e(url('/fahrzeuge/' . $fz['id'])) ?>"><?= e($fz['inventarnummer']) ?></a></td>
                    <td><?= e($fz['bezeichnung']) ?></td>
                    <td><?= e($fz['spurweite_name']) ?></td>
                    <td><?= $adressen($fz) ?></td>
                    <td><?= $eigentumBadge($fz) ?></td>
                    <td><?= $mitSeit ? $seit($fz) . $imMagazin($fz) : $statusBadge($fz) ?></td>
                    <?php if (!$mitSeit): ?>
                        <td class="text-end"><a class="btn btn-sm btn-outline-danger text-nowrap" href="<?= e(url('/fahrzeuge/' . $fz['id'] . '/defekt')) ?>"
                               aria-label="Defekt melden: <?= e($fz['bezeichnung']) ?>">Defekt melden</a></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted small"><?= count($fahrzeuge) ?> Fahrzeug<?= count($fahrzeuge) === 1 ? '' : 'e' ?></p>
<?php endif; ?>
