<?php
/**
 * Verwaltung › Magazin-Kategorien.
 *
 * @var list<array<string,mixed>> $kategorien aus MagazinRepository::kategorien (mit anzahl)
 * @var array<string,string> $werte
 * @var array<string,string> $fehler
 * @var ?int $bearbeiteId
 */
$feld = function (string $name, string $label, string $typ = 'text', bool $pflicht = false, string $extra = '') use ($werte, $fehler): void { ?>
    <div class="col-12 col-md">
        <label for="<?= e($name) ?>" class="form-label"><?= e($label) ?></label>
        <input type="<?= e($typ) ?>" class="form-control<?= isset($fehler[$name]) ? ' is-invalid' : '' ?>" id="<?= e($name) ?>"
               name="<?= e($name) ?>" value="<?= e((string) ($werte[$name] ?? '')) ?>"<?= $pflicht ? ' required' : '' ?><?= $extra ?>>
        <?php if (isset($fehler[$name])): ?><div class="invalid-feedback"><?= e($fehler[$name]) ?></div><?php endif; ?>
    </div>
<?php }; ?>
<h1 class="h3 mb-3">Magazin-Kategorien</h1>
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h6"><?= $bearbeiteId === null ? 'Kategorie anlegen' : 'Kategorie umbenennen' ?></h2>
        <form method="post" action="<?= e(url('/admin/magazin-kategorien')) ?>" class="row g-2 align-items-start">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php if ($bearbeiteId !== null): ?><input type="hidden" name="id" value="<?= (int) $bearbeiteId ?>"><?php endif; ?>
            <?php $feld('name', 'Name', 'text', true, ' maxlength="80"'); ?>
            <?php $feld('sortierung', 'Sortierung', 'number', true); ?>
            <div class="col-12 col-md-auto align-self-end">
                <button type="submit" class="btn btn-primary">Speichern</button>
                <?php if ($bearbeiteId !== null): ?><a class="btn btn-link" href="<?= e(url('/admin/magazin-kategorien')) ?>">Abbrechen</a><?php endif; ?>
            </div>
        </form>
        <p class="form-text mb-0 mt-2">Fahrzeuge, Literatur und Sonstiges braucht das Programm: sie lassen sich umbenennen, aber nicht löschen. Benutzte Kategorien bleiben erhalten.</p>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead><tr><th>Name</th><th class="text-end">Artikel</th><th class="d-none d-sm-table-cell">Sortierung</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($kategorien as $k): ?>
            <tr>
                <td><?= e((string) $k['name']) ?><?php if ($k['schluessel'] !== null): ?> <span class="badge text-bg-light border fw-normal">fest</span><?php endif; ?></td>
                <td class="text-end"><?= (int) $k['anzahl'] ?></td>
                <td class="d-none d-sm-table-cell"><?= (int) $k['sortierung'] ?></td>
                <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/magazin-kategorien', ['bearbeiten' => $k['id']])) ?>">Bearbeiten</a>
                    <?php if ($k['schluessel'] === null && (int) $k['anzahl'] === 0): ?>
                        <form method="post" action="<?= e(url('/admin/magazin-kategorien/' . $k['id'] . '/loeschen')) ?>" class="d-inline"
                              onsubmit="return confirm('Kategorie wirklich löschen?');">
                            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
