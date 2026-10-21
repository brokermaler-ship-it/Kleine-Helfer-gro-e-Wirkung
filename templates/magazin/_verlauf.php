<?php
/**
 * Verlauf der Buchungen eines Artikels (neueste zuerst) mit „Gegenbuchen“ für den Börsenwart.
 *
 * @var list<array<string,mixed>> $buchungen aus MagazinRepository::buchungen
 * @var bool $istBoersenwart
 */
use Emc\Magazin\MagazinDienst;
use Emc\Protokoll\Aenderungsprotokoll;

$artKlassen = ['eingang' => 'success', 'ausgang' => 'danger', 'umlagerung' => 'secondary'];
?>
<section class="mt-4" id="buchungen" aria-labelledby="buchungen-titel">
    <h2 class="h4" id="buchungen-titel">Verlauf</h2>
    <?php if ($buchungen === []): ?>
        <p class="text-muted">Noch keine Buchungen.</p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($buchungen as $b): ?>
                <?php
                $art = (string) $b['art'];
                $menge = (int) $b['menge'];
                $grund = match ($art) {
                    'eingang' => $magazinHerkunft[(string) $b['herkunft']] ?? (string) $b['herkunft'],
                    'ausgang' => $magazinAusgang[(string) $b['ausgang_art']] ?? (string) $b['ausgang_art'],
                    default => '→ ' . (string) $b['lagerort_neu'],
                };
                $gegenbuchbar = $istBoersenwart && in_array($art, ['eingang', 'ausgang'], true) && $menge !== 0
                    && $b['gegenbuchung_zu_id'] === null && $b['gegengebucht_durch_id'] === null && $b['verkauf_position_id'] === null;
                ?>
                <li class="list-group-item">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <span class="badge text-bg-<?= e($artKlassen[$art] ?? 'secondary') ?>"><?= e($magazinArten[$art] ?? $art) ?></span>
                            <?php if ($art !== 'umlagerung'): ?><strong class="ms-1 fs-5 align-middle"><?= e(MagazinDienst::mitVorzeichen($menge)) ?></strong><?php endif; ?>
                            <span class="ms-1"><?= e($grund) ?></span>
                            <?php if ($b['betrag'] !== null): ?><span class="ms-1 text-nowrap">· <?= e($magazinBetrag((string) $b['betrag'])) ?></span><?php endif; ?>
                        </div>
                        <div class="text-nowrap"><?= e($magazinDatum((string) $b['datum'])) ?></div>
                    </div>
                    <?php if ($b['freitext'] !== null): ?><div class="text-break"><?= e((string) $b['freitext']) ?></div><?php endif; ?>
                    <div class="small text-muted">
                        Nr. <?= (int) $b['id'] ?>
                        · <?= e($b['benutzer_name'] ?? Aenderungsprotokoll::EHEMALIGES_MITGLIED) ?>
                        · <?= e(date('d.m.Y H:i', (int) strtotime((string) $b['erstellt_am']))) ?>
                        <?php if ($b['gegengebucht_durch_id'] !== null): ?> · <span class="badge text-bg-light border">gegengebucht (Nr. <?= (int) $b['gegengebucht_durch_id'] ?>)</span><?php endif; ?>
                    </div>
                    <?php if ($gegenbuchbar): ?>
                        <form method="post" action="<?= e(url('/magazin/buchungen/' . (int) $b['id'] . '/gegenbuchung')) ?>" class="mt-1"
                              onsubmit="return confirm('Buchung Nr. <?= (int) $b['id'] ?> durch eine Gegenbuchung zurücknehmen?');">
                            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Gegenbuchen</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
