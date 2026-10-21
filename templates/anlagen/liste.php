<?php
/** @var list<array<string,mixed>> $anlagen */ /** @var list<array<string,mixed>> $spurweiten */
/** @var ?int $filterSpurweite */ /** @var string $filterText */
include __DIR__ . '/_labels.php';
$gefiltert = $filterSpurweite !== null || $filterText !== '';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Anlagen</h1>
    <?php if ($darf(\Emc\Auth\Berechtigung::ANLAGE_BEARBEITEN)): ?>
        <a class="btn btn-primary" href="<?= e(url('/anlagen/neu')) ?>">+ Anlage anlegen</a>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/anlagen')) ?>" class="card card-body mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6">
            <label for="q" class="form-label small mb-0">Suche</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($filterText) ?>" placeholder="Code, Name, Standort …">
        </div>
        <div class="col-8 col-md-3">
            <label for="f-spurweite" class="form-label small mb-0">Spurweite</label>
            <select class="form-select" id="f-spurweite" name="spurweite">
                <option value="">alle</option>
                <?php foreach ($spurweiten as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= $filterSpurweite === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-4 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary">Suchen</button>
            <?php if ($gefiltert): ?><a class="btn btn-link" href="<?= e(url('/anlagen')) ?>">Zurücksetzen</a><?php endif; ?>
        </div>
    </div>
</form>

<?php if ($anlagen === []): ?>
    <p class="text-muted"><?= $gefiltert ? 'Keine Anlagen gefunden.' : 'Noch keine Anlagen erfasst.' ?></p>
<?php else: ?>
    <div class="d-md-none">
        <?php foreach ($anlagen as $a): ?>
            <a class="card mb-2 text-decoration-none text-reset" href="<?= e(url('/anlagen/' . $a['id'])) ?>">
                <div class="card-body d-flex gap-3">
                    <?= $anlageTitelbild($a) ?>
                    <div class="flex-grow-1">
                        <h2 class="h6 mb-1"><span class="fw-bold me-1"><?= e($a['code']) ?></span> <?= e($a['name']) ?></h2>
                        <div class="small text-muted"><?= e($a['spurweite_name']) ?><?= $a['standort'] !== null ? ' · ' . e($a['standort']) : '' ?></div>
                        <div class="mt-1"><?= $anlageStatusBadge($a) ?></div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle">
            <thead><tr><th>Code</th><th>Name</th><th>Spurweite</th><th>Standort</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($anlagen as $a): ?>
                <tr>
                    <td class="fw-bold"><?= e($a['code']) ?></td>
                    <td><a href="<?= e(url('/anlagen/' . $a['id'])) ?>"><?= e($a['name']) ?></a></td>
                    <td><?= e($a['spurweite_name']) ?></td>
                    <td><?= $a['standort'] === null ? '–' : e($a['standort']) ?></td>
                    <td><?= $anlageStatusBadge($a) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
