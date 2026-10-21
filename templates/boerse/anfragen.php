<?php
/**
 * Eingang der Börsenanfragen (nur Börsenwart), neueste zuerst. Alle Angaben stammen von Besuchern: nur mit e().
 *
 * @var string $status '' (offen und in Bearbeitung), offen, in_bearbeitung, erledigt oder alle
 * @var list<array<string,mixed>> $anfragen
 */
include __DIR__ . '/_anfrage_labels.php';
$filter = ['' => 'Neu und in Bearbeitung', 'offen' => 'Neu', 'in_bearbeitung' => 'In Bearbeitung', 'erledigt' => 'Erledigt', 'alle' => 'Alle'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Börsenanfragen</h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/angebote')) ?>">Angebote</a>
</div>
<nav aria-label="Filter" class="mb-3">
    <ul class="nav nav-pills flex-wrap gap-1">
        <?php foreach ($filter as $wert => $text): ?>
            <li class="nav-item">
                <a class="nav-link py-1 px-2<?= $status === $wert ? ' active' : '' ?>"<?= $status === $wert ? ' aria-current="page"' : '' ?> href="<?= e($wert === '' ? url('/anfragen') : url('/anfragen', ['status' => $wert])) ?>"><?= e($text) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
<?php if ($anfragen === []): ?>
    <p class="text-muted">Keine Anfragen.</p>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($anfragen as $a): ?>
            <a class="list-group-item list-group-item-action" href="<?= e(url('/anfragen/' . (int) $a['id'])) ?>">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div class="fw-semibold text-break"><?= e((string) $a['name']) ?></div>
                    <div><?= $anfrageStatusBadge((string) $a['status']) ?></div>
                </div>
                <div class="small text-break"><?= e($anfrageArt($a)) ?></div>
                <div class="small text-muted">
                    eingegangen am <?= e(date('d.m.Y', (int) strtotime((string) $a['eingegangen_am']))) ?>
                    <?php if ((int) $a['fotos_anzahl'] > 0): ?> · <?= (int) $a['fotos_anzahl'] === 1 ? '1 Foto' : (int) $a['fotos_anzahl'] . ' Fotos' ?><?php endif; ?>
                    <?php if ($a['magazin_artikel_id'] !== null): ?> · übernommen als M<?= (int) $a['magazin_artikel_id'] ?><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<p class="small text-muted mt-3">Anfragen werden 12 Monate nach Eingang samt Fotos automatisch gelöscht.</p>
