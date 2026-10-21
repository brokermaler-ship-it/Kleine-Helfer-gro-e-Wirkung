<?php
/**
 * Reparaturblock auf Fahrzeug- (Tab „Reparaturen“) und Anlagenseite (Abschnitt „Reparaturen“): offene oben,
 * abgeschlossene darunter, je Reparatur ein Link zur Detailseite, dazu „Defekt melden“.
 *
 * @var array<string,mixed> $block Daten aus ReparaturDienst::blockDaten (objektTyp, objekt, reparaturen, offene, darfBearbeiten)
 */
use Emc\Reparatur\ReparaturStatus;

include __DIR__ . '/_labels.php';
$rbTyp = (string) $block['objektTyp'];
$rbObjekt = $block['objekt'];
$rbReparaturen = $block['reparaturen'];
$rbDarf = (bool) $block['darfBearbeiten'];
$rbOffen = array_values(array_filter($rbReparaturen, fn(array $r): bool => ReparaturStatus::tryFrom((string) $r['status'])?->istOffen() ?? false));
$rbAbgeschlossen = array_values(array_filter($rbReparaturen, fn(array $r): bool => !(ReparaturStatus::tryFrom((string) $r['status'])?->istOffen() ?? false)));
$rbListe = function (array $eintraege) use ($reparaturStatusBadge, $prioritaetBadge, $reparaturDatum, $betrag, $rbDarf): void { ?>
    <div class="list-group mb-3">
        <?php foreach ($eintraege as $r): ?>
            <div class="list-group-item position-relative">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <a class="stretched-link text-reset text-decoration-none fw-semibold" href="<?= e(url('/reparaturen/' . (int) $r['id'])) ?>"><?= e((string) $r['beschreibung']) ?></a>
                    <div class="text-nowrap">
                        <?= $prioritaetBadge((string) $r['prioritaet']) ?>
                        <?= $reparaturStatusBadge((string) $r['status']) ?>
                    </div>
                </div>
                <div class="small text-muted mt-1">
                    gemeldet am <?= e($reparaturDatum((string) $r['gemeldet_am'])) ?>
                    <?php if (($r['wo_bauteil'] ?? null) !== null): ?> · <?= e((string) $r['wo_bauteil']) ?><?php endif; ?>
                    <?php if ($r['zustaendig_name'] !== null): ?> · zuständig: <?= e((string) $r['zustaendig_name']) ?><?php endif; ?>
                    <?php if ($r['termin'] !== null && ReparaturStatus::tryFrom((string) $r['status'])?->istOffen()): ?> · Termin <?= e($reparaturDatum((string) $r['termin'])) ?><?php endif; ?>
                    <?php if ($r['abgeschlossen_am'] !== null): ?> · abgeschlossen am <?= e($reparaturDatum((string) $r['abgeschlossen_am'])) ?><?php endif; ?>
                    <?php if (!in_array((string) $r['kosten_gesamt'], ['0', '0.00'], true)): ?> · <?= e($betrag((string) $r['kosten_gesamt'])) ?><?php endif; ?>
                </div>
                <?php if ($rbDarf): ?>
                    <a class="btn btn-sm btn-outline-primary mt-2 position-relative z-2" href="<?= e(url('/reparaturen/' . (int) $r['id'] . '/bearbeiten')) ?>">Bearbeiten</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php };
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <h3 class="h6 mb-0">Offen</h3>
    <a class="btn btn-sm btn-danger" href="<?= e(url(($rbTyp === 'anlage' ? '/anlagen/' : '/fahrzeuge/') . (int) $rbObjekt['id'] . '/defekt')) ?>">Defekt melden</a>
</div>
<?php if ($rbReparaturen === []): ?>
    <p class="text-muted">Keine Reparaturen.</p>
<?php else: ?>
    <?php if ($rbOffen === []): ?>
        <p class="text-muted">Keine offenen Reparaturen.</p>
    <?php else: $rbListe($rbOffen); endif; ?>
    <?php if ($rbAbgeschlossen !== []): ?>
        <h3 class="h6">Abgeschlossen</h3>
        <?php $rbListe($rbAbgeschlossen); ?>
    <?php endif; ?>
<?php endif; ?>
