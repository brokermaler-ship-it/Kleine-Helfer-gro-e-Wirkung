<?php
/**
 * Wartungsblock auf Fahrzeug- und Anlagenseiten: Fälligkeiten mit Ampel, Formular „Wartung eintragen“, Verlauf, „Wartungsplan anpassen“.
 *
 * @var array<string,mixed> $block Daten aus WartungDienst::blockDaten (objektTyp, objekt, wartungen, arten, ausnahmen,
 *      plan, ausgemustert, personen, darfEintragen, darfPlan, werte, fehler, planWerte, planFehler)
 */
include __DIR__ . '/_daten.php';
?>
<h3 class="h6">Fälligkeiten</h3>
<?php if ($ausgemustert): ?>
    <p class="text-muted">Ausgemusterte Fahrzeuge werden im Wartungsplan nicht berücksichtigt.</p>
<?php elseif ($plan === [] && $arten !== []): ?>
    <p class="text-muted">Alle Wartungsarten sind für dieses Objekt abgeschaltet.</p>
<?php elseif ($plan === []): ?>
    <p class="text-muted">Für dieses Objekt sind keine Wartungsarten vorgesehen.</p>
<?php else: ?>
    <div class="table-responsive mb-4">
        <table class="table table-sm align-middle wartung-faelligkeiten">
            <thead>
            <tr>
                <th scope="col">Wartungsart</th>
                <th scope="col">Ampel</th>
                <th scope="col">Intervall</th>
                <th scope="col">Letzte Wartung</th>
                <th scope="col">Fällig am / bei</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($plan as $pz): $faelligkeit = $pz['faelligkeit']; ?>
                <tr>
                    <td><?= e((string) $pz['art']['name']) ?></td>
                    <td><?php include __DIR__ . '/_ampel.php'; ?></td>
                    <td><?= e(\Emc\Wartung\WartungsartValidator::intervallText($pz['monate'], $pz['stunden'])) ?>
                        <?php if ($pz['eigenes_intervall']): ?><span class="badge text-bg-light border">eigenes Intervall</span><?php endif; ?></td>
                    <td><?php if ($pz['letzte'] === null): ?>–<?php else: ?><?= e(date('d.m.Y', (int) strtotime($pz['letzte']['datum']))) ?><?php if ($pz['letzte']['stundenstand'] !== null): ?> · <?= e($stundenText($pz['letzte']['stundenstand'])) ?><?php endif; ?><?php endif; ?></td>
                    <td><?php include __DIR__ . '/_faellig.php'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

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
                            <div class="text-center">
                                <a href="<?= e(url('/dateien/' . $f['id'])) ?>" target="_blank" rel="noopener">
                                    <img src="<?= e(url('/dateien/' . $f['id'] . '/vorschau')) ?>" alt="<?= e((string) $f['titel']) ?>" loading="lazy" class="rounded border" style="width:96px;height:72px;object-fit:cover">
                                </a>
                                <?php if ($darfEintragen): ?>
                                    <form method="post" action="<?= e(url('/dateien/' . $f['id'] . '/loeschen')) ?>"
                                          onsubmit="return confirm('Dieses Foto löschen?');">
                                        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                                        <button type="submit" class="btn btn-link btn-sm text-danger p-0">Foto löschen<span class="visually-hidden">: <?= e((string) $f['titel']) ?></span></button>
                                    </form>
                                <?php endif; ?>
                            </div>
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
