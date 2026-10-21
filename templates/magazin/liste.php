<?php
/**
 * Magazinliste mit Filtern; am Handy als Karten, ab md als Tabelle.
 *
 * @var \Emc\Magazin\MagazinFilter $filter
 * @var list<array<string,mixed>> $artikel aus MagazinRepository::suche
 * @var list<array<string,mixed>> $kategorien
 * @var list<array<string,mixed>> $spurweiten
 * @var list<string> $lagerorte
 * @var bool $istBoersenwart
 */
include __DIR__ . '/_labels.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Magazin</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('/magazin/sammlungen')) ?>">Sammlungen</a>
        <?php if ($istBoersenwart): ?>
            <a class="btn btn-primary" href="<?= e(url('/magazin/neu')) ?>">+ Artikel anlegen</a>
        <?php endif; ?>
    </div>
</div>

<button class="btn btn-outline-secondary d-md-none mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterleiste"
        aria-expanded="<?= $filter->istGesetzt() ? 'true' : 'false' ?>" aria-controls="filterleiste">Filter</button>
<form method="get" action="<?= e(url('/magazin')) ?>" id="filterleiste" class="collapse d-md-block card card-body mb-3<?= $filter->istGesetzt() ? ' show' : '' ?>">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label for="f-q" class="form-label small mb-0">Suche</label>
            <input type="search" class="form-control" id="f-q" name="q" value="<?= e($filter->text ?? '') ?>" placeholder="Code, Bezeichnung, Hersteller, ISBN …">
        </div>
        <div class="col-6 col-md-2">
            <label for="f-kategorie" class="form-label small mb-0">Kategorie</label>
            <select class="form-select" id="f-kategorie" name="kategorie">
                <option value="">alle</option>
                <?php foreach ($kategorien as $k): ?>
                    <option value="<?= (int) $k['id'] ?>"<?= $filter->kategorieId === (int) $k['id'] ? ' selected' : '' ?>><?= e((string) $k['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-spurweite" class="form-label small mb-0">Spurweite</label>
            <select class="form-select" id="f-spurweite" name="spurweite">
                <option value="">alle</option>
                <?php foreach ($spurweiten as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= $filter->spurweiteId === (int) $s['id'] ? ' selected' : '' ?>><?= e((string) $s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2">
            <label for="f-lagerort" class="form-label small mb-0">Lagerort</label>
            <select class="form-select" id="f-lagerort" name="lagerort">
                <option value="">alle</option>
                <?php foreach ($lagerorte as $ort): ?>
                    <option value="<?= e($ort) ?>"<?= $filter->lagerort === $ort ? ' selected' : '' ?>><?= e($ort) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="f-alle" name="alle" value="1"<?= $filter->nurMitBestand ? '' : ' checked' ?>>
                <label class="form-check-label small" for="f-alle">auch ohne Bestand</label>
            </div>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Filtern</button>
            <?php if ($filter->istGesetzt()): ?><a class="btn btn-link" href="<?= e(url('/magazin')) ?>">Zurücksetzen</a><?php endif; ?>
        </div>
    </div>
</form>

<?php if ($artikel === []): ?>
    <p class="text-muted"><?= $filter->istGesetzt() ? 'Keine Artikel gefunden.' : 'Noch keine Artikel mit Bestand im Magazin.' ?></p>
<?php else: ?>
    <p class="small text-muted mb-2"><?= count($artikel) === 1 ? '1 Artikel' : count($artikel) . ' Artikel' ?></p>
    <div class="d-md-none list-group">
        <?php foreach ($artikel as $a): ?>
            <a class="list-group-item list-group-item-action d-flex gap-3 align-items-start" href="<?= e(url('/magazin/' . (int) $a['id'])) ?>">
                <?= $magazinTitelbild($a) ?>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="fw-semibold text-break"><span class="fw-bold me-1"><?= e((string) $a['code']) ?></span> <?= e((string) $a['bezeichnung']) ?></div>
                        <div class="text-nowrap" title="Bestand"><?= $magazinBestandBadge((int) $a['bestand']) ?></div>
                    </div>
                    <div class="small text-muted"><?= e((string) $a['kategorie_name']) ?><?= $a['lagerort'] !== null ? ' · ' . e((string) $a['lagerort']) : '' ?></div>
                    <div class="mt-1"><?= $magazinZustandBadge((string) $a['zustand']) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle">
            <thead><tr><th><span class="visually-hidden">Foto</span></th><th>Code</th><th>Bezeichnung</th><th>Kategorie</th><th>Zustand</th><th class="text-end">Bestand</th><th>Lagerort</th></tr></thead>
            <tbody>
            <?php foreach ($artikel as $a): ?>
                <tr>
                    <td><?= $magazinTitelbild($a) ?></td>
                    <td class="fw-bold text-nowrap"><?= e((string) $a['code']) ?></td>
                    <td><a href="<?= e(url('/magazin/' . (int) $a['id'])) ?>"><?= e((string) $a['bezeichnung']) ?></a></td>
                    <td><?= e((string) $a['kategorie_name']) ?></td>
                    <td><?= $magazinZustandBadge((string) $a['zustand']) ?></td>
                    <td class="text-end"><?= $magazinBestandBadge((int) $a['bestand']) ?></td>
                    <td><?= $a['lagerort'] === null ? '–' : e((string) $a['lagerort']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
