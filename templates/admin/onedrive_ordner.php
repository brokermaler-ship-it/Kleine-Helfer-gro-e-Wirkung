<?php
/** @var list<array{drive_id:string,item_id:string,name:string,geteilt:bool}> $ordner */
/** @var ?string $fehler */ /** @var ?array{drive_id:string,item_id:string} $eltern */
?>
<h1 class="h3 mb-3">OneDrive-Zielordner wählen</h1>
<p class="mb-3">
    <a href="<?= e(url('/admin/onedrive')) ?>">Zurück</a>
    <?php if ($eltern !== null): ?> · <a href="<?= e(url('/admin/onedrive/ordner')) ?>">Zur obersten Ebene</a><?php endif; ?>
</p>
<?php if ($fehler !== null): ?>
    <div class="alert alert-danger"><?= e($fehler) ?></div>
<?php elseif ($ordner === []): ?>
    <div class="alert alert-info">Hier gibt es keine Unterordner.</div>
<?php else: ?>
    <ul class="list-group">
        <?php foreach ($ordner as $o): ?>
            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><?= e($o['name']) ?><?= $o['geteilt'] ? ' <span class="badge text-bg-info">geteilt</span>' : '' ?></span>
                <span class="d-flex gap-2">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/onedrive/ordner', ['drive' => $o['drive_id'], 'item' => $o['item_id']])) ?>">Öffnen</a>
                    <form method="post" action="<?= e(url('/admin/onedrive/ordner')) ?>">
                        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                        <input type="hidden" name="drive_id" value="<?= e($o['drive_id']) ?>">
                        <input type="hidden" name="item_id" value="<?= e($o['item_id']) ?>">
                        <?php if ($eltern !== null): ?>
                            <input type="hidden" name="eltern_drive" value="<?= e($eltern['drive_id']) ?>">
                            <input type="hidden" name="eltern_item" value="<?= e($eltern['item_id']) ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-primary">Wählen</button>
                    </form>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
