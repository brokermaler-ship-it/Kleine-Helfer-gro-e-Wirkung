<?php
/**
 * @var list<array<string,mixed>> $sammlungen aus MagazinRepository::sammlungen
 * @var bool $istBoersenwart
 */
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Sammlungen</h1>
    <?php if ($istBoersenwart): ?>
        <a class="btn btn-primary" href="<?= e(url('/magazin/sammlungen/neu')) ?>">+ Sammlung anlegen</a>
    <?php endif; ?>
</div>
<p class="text-muted small">Eine Sammlung bündelt mehrere Artikel (z. B. einen Nachlass). Sie lässt sich als Ganzes umlagern.</p>
<?php if ($sammlungen === []): ?>
    <p class="text-muted">Noch keine Sammlungen.</p>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($sammlungen as $s): ?>
            <a class="list-group-item list-group-item-action" href="<?= e(url('/magazin/sammlungen/' . (int) $s['id'])) ?>">
                <div class="d-flex justify-content-between gap-2">
                    <span class="fw-semibold text-break"><?= e((string) $s['name']) ?></span>
                    <span class="text-nowrap small text-muted"><?= (int) $s['artikel_anzahl'] === 1 ? '1 Artikel' : (int) $s['artikel_anzahl'] . ' Artikel' ?> · Bestand <?= (int) $s['bestand'] ?></span>
                </div>
                <?php if ($s['notiz'] !== null && $s['notiz'] !== ''): ?><div class="small text-muted text-truncate"><?= e((string) $s['notiz']) ?></div><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<p class="mt-3"><a href="<?= e(url('/magazin')) ?>">&larr; Zurück zum Magazin</a></p>
