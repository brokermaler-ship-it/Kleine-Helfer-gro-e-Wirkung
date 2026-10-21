<?php
/** Formular „Wartungsplan anpassen“ (nach templates/wartung/_daten.php). */
$modusLabels = ['standard' => 'Standard', 'aus' => 'abgeschaltet', 'eigen' => 'eigenes Intervall'];
$pre = 'plan-' . e($objektTyp) . '-';
?>
<form method="post" action="<?= e(url($basisUrl . '/wartungsplan')) ?>" class="card card-body" novalidate>
    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
    <p class="small text-muted">Standard: Intervall der Wartungsart. Abgeschaltet: die Wartungsart entfällt für dieses Objekt.
        Eigenes Intervall: ersetzt beide Werte der Wartungsart (leer = entfällt).</p>
    <?php foreach ($arten as $a):
        $id = (int) $a['id'];
        $aus = $ausnahmen[$id] ?? null;
        $modus = (string) ($planWerte['modus'][$id] ?? ($aus === null ? 'standard' : ($aus['aus'] ? 'aus' : 'eigen')));
        $monate = (string) ($planWerte['monate'][$id] ?? ($aus !== null && !$aus['aus'] ? (string) ($aus['intervall_monate'] ?? '') : ''));
        $stunden = (string) ($planWerte['stunden'][$id] ?? ($aus !== null && !$aus['aus'] ? (string) ($aus['intervall_stunden'] ?? '') : ''));
        ?>
        <fieldset class="border-bottom pb-2 mb-2">
            <legend class="fs-6 fw-semibold mb-1"><?= e((string) $a['name']) ?>
                <span class="fw-normal text-muted small">(Standard: <?= e(\Emc\Wartung\WartungsartValidator::intervallText($a['intervall_monate'], $objektTyp === 'anlage' ? null : $a['intervall_stunden'])) ?>)</span>
            </legend>
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label small" for="<?= $pre ?>modus-<?= $id ?>">Einstellung</label>
                    <select class="form-select form-select-sm<?= isset($planFehler[$id]) ? ' is-invalid' : '' ?>" id="<?= $pre ?>modus-<?= $id ?>" name="modus[<?= $id ?>]">
                        <?php foreach ($modusLabels as $wert => $text): ?>
                            <option value="<?= e($wert) ?>"<?= $wert === $modus ? ' selected' : '' ?>><?= e($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="<?= $pre ?>monate-<?= $id ?>">Monate</label>
                    <input class="form-control form-control-sm" type="number" min="1" max="999" id="<?= $pre ?>monate-<?= $id ?>" name="monate[<?= $id ?>]" value="<?= e($monate) ?>">
                </div>
                <?php if ($objektTyp === 'fahrzeug'): ?>
                <div class="col-6 col-md-3">
                    <label class="form-label small" for="<?= $pre ?>stunden-<?= $id ?>">Stunden</label>
                    <input class="form-control form-control-sm" type="number" min="1" max="999" id="<?= $pre ?>stunden-<?= $id ?>" name="stunden[<?= $id ?>]" value="<?= e($stunden) ?>">
                </div>
                <?php endif; ?>
            </div>
            <?php if (isset($planFehler[$id])): ?><div class="invalid-feedback d-block"><?= e($planFehler[$id]) ?></div><?php endif; ?>
        </fieldset>
    <?php endforeach; ?>
    <div class="mt-2"><button type="submit" class="btn btn-outline-primary btn-sm">Wartungsplan speichern</button></div>
</form>
