<?php
/**
 * Inhalt der Abrechnung (Bildschirm und Druckansicht): Verein, Nach Zahlart, Mitglieder mit Haken „ausgezahlt“.
 *
 * @var array<string,mixed> $boerse
 * @var array<string,mixed> $abrechnung aus Abrechnung::berechne
 * @var array<int, array{betrag:string, ausgezahlt_am:string}> $auszahlungen
 * @var string $heute Y-m-d
 * @var bool $mitFormularen false in der Druckansicht
 */
use Emc\Boerse\Geld;
use Emc\Reparatur\ReparaturValidator;

$cent = fn(int $c): string => ReparaturValidator::betrag(Geld::dezimal($c));
$id = (int) $boerse['id'];
$v = $abrechnung['verein'];
$z = $abrechnung['zahlarten'];
$mitglieder = $abrechnung['mitglieder'];
$summe = fn(string $f): int => array_sum(array_column($mitglieder, $f));
?>
<?php
// Verkauf nach dem Haken „ausgezahlt“: vermerkter Betrag ≠ aktuelle Auszahlung (Differenz in Cent je Person).
$abweichend = [];
foreach ($mitglieder as $m) {
    $aus = $auszahlungen[$m['person_id'] ?? 0] ?? null;
    if ($aus !== null && Geld::cent($aus['betrag']) !== $m['auszahlung']) {
        $abweichend[$m['person_id'] ?? 0] = $m['auszahlung'] - Geld::cent($aus['betrag']);
    }
}
?>
<?php if (!$abrechnung['kontrolle']): ?>
    <div class="alert alert-danger" role="alert">Die Summen passen nicht zusammen – bitte melden.</div>
<?php endif; ?>
<?php if ($abweichend !== []): ?>
    <div class="alert alert-warning" role="alert">Bei <?= count($abweichend) ?> <?= count($abweichend) === 1 ? 'Mitglied' : 'Mitgliedern' ?> weicht der Betrag vom vermerkten ab – bitte nachzahlen und neu vermerken.</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <section class="col-12 col-md-6" aria-labelledby="abr-verein">
        <h2 class="h5" id="abr-verein">Verein</h2>
        <table class="table table-sm abrechnung-tabelle mb-0">
            <tbody>
                <tr><th scope="row" class="fw-normal">Einnahmen aus Vereinsstücken</th><td class="text-end text-nowrap"><?= e($cent($v['vereinsstuecke'])) ?></td></tr>
                <tr><th scope="row" class="fw-normal">Provisionen</th><td class="text-end text-nowrap"><?= e($cent($v['provisionen'])) ?></td></tr>
                <tr><th scope="row" class="fw-normal">Spenden aus Mitgliederverkäufen</th><td class="text-end text-nowrap"><?= e($cent($v['spenden'])) ?></td></tr>
                <tr class="fw-bold"><th scope="row">Summe Verein</th><td class="text-end text-nowrap"><?= e($cent($v['gesamt'])) ?></td></tr>
            </tbody>
        </table>
    </section>
    <section class="col-12 col-md-6" aria-labelledby="abr-zahlart">
        <h2 class="h5" id="abr-zahlart">Nach Zahlart</h2>
        <table class="table table-sm abrechnung-tabelle mb-0">
            <tbody>
                <tr><th scope="row" class="fw-normal">bar</th><td class="text-end text-nowrap"><?= e($cent($z['bar'])) ?></td></tr>
                <tr><th scope="row" class="fw-normal">Karte/Überweisung</th><td class="text-end text-nowrap"><?= e($cent($z['karte_ueberweisung'])) ?></td></tr>
                <tr class="fw-bold"><th scope="row">gesamt</th><td class="text-end text-nowrap"><?= e($cent(array_sum($z))) ?></td></tr>
            </tbody>
        </table>
        <p class="small text-muted mt-1 mb-0">Davon an Mitglieder auszuzahlen: <?= e($cent($summe('auszahlung'))) ?></p>
    </section>
</div>

<section aria-labelledby="abr-mitglieder">
    <h2 class="h5" id="abr-mitglieder">Mitglieder</h2>
    <?php if ($mitglieder === []): ?>
        <p class="text-muted">Noch keine verkauften Stücke von Mitgliedern.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle abrechnung-mitglieder">
                <thead>
                    <tr><th>Name</th><th class="text-end">Stücke</th><th class="text-end">Erlös</th><th class="text-end">Provision</th><th class="text-end">Auszahlung</th><th>ausgezahlt</th></tr>
                </thead>
                <tbody>
                <?php foreach ($mitglieder as $m): ?>
                    <?php
                    $pid = $m['person_id'];
                    $aus = $auszahlungen[$pid ?? 0] ?? null;
                    ?>
                    <tr>
                        <td class="text-break"><?= e($m['name']) ?></td>
                        <td class="text-end"><?= (int) $m['stuecke'] ?></td>
                        <td class="text-end text-nowrap"><?= e($cent($m['erloes'])) ?></td>
                        <td class="text-end text-nowrap"><?= e($cent($m['provision'])) ?></td>
                        <td class="text-end text-nowrap fw-semibold">
                            <?php if ($m['auszahlung'] === 0 && $m['spende'] > 0): ?>
                                0,00 € (Spende)
                            <?php else: ?>
                                <?= e($cent($m['auszahlung'])) ?>
                                <?php if ($m['spende'] > 0): ?><div class="small fw-normal text-muted">dazu Spende <?= e($cent($m['spende'])) ?></div><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($aus !== null): ?>
                                <span class="text-success">&#10003; ausgezahlt am <?= e(date('d.m.Y', (int) strtotime($aus['ausgezahlt_am']))) ?></span>
                                <?php if (Geld::cent($aus['betrag']) !== $m['auszahlung']): ?>
                                    <div class="small text-danger">Vermerkt waren <?= e(ReparaturValidator::betrag($aus['betrag'])) ?>, jetzt sind es <?= e($cent($m['auszahlung'])) ?>.</div>
                                    <?php if (!$mitFormularen && ($abweichend[$pid ?? 0] ?? 0) > 0): ?>
                                        <div class="small"><span class="abrechnung-ankreuzen" aria-hidden="true">&#9744;</span> Nachzahlung <?= e($cent($abweichend[$pid ?? 0])) ?> am ____________</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($mitFormularen && $pid !== null): ?>
                                    <form method="post" action="<?= e(url('/boersen/' . $id . '/auszahlungen/' . $pid . '/loeschen')) ?>" class="d-inline" onsubmit="return confirm('Haken „ausgezahlt“ entfernen?');">
                                        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                                        <button type="submit" class="btn btn-link btn-sm p-0 ms-1">Haken entfernen</button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ($mitFormularen && $pid !== null && $m['auszahlung'] > 0): ?>
                                <form method="post" action="<?= e(url('/boersen/' . $id . '/auszahlungen')) ?>" class="d-flex flex-wrap gap-1 align-items-center abrechnung-haken">
                                    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                                    <input type="hidden" name="person_id" value="<?= (int) $pid ?>">
                                    <label class="visually-hidden" for="aus-<?= (int) $pid ?>">Ausgezahlt am</label>
                                    <input type="date" class="form-control form-control-sm" id="aus-<?= (int) $pid ?>" name="ausgezahlt_am" value="<?= e($heute) ?>" max="<?= e($heute) ?>" required>
                                    <button type="submit" class="btn btn-sm btn-outline-success">ausgezahlt</button>
                                </form>
                            <?php elseif ($m['auszahlung'] > 0): ?>
                                <span class="abrechnung-ankreuzen" aria-hidden="true">&#9744;</span> am ____________
                            <?php else: ?>
                                <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <th>Summe</th>
                        <td class="text-end"><?= (int) $summe('stuecke') ?></td>
                        <td class="text-end text-nowrap"><?= e($cent($summe('erloes'))) ?></td>
                        <td class="text-end text-nowrap"><?= e($cent($summe('provision'))) ?></td>
                        <td class="text-end text-nowrap"><?= e($cent($summe('auszahlung'))) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>
