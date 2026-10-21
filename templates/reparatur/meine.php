<?php
/**
 * „Meine Meldungen“: eigene Defektmeldungen, neueste zuerst, mit Status.
 *
 * @var list<array<string,mixed>> $meldungen
 */
use Emc\Reparatur\ReparaturStatus;

$statusKlassen = ['gemeldet' => 'primary', 'geplant' => 'info', 'in_arbeit' => 'warning', 'wartet_auf_teile' => 'warning', 'erledigt' => 'success', 'verworfen' => 'secondary'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Meine Meldungen</h1>
    <a class="btn btn-danger" href="<?= e(url('/defekt')) ?>">Defekt melden</a>
</div>

<?php if ($meldungen === []): ?>
    <p class="text-muted">Du hast noch keine Defekte gemeldet.</p>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($meldungen as $m): ?>
            <?php
            $status = ReparaturStatus::tryFrom((string) $m['status']);
            $vorhanden = $m['objekt_kennung'] !== null;
            $pfad = ($m['objekt_typ'] === 'anlage' ? '/anlagen/' : '/fahrzeuge/') . (int) $m['objekt_id'];
            ?>
            <<?= $vorhanden ? 'a href="' . e(url($pfad)) . '"' : 'div' ?> class="list-group-item<?= $vorhanden ? ' list-group-item-action' : '' ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="fw-semibold">
                        <?php if ($vorhanden): ?>
                            <span class="fw-bold me-1"><?= e((string) $m['objekt_kennung']) ?></span> <?= e((string) $m['objekt_name']) ?>
                        <?php else: ?>
                            <span class="text-muted">(<?= $m['objekt_typ'] === 'anlage' ? 'Anlage' : 'Fahrzeug' ?> gelöscht)</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-nowrap">
                        <?php if ($m['prioritaet'] === 'dringend'): ?><span class="badge text-bg-danger">dringend</span><?php endif; ?>
                        <span class="badge text-bg-<?= e($statusKlassen[(string) $m['status']] ?? 'secondary') ?>"><?= e($status?->label() ?? (string) $m['status']) ?></span>
                    </div>
                </div>
                <div class="mt-1"><?= nl2br(e((string) $m['beschreibung'])) ?></div>
                <div class="small text-muted mt-1">
                    gemeldet am <?= e(date('d.m.Y', (int) strtotime((string) $m['gemeldet_am']))) ?><?= ($m['wo_bauteil'] ?? null) !== null ? ' · ' . e((string) $m['wo_bauteil']) : '' ?>
                </div>
            </<?= $vorhanden ? 'a' : 'div' ?>>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
