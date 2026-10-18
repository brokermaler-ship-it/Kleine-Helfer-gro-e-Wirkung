<?php /** @var list<array<string,mixed>> $personen */ /** @var bool $alle */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Personen</h1>
    <a class="btn btn-primary" href="<?= e(url('/personen/neu')) ?>">Person anlegen</a>
</div>
<p>
    <?php if ($alle): ?>
        <a href="<?= e(url('/personen')) ?>">Nur aktive Personen anzeigen</a>
    <?php else: ?>
        <a href="<?= e(url('/personen', ['alle' => 1])) ?>">Auch inaktive Personen anzeigen</a>
    <?php endif; ?>
</p>
<?php if ($personen === []): ?>
    <p class="text-muted">Keine Personen vorhanden.</p>
<?php else: ?>
    <?php $loeschenForm = function (array $p) use ($csrf): void { ?>
        <form method="post" action="<?= e(url('/personen/' . $p['id'] . '/loeschen')) ?>" class="d-inline"
              onsubmit="return confirm('Person wirklich löschen?');">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
        </form>
    <?php }; ?>
    <div class="d-md-none">
        <?php foreach ($personen as $p): ?>
            <div class="card mb-2">
                <div class="card-body">
                    <h2 class="h6 card-title mb-1"><?= e($p['nachname'] . ', ' . $p['vorname']) ?>
                        <?php if ((int) $p['aktiv'] !== 1): ?><span class="badge text-bg-secondary">inaktiv</span><?php endif; ?></h2>
                    <?php if (!empty($p['email'])): ?><div class="small"><?= e($p['email']) ?></div><?php endif; ?>
                    <?php if (!empty($p['telefon'])): ?><div class="small"><?= e($p['telefon']) ?></div><?php endif; ?>
                    <div class="mt-2">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/personen/' . $p['id'] . '/bearbeiten')) ?>">Bearbeiten</a>
                        <?php $loeschenForm($p); ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover align-middle">
            <thead><tr><th>Name</th><th>E-Mail</th><th>Telefon</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($personen as $p): ?>
                <tr>
                    <td><?= e($p['nachname'] . ', ' . $p['vorname']) ?></td>
                    <td><?= e($p['email']) ?></td>
                    <td><?= e($p['telefon']) ?></td>
                    <td><?= (int) $p['aktiv'] === 1 ? 'aktiv' : 'inaktiv' ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/personen/' . $p['id'] . '/bearbeiten')) ?>">Bearbeiten</a>
                        <?php $loeschenForm($p); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
