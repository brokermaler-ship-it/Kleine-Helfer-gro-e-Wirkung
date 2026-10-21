<?php
/** Formular „Wartung eintragen“ (nach templates/wartung/_daten.php). */
$eintragbar = array_values(array_filter($arten, fn(array $a): bool => !($ausnahmen[$a['id']]['aus'] ?? false)));
$gewaehlt = array_map('strval', is_array($werte['arten'] ?? null) ? $werte['arten'] : []);
$feldFehler = function (string $feld) use ($fehler): string {
    return isset($fehler[$feld]) ? '<div class="invalid-feedback d-block">' . e($fehler[$feld]) . '</div>' : '';
};
$ungueltig = fn(string $feld): string => isset($fehler[$feld]) ? ' is-invalid' : '';
$pre = 'wartung-' . e($objektTyp) . '-';
?>
<form method="post" action="<?= e(url($basisUrl . '/wartungen')) ?>" enctype="multipart/form-data" class="card card-body mb-4" novalidate>
    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
    <h3 class="h6">Wartung eintragen</h3>
    <?php if ($eintragbar === []): ?>
        <p class="text-muted mb-0"><?= $arten !== [] ? 'Alle Wartungsarten sind für dieses Objekt abgeschaltet.' : 'Für dieses Objekt sind keine Wartungsarten vorgesehen.' ?></p>
    <?php else: ?>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <label class="form-label" for="<?= $pre ?>datum">Datum</label>
            <input class="form-control<?= $ungueltig('datum') ?>" type="date" id="<?= $pre ?>datum" name="datum" required
                   max="<?= e((new \DateTimeImmutable('today'))->format('Y-m-d')) ?>" value="<?= e((string) ($werte['datum'] ?? '')) ?>">
            <?= $feldFehler('datum') ?>
        </div>
        <?php if ($objektTyp === 'fahrzeug'): ?>
        <div class="col-12 col-md-4">
            <label class="form-label" for="<?= $pre ?>stunden">Betriebsstunden (optional)</label>
            <input class="form-control<?= $ungueltig('betriebsstunden') ?>" type="text" inputmode="decimal" id="<?= $pre ?>stunden" name="betriebsstunden"
                   value="<?= e((string) ($werte['betriebsstunden'] ?? '')) ?>" placeholder="z. B. 52,5" aria-describedby="<?= $pre ?>stunden-hilfe">
            <div class="form-text" id="<?= $pre ?>stunden-hilfe">Bisher <?= e($stundenText($objekt['betriebsstunden'] ?? 0)) ?>. Ein höherer Stand wird am Fahrzeug übernommen.</div>
            <?= $feldFehler('betriebsstunden') ?>
        </div>
        <?php endif; ?>
        <div class="col-12 col-md-4">
            <label class="form-label" for="<?= $pre ?>person">Ausgeführt von</label>
            <select class="form-select<?= $ungueltig('ausgefuehrt_von') ?>" id="<?= $pre ?>person" name="ausgefuehrt_von" required>
                <option value="">Bitte wählen …</option>
                <?php foreach ($personen as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"<?= (string) $p['id'] === (string) ($werte['ausgefuehrt_von'] ?? '') ? ' selected' : '' ?>><?= e(trim($p['vorname'] . ' ' . $p['nachname'])) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $feldFehler('ausgefuehrt_von') ?>
        </div>
        <fieldset class="col-12">
            <legend class="form-label fs-6">Durchgeführte Wartungen</legend>
            <?php foreach ($eintragbar as $a): ?>
                <div class="form-check">
                    <input class="form-check-input<?= $ungueltig('arten') ?>" type="checkbox" name="arten[]" value="<?= (int) $a['id'] ?>" id="<?= $pre ?>art-<?= (int) $a['id'] ?>"<?= in_array((string) $a['id'], $gewaehlt, true) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="<?= $pre ?>art-<?= (int) $a['id'] ?>"><?= e((string) $a['name']) ?></label>
                </div>
            <?php endforeach; ?>
            <?= $feldFehler('arten') ?>
        </fieldset>
        <div class="col-12">
            <label class="form-label" for="<?= $pre ?>notiz">Notiz (optional)</label>
            <textarea class="form-control<?= $ungueltig('notiz') ?>" id="<?= $pre ?>notiz" name="notiz" rows="2" maxlength="2000"><?= e((string) ($werte['notiz'] ?? '')) ?></textarea>
            <?= $feldFehler('notiz') ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="<?= $pre ?>fotos">Fotos (optional)</label>
            <input class="form-control" type="file" id="<?= $pre ?>fotos" name="fotos[]" multiple accept=".jpg,.jpeg,.png,.webp">
        </div>
    </div>
    <div class="mt-3"><button type="submit" class="btn btn-primary">Wartung eintragen</button></div>
    <?php endif; ?>
</form>
