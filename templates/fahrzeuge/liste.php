<?php
/** @var list<array<string,mixed>> $fahrzeuge */ /** @var \Emc\Fahrzeuge\FahrzeugFilter $filter */
/** @var list<array<string,mixed>> $spurweiten */ /** @var list<array<string,mixed>> $personen */
include __DIR__ . '/_labels.php';
$titelbild = fn(array $fz): string => $fz['titelbild_dokument_id'] === null
    ? '<div class="fahrzeug-bild fahrzeug-bild-leer" aria-hidden="true">&#128642;</div>'
    : '<img class="fahrzeug-bild" alt="" loading="lazy" src="' . e(url('/dateien/' . $fz['titelbild_dokument_id'] . '/vorschau')) . '">';
$adressen = fn(array $fz): string => $fz['adressen'] === null ? '–' : e((string) $fz['adressen']);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Fahrzeuge</h1>
    <?php if ($darf('fahrzeug_bearbeiten')): ?>
        <a class="btn btn-primary" href="<?= e(url('/fahrzeuge/neu')) ?>">Fahrzeug anlegen</a>
    <?php endif; ?>
</div>

<button class="btn btn-outline-secondary d-md-none mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterleiste"
        aria-expanded="<?= $filter->istGesetzt() ? 'true' : 'false' ?>" aria-controls="filterleiste">Suche und Filter</button>
<form method="get" action="<?= e(url('/fahrzeuge')) ?>" id="filterleiste" class="collapse d-md-block card card-body mb-3<?= $filter->istGesetzt() ? ' show' : '' ?>">
    <div class="row g-2">
        <div class="col-12 col-md-4">
            <label for="q" class="form-label small mb-0">Suche</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e((string) $filter->text) ?>" placeholder="Nummer, Bezeichnung, Baureihe …">
        </div>
        <div class="col-6 col-md-2">
            <label for="f-spurweite" class="form-label small mb-0">Spurweite</label>
            <select class="form-select" id="f-spurweite" name="spurweite">
                <option value="">alle</option>
                <?php foreach ($spurweiten as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= $filter->spurweiteId === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-gattung" class="form-label small mb-0">Gattung</label>
            <select class="form-select" id="f-gattung" name="gattung">
                <option value="">alle</option>
                <?php foreach ($gattungen as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $filter->gattung === $wert ? ' selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-status" class="form-label small mb-0">Status</label>
            <select class="form-select" id="f-status" name="status">
                <option value="">ohne ausgemusterte</option>
                <?php foreach ($statusLabels as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $filter->status === $wert ? ' selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-eigentuemer" class="form-label small mb-0">Eigentum</label>
            <select class="form-select" id="f-eigentuemer" name="eigentuemer">
                <option value="">alle</option>
                <option value="verein"<?= $filter->eigentuemer === 'verein' ? ' selected' : '' ?>>Verein</option>
                <option value="mitglied"<?= $filter->eigentuemer === 'mitglied' ? ' selected' : '' ?>>Mitglieder (extern)</option>
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label for="f-person" class="form-label small mb-0">Eigentümer</label>
            <select class="form-select" id="f-person" name="person">
                <option value="">alle</option>
                <?php foreach ($personen as $pe): ?>
                    <option value="<?= (int) $pe['id'] ?>"<?= $filter->personId === (int) $pe['id'] ? ' selected' : '' ?>><?= e($pe['nachname'] . ', ' . $pe['vorname']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-8 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-primary">Suchen</button>
            <a class="btn btn-link" href="<?= e(url('/fahrzeuge')) ?>">Zurücksetzen</a>
        </div>
    </div>
</form>

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
                            <span class="badge text-bg-<?= e($statusKlassen[$fz['status']] ?? 'secondary') ?>"><?= e($statusLabels[$fz['status']] ?? $fz['status']) ?></span>
                            <?= $eigentumBadge($fz) ?>
                        </div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle">
            <thead><tr><th></th><th>Nummer</th><th>Bezeichnung</th><th>Spurweite</th><th>Adresse(n)</th><th>Eigentum</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($fahrzeuge as $fz): ?>
                <tr>
                    <td><?= $titelbild($fz) ?></td>
                    <td><a href="<?= e(url('/fahrzeuge/' . $fz['id'])) ?>"><?= e($fz['inventarnummer']) ?></a></td>
                    <td><?= e($fz['bezeichnung']) ?></td>
                    <td><?= e($fz['spurweite_name']) ?></td>
                    <td><?= $adressen($fz) ?></td>
                    <td><?= $eigentumBadge($fz) ?></td>
                    <td><span class="badge text-bg-<?= e($statusKlassen[$fz['status']] ?? 'secondary') ?>"><?= e($statusLabels[$fz['status']] ?? $fz['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted small"><?= count($fahrzeuge) ?> Fahrzeug<?= count($fahrzeuge) === 1 ? '' : 'e' ?></p>
<?php endif; ?>
