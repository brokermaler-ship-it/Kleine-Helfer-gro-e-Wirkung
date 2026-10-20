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
?>
<?php if ($fahrzeuge === []): ?>
    <p class="text-muted">Keine Fahrzeuge gefunden.</p>
<?php else: ?>
    <div class="d-md-none">
        <?php foreach ($fahrzeuge as $fz): ?>
            <a class="card mb-2 text-decoration-none text-reset" href="<?= e(url('/fahrzeuge/' . $fz['id'])) ?>">
                <div class="card-body d-flex gap-3">
                    <?= $titelbild($fz) ?>
                    <div class="flex-grow-1">
                        <h2 class="h6 mb-1"><?= e($fz['bezeichnung']) ?></h2>
                        <div class="small text-muted"><?= e($fz['inventarnummer']) ?> · <?= e($fz['spurweite_name']) ?></div>
                        <div class="small">Adresse: <?= $adressen($fz) ?></div>
                        <div class="mt-1">
                            <?= $mitSeit ? '<span class="small">' . $seit($fz) . '</span>' : $statusBadge($fz) ?>
                            <?= $eigentumBadge($fz) ?>
                        </div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle">
            <thead><tr><th></th><th>Nummer</th><th>Bezeichnung</th><th>Spurweite</th><th>Adresse(n)</th><th>Eigentum</th><th><?= $mitSeit ? 'Abgestellt' : 'Status' ?></th></tr></thead>
            <tbody>
            <?php foreach ($fahrzeuge as $fz): ?>
                <tr>
                    <td><?= $titelbild($fz) ?></td>
                    <td><a href="<?= e(url('/fahrzeuge/' . $fz['id'])) ?>"><?= e($fz['inventarnummer']) ?></a></td>
                    <td><?= e($fz['bezeichnung']) ?></td>
                    <td><?= e($fz['spurweite_name']) ?></td>
                    <td><?= $adressen($fz) ?></td>
                    <td><?= $eigentumBadge($fz) ?></td>
                    <td><?= $mitSeit ? $seit($fz) : $statusBadge($fz) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted small"><?= count($fahrzeuge) ?> Fahrzeug<?= count($fahrzeuge) === 1 ? '' : 'e' ?></p>
<?php endif; ?>
