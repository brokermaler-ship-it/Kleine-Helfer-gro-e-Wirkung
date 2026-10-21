<?php
/**
 * Wartungsblock auf Fahrzeug- und Anlagenseiten: Formular „Wartung eintragen“, Verlauf, „Wartungsplan anpassen“.
 *
 * @var array<string,mixed> $block Daten aus WartungDienst::blockDaten (objektTyp, objekt, wartungen, arten, ausnahmen,
 *      personen, darfEintragen, darfPlan, werte, fehler, planWerte, planFehler)
 */
include __DIR__ . '/_daten.php';
?>
<?php if ($darfEintragen) { include __DIR__ . '/_eintragen.php'; } ?>

<h3 class="h6">Wartungsverlauf</h3>
<?php if ($wartungen === []): ?>
    <p class="text-muted">Noch keine Wartungen eingetragen.</p>
<?php else: ?>
    <ul class="list-group mb-4">
        <?php foreach ($wartungen as $w): ?>
            <li class="list-group-item">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <strong><?= e(date('d.m.Y', (int) strtotime($w['datum']))) ?></strong>
                        · <?= e(implode(', ', $w['arten'])) ?>
                        <?php if ($w['betriebsstunden'] !== null): ?> · <?= e($stundenText($w['betriebsstunden'])) ?><?php endif; ?>
                        <div class="small text-muted">ausgeführt von <?= e($w['ausgefuehrt_von'] ?? \Emc\Protokoll\Aenderungsprotokoll::EHEMALIGES_MITGLIED) ?></div>
                    </div>
                    <?php if ($darfEintragen): ?>
                        <form method="post" action="<?= e(url('/wartungen/' . $w['id'] . '/loeschen')) ?>"
                              onsubmit="return confirm('Diese Wartung samt Fotos löschen?');">
                            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if ($w['notiz'] !== null): ?><div class="mt-1"><?= nl2br(e($w['notiz'])) ?></div><?php endif; ?>
                <?php if ($w['fotos'] !== []): ?>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <?php foreach ($w['fotos'] as $f): ?>
                            <a href="<?= e(url('/dateien/' . $f['id'])) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(url('/dateien/' . $f['id'] . '/vorschau')) ?>" alt="<?= e((string) $f['titel']) ?>" loading="lazy" class="rounded border" style="width:96px;height:72px;object-fit:cover">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($darfPlan && $arten !== []): ?>
    <details class="mb-3"<?= $planFehler !== [] ? ' open' : '' ?>>
        <summary class="h6">Wartungsplan anpassen</summary>
        <?php include __DIR__ . '/_plan.php'; ?>
    </details>
<?php endif; ?>
