<?php
/** @var list<array<string,mixed>> $spurweiten */ /** @var array<string,mixed> $werte */
/** @var array<string,string> $fehler */ /** @var ?int $bearbeiteId */
$feld = function (string $name, string $label, string $typ = 'text', bool $pflicht = false) use ($werte, $fehler): void { ?>
    <div class="col-12 col-md">
        <label for="<?= e($name) ?>" class="form-label"><?= e($label) ?></label>
        <input type="<?= e($typ) ?>" class="form-control<?= isset($fehler[$name]) ? ' is-invalid' : '' ?>" id="<?= e($name) ?>"
               name="<?= e($name) ?>" value="<?= e((string) ($werte[$name] ?? '')) ?>"<?= $pflicht ? ' required' : '' ?>>
        <?php if (isset($fehler[$name])): ?><div class="invalid-feedback"><?= e($fehler[$name]) ?></div><?php endif; ?>
    </div>
<?php }; ?>
<h1 class="h3 mb-3">Spurweiten</h1>
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h6"><?= $bearbeiteId === null ? 'Spurweite anlegen' : 'Spurweite bearbeiten' ?></h2>
        <form method="post" action="<?= e(url('/admin/spurweiten')) ?>" class="row g-2 align-items-start">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php if ($bearbeiteId !== null): ?><input type="hidden" name="id" value="<?= (int) $bearbeiteId ?>"><?php endif; ?>
            <?php $feld('name', 'Name', 'text', true); ?>
            <?php $feld('massstab', 'Maßstab', 'text'); ?>
            <?php $feld('sortierung', 'Sortierung', 'number', true); ?>
            <div class="col-12 col-md-auto align-self-end">
                <button type="submit" class="btn btn-primary">Speichern</button>
                <?php if ($bearbeiteId !== null): ?><a class="btn btn-link" href="<?= e(url('/admin/spurweiten')) ?>">Abbrechen</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead><tr><th>Name</th><th>Maßstab</th><th>Sortierung</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($spurweiten as $s): ?>
            <tr>
                <td><?= e($s['name']) ?></td>
                <td><?= e($s['massstab']) ?></td>
                <td><?= (int) $s['sortierung'] ?></td>
                <td class="text-end">
                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/spurweiten', ['bearbeiten' => $s['id']])) ?>">Bearbeiten</a>
                    <form method="post" action="<?= e(url('/admin/spurweiten/' . $s['id'] . '/loeschen')) ?>" class="d-inline"
                          onsubmit="return confirm('Spurweite wirklich löschen?');">
                        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
