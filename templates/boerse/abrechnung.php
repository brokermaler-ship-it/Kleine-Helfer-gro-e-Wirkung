<?php
/**
 * Abrechnung einer Börse (Börsenwart): Verein, Nach Zahlart, Mitglieder mit Haken „ausgezahlt“; Druckansicht,
 * Abschluss (laufende Börse) und Wiederöffnen (nur Administratoren, abgeschlossene Börse).
 *
 * @var array<string,mixed> $boerse
 * @var array<string,mixed> $abrechnung aus Abrechnung::berechne
 * @var array<int, array{betrag:string, ausgezahlt_am:string}> $auszahlungen
 * @var bool $darfWiederOeffnen
 * @var string $heute
 */
include __DIR__ . '/_angebot_labels.php';
$b = $boerse;
$id = (int) $b['id'];
$mitFormularen = true;
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div class="min-w-0">
        <h1 class="h3 mb-1 text-break">Abrechnung <?= e((string) $b['name']) ?></h1>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?= $boerseStatusBadge((string) $b['status']) ?>
            <span class="text-muted"><?= e($datum((string) $b['datum'])) ?><?= $b['ort'] !== null ? ' · ' . e((string) $b['ort']) : '' ?> · Provision <?= e($provision((string) $b['provision_prozent'])) ?></span>
        </div>
        <?php if ($b['status'] === 'abgeschlossen' && $b['abgeschlossen_am'] !== null): ?>
            <p class="small text-muted mb-0 mt-1">Abgeschlossen am <?= e(date('d.m.Y H:i', (int) strtotime((string) $b['abgeschlossen_am']))) ?>. Kasse und Angebote dieser Börse sind gesperrt; Auszahlungen lassen sich weiter vermerken.</p>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('/boersen/' . $id . '/abrechnung', ['druck' => '1'])) ?>" target="_blank">Druckansicht</a>
        <?php if ($b['status'] !== 'in_vorbereitung'): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('/boersen/' . $id . '/verkaeufe')) ?>">Verkäufe</a>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/_abrechnung_tabellen.php'; ?>

<?php if ($b['status'] === 'laeuft'): ?>
    <section class="card border-danger mt-4" aria-labelledby="abschluss-titel">
        <div class="card-body">
            <h2 class="h5" id="abschluss-titel">Börse abschließen</h2>
            <p class="mb-2">Unverkaufte Stücke dieser Börse gehen zurück (Vereinsstücke bleiben mit unverändertem Bestand im Magazin). Danach sind Kasse und Angebote dieser Börse gesperrt.</p>
            <form method="post" action="<?= e(url('/boersen/' . $id . '/abschliessen')) ?>" class="m-0" onsubmit="return confirm('Börse abschließen? Unverkaufte Stücke gehen zurück. Danach sind Kasse und Angebote dieser Börse gesperrt.');">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <button type="submit" class="btn btn-danger">Börse abschließen</button>
            </form>
        </div>
    </section>
<?php elseif ($b['status'] === 'abgeschlossen' && $darfWiederOeffnen): ?>
    <section class="card mt-4" aria-labelledby="oeffnen-titel">
        <div class="card-body">
            <h2 class="h5" id="oeffnen-titel">Börse wieder öffnen</h2>
            <p class="mb-2">Nur für Administratoren. Die zurückgegebenen Stücke bleiben zurück; bei Bedarf einzeln wieder anbieten.</p>
            <form method="post" action="<?= e(url('/boersen/' . $id . '/wieder-oeffnen')) ?>" class="m-0" onsubmit="return confirm('Börse wieder öffnen? Kasse und Angebote dieser Börse sind dann wieder änderbar.');">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <button type="submit" class="btn btn-outline-danger">Wieder öffnen</button>
            </form>
        </div>
    </section>
<?php endif; ?>

<p class="mt-3"><a href="<?= e(url('/boersen/' . $id)) ?>">&larr; Zur Börse</a></p>
