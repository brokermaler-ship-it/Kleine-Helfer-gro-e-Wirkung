<?php
/**
 * Seite „Reparaturen“: dringende zuerst, dann neueste Meldung; Filter Status (Standard „offen“), Objekt, zuständig.
 *
 * @var \Emc\Reparatur\ReparaturFilter $filter
 * @var list<array<string,mixed>> $reparaturen aus ReparaturRepository::suche
 * @var list<array<string,mixed>> $personen
 */
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturFilter;
use Emc\Reparatur\ReparaturStatus;

include __DIR__ . '/_labels.php';
$statusWahl = $filter->status ?? ReparaturFilter::OFFEN;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Reparaturen</h1>
    <a class="btn btn-danger" href="<?= e(url('/defekt')) ?>">Defekt melden</a>
</div>

<button class="btn btn-outline-secondary d-md-none mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterleiste"
        aria-expanded="<?= $filter->istGesetzt() ? 'true' : 'false' ?>" aria-controls="filterleiste">Filter</button>
<form method="get" action="<?= e(url('/reparaturen')) ?>" id="filterleiste" class="collapse d-md-block card card-body mb-3<?= $filter->istGesetzt() ? ' show' : '' ?>">
    <div class="row g-2">
        <div class="col-6 col-md-3">
            <label for="f-status" class="form-label small mb-0">Status</label>
            <select class="form-select" id="f-status" name="status">
                <option value="<?= ReparaturFilter::OFFEN ?>"<?= $statusWahl === ReparaturFilter::OFFEN ? ' selected' : '' ?>>offen</option>
                <?php foreach (ReparaturStatus::cases() as $s): ?>
                    <option value="<?= e($s->value) ?>"<?= $statusWahl === $s->value ? ' selected' : '' ?>><?= e($s->label()) ?></option>
                <?php endforeach; ?>
                <option value="<?= ReparaturFilter::ALLE ?>"<?= $statusWahl === ReparaturFilter::ALLE ? ' selected' : '' ?>>alle</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="f-typ" class="form-label small mb-0">Objekt</label>
            <select class="form-select" id="f-typ" name="typ">
                <option value="">alle</option>
                <?php foreach (ReparaturFilter::TYPEN as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $filter->typ === $wert ? ' selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label for="f-zustaendig" class="form-label small mb-0">zuständig</label>
            <select class="form-select" id="f-zustaendig" name="zustaendig">
                <option value="">alle</option>
                <?php foreach ($personen as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"<?= $filter->zustaendigId === (int) $p['id'] ? ' selected' : '' ?>><?= e(trim($p['vorname'] . ' ' . $p['nachname'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-primary">Filtern</button>
            <a class="btn btn-link" href="<?= e(url('/reparaturen')) ?>">Zurücksetzen</a>
        </div>
    </div>
</form>

<?php if ($reparaturen === []): ?>
    <p class="text-muted">Keine Reparaturen gefunden.</p>
<?php else: ?>
    <p class="small text-muted mb-2"><?= count($reparaturen) === 1 ? '1 Reparatur' : count($reparaturen) . ' Reparaturen' ?></p>
    <div class="list-group reparatur-liste">
        <?php foreach ($reparaturen as $rep): ?>
            <a href="<?= e(url('/reparaturen/' . (int) $rep['id'])) ?>" class="list-group-item list-group-item-action">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="fw-semibold">
                        <?php if ($rep['objekt_kennung'] !== null): ?>
                            <span class="fw-bold me-1"><?= e((string) $rep['objekt_kennung']) ?></span> <?= e((string) $rep['objekt_name']) ?>
                            <?php if ($rep['spurweite_name'] !== null): ?><span class="small text-muted fw-normal">· <?= e((string) $rep['spurweite_name']) ?></span><?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">(<?= $rep['objekt_typ'] === 'anlage' ? 'Anlage' : 'Fahrzeug' ?> gelöscht)</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-nowrap">
                        <?= $prioritaetBadge((string) $rep['prioritaet']) ?>
                        <?= $reparaturStatusBadge((string) $rep['status']) ?>
                    </div>
                </div>
                <div class="mt-1 text-truncate"><?= e((string) $rep['beschreibung']) ?><?= ($rep['wo_bauteil'] ?? null) !== null ? ' · ' . e((string) $rep['wo_bauteil']) : '' ?></div>
                <div class="small text-muted mt-1">
                    gemeldet am <?= e($reparaturDatum((string) $rep['gemeldet_am'])) ?>
                    · von <?= e((string) ($rep['gemeldet_von_name'] ?? Aenderungsprotokoll::EHEMALIGES_MITGLIED)) ?>
                    <?php if ($rep['zustaendig_name'] !== null): ?> · zuständig: <?= e((string) $rep['zustaendig_name']) ?><?php endif; ?>
                    <?php if ($rep['termin'] !== null): ?> · Termin <?= e($reparaturDatum((string) $rep['termin'])) ?><?php endif; ?>
                    <?php if (!in_array((string) $rep['kosten_gesamt'], ['0', '0.00'], true)): ?> · <?= e($betrag((string) $rep['kosten_gesamt'])) ?><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
