<?php
/**
 * @var list<array<string,mixed>> $spurweiten
 * @var ?array<string,mixed> $aktuell
 * @var list<array<string,mixed>> $zeilen
 */
?>
<h1 class="h3 mb-3">Adressübersicht</h1>
<form method="get" action="<?= e(url('/adressen')) ?>" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label for="spurweite" class="form-label">Spurweite</label>
        <select class="form-select" id="spurweite" name="spurweite" data-auto-submit>
            <?php foreach ($spurweiten as $s): ?>
                <option value="<?= e((string) $s['id']) ?>"<?= $aktuell !== null && (int) $s['id'] === (int) $aktuell['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button type="submit" class="btn btn-outline-primary">Anzeigen</button></div>
</form>
<?php if ($zeilen === []): ?>
    <p class="text-muted">Für diese Spurweite sind keine Decoder-Adressen erfasst.</p>
<?php else: ?>
    <p class="text-muted small">Ausgemusterte Fahrzeuge sind nicht enthalten. Doppelt vergebene Adressen sind markiert.</p>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr><th>Protokoll</th><th>Adresse</th><th>Art</th><th>Fahrzeug</th><th>Eigentümer</th></tr>
            </thead>
            <tbody>
                <?php foreach ($zeilen as $z): ?>
                    <tr<?= $z['doppelt'] ? ' class="table-warning"' : '' ?>>
                        <td><?= e($z['protokoll']->label()) ?></td>
                        <td><?php if ($z['doppelt']): ?><span title="Adresse mehrfach vergeben" aria-label="Adresse mehrfach vergeben">⚠️</span> <?php endif; ?><?= e((string) $z['adresse']) ?></td>
                        <td><?= e($z['adressart'] ?? '–') ?></td>
                        <td><a href="<?= e(url('/fahrzeuge/' . $z['fahrzeug_id'])) ?>"><?= e($z['inventarnummer']) ?></a> <?= e($z['bezeichnung']) ?></td>
                        <td><?= e($z['eigentuemer_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
