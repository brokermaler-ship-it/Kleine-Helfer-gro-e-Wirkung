<?php
/** @var list<array<string,mixed>> $arten */ /** @var array<string,mixed> $werte */
/** @var array<string,string> $fehler */ /** @var ?int $bearbeiteId */
use Emc\Wartung\GiltFuer;
use Emc\Wartung\WartungsartValidator;

$feld = function (string $name, string $label, string $typ = 'text', bool $pflicht = false, string $extra = '', string $hilfe = '') use ($werte, $fehler): void { ?>
    <div class="col-12 col-md">
        <label for="<?= e($name) ?>" class="form-label"><?= e($label) ?></label>
        <input type="<?= e($typ) ?>" class="form-control<?= isset($fehler[$name]) ? ' is-invalid' : '' ?>" id="<?= e($name) ?>"
               name="<?= e($name) ?>" value="<?= e((string) ($werte[$name] ?? '')) ?>"<?= $pflicht ? ' required' : '' ?><?= $extra ?>
               <?= $hilfe !== '' ? 'aria-describedby="' . e($name) . '_hilfe"' : '' ?>>
        <?php if (isset($fehler[$name])): ?><div class="invalid-feedback"><?= e($fehler[$name]) ?></div><?php endif; ?>
        <?php if ($hilfe !== ''): ?><div class="form-text" id="<?= e($name) ?>_hilfe"><?= e($hilfe) ?></div><?php endif; ?>
    </div>
<?php }; ?>
<h1 class="h3 mb-3">Wartungsarten</h1>
<p class="text-muted">Eine Wartungsart gilt automatisch für alle passenden Fahrzeuge bzw. Anlagen. Fällig ist, was zuerst erreicht wird – Monate oder Betriebsstunden.</p>
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h6"><?= $bearbeiteId === null ? 'Wartungsart anlegen' : 'Wartungsart bearbeiten' ?></h2>
        <form method="post" action="<?= e(url('/admin/wartungsarten')) ?>" class="row g-2 align-items-start">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php if ($bearbeiteId !== null): ?><input type="hidden" name="id" value="<?= (int) $bearbeiteId ?>"><?php endif; ?>
            <?php $feld('name', 'Name', 'text', true, ' maxlength="' . WartungsartValidator::NAME_MAX . '"'); ?>
            <div class="col-12 col-md">
                <label for="gilt_fuer" class="form-label">Gilt für</label>
                <select class="form-select<?= isset($fehler['gilt_fuer']) ? ' is-invalid' : '' ?>" id="gilt_fuer" name="gilt_fuer" required>
                    <option value="">Bitte wählen …</option>
                    <?php foreach (GiltFuer::cases() as $g): ?>
                        <option value="<?= e($g->value) ?>"<?= ($werte['gilt_fuer'] ?? '') === $g->value ? ' selected' : '' ?>><?= e($g->label()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($fehler['gilt_fuer'])): ?><div class="invalid-feedback"><?= e($fehler['gilt_fuer']) ?></div><?php endif; ?>
            </div>
            <?php $feld('intervall_monate', 'Intervall Monate', 'number', false, ' min="1" max="999" step="1"'); ?>
            <?php $feld('intervall_stunden', 'Intervall Betriebsstunden', 'number', false, ' min="1" max="999" step="1"', 'Nicht für Anlagen.'); ?>
            <?php $feld('sortierung', 'Sortierung', 'number', false, ' step="1"'); ?>
            <div class="col-12 col-md-auto align-self-center pt-md-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="aktiv" name="aktiv" value="1"<?= !empty($werte['aktiv']) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="aktiv">aktiv</label>
                </div>
            </div>
            <div class="col-12 col-md-auto align-self-end">
                <button type="submit" class="btn btn-primary">Speichern</button>
                <?php if ($bearbeiteId !== null): ?><a class="btn btn-link" href="<?= e(url('/admin/wartungsarten')) ?>">Abbrechen</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead><tr><th>Name</th><th>Gilt für</th><th>Intervall</th><th>Status</th><th>Sortierung</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($arten as $a): ?>
            <tr<?= $a['aktiv'] === 1 ? '' : ' class="text-muted"' ?>>
                <td><?= e($a['name']) ?></td>
                <td><?= e(GiltFuer::tryFrom((string) $a['gilt_fuer'])?->label() ?? (string) $a['gilt_fuer']) ?></td>
                <td><?= e(WartungsartValidator::intervallText($a['intervall_monate'], $a['intervall_stunden'])) ?></td>
                <td><?php if ($a['aktiv'] === 1): ?><span class="badge text-bg-success">aktiv</span><?php else: ?><span class="badge text-bg-secondary">inaktiv</span><?php endif; ?></td>
                <td><?= (int) $a['sortierung'] ?></td>
                <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/wartungsarten', ['bearbeiten' => $a['id']])) ?>">Bearbeiten</a>
                    <form method="post" action="<?= e(url('/admin/wartungsarten/' . $a['id'] . '/loeschen')) ?>" class="d-inline"
                          onsubmit="return confirm('Wartungsart wirklich löschen?');">
                        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
