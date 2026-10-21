<?php
/**
 * Kasse am Handy: eine Spalte, große Schaltflächen. Nummer eintippen (Zifferntastatur) oder suchen, Warenkorb als
 * Karten mit Verkaufspreis, Summe, Zahlart, „Verkauf abschließen“. Keine Käuferdaten.
 *
 * @var array<string,mixed> $boerse
 * @var list<array{angebot_id:int, angebot:?array, preis:string, fehler:bool}> $karten
 * @var string $summe DECIMAL
 * @var string $q
 * @var list<array<string,mixed>> $treffer
 * @var int $trefferMax
 * @var list<int> $imKorb
 * @var string $nummer
 * @var string $zahlart
 * @var bool $nullpreis ein Stück im Warenkorb hat 0,00 €
 */
use Emc\Boerse\KasseDienst;

include __DIR__ . '/_angebot_labels.php';
$id = (int) $boerse['id'];
$laeuft = $boerse['status'] === 'laeuft';
$basis = '/boersen/' . $id . '/kasse';
?>
<div class="kasse">
    <div class="d-flex justify-content-between align-items-baseline gap-2 mb-3">
        <h1 class="h4 mb-0 text-break">Kasse · <?= e((string) $boerse['name']) ?></h1>
        <a class="text-nowrap" href="<?= e(url('/boersen/' . $id . '/verkaeufe')) ?>">Verkäufe</a>
    </div>

    <?php if (!$laeuft): ?>
        <div class="alert alert-warning"><?= e(KasseDienst::LAEUFT_NICHT) ?> <a class="alert-link" href="<?= e(url('/boersen/' . $id)) ?>">Zur Börse</a></div>
    <?php else: ?>
        <form method="post" action="<?= e(url($basis . '/hinzu')) ?>" class="mb-3" data-senden>
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <label for="kasse-nummer" class="form-label fw-semibold">Angebotsnummer</label>
            <div class="d-flex gap-2">
                <input type="text" inputmode="numeric" class="form-control form-control-lg kasse-nummer" id="kasse-nummer" name="nummer"
                       placeholder="z. B. 118" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="30"
                       value="<?= e($nummer) ?>" autofocus>
                <button type="submit" class="btn btn-primary btn-lg kasse-knopf">Hinzufügen</button>
            </div>
        </form>

        <form method="get" action="<?= e(url($basis)) ?>" role="search" class="mb-3">
            <label for="kasse-suche" class="form-label small mb-1">Oder nach Bezeichnung suchen</label>
            <div class="d-flex gap-2">
                <input type="search" class="form-control kasse-feld" id="kasse-suche" name="q" value="<?= e($q) ?>" autocomplete="off">
                <button type="submit" class="btn btn-outline-secondary kasse-knopf">Suchen</button>
            </div>
        </form>

        <?php if ($q !== ''): ?>
            <section class="mb-4" aria-labelledby="treffer-titel">
                <h2 class="h6" id="treffer-titel">Treffer für „<?= e($q) ?>“</h2>
                <?php if ($treffer === []): ?>
                    <p class="text-muted">Kein offenes Angebot dieser Börse (oder dauerhaftes) passt.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($treffer as $t): ?>
                            <div class="list-group-item d-flex gap-2 align-items-center">
                                <?= $angebotTitelbild($t) ?>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-bold"><?= e((string) $t['nummer']) ?> <?= $t['status'] === 'reserviert' ? $angebotStatusBadge('reserviert') : '' ?></div>
                                    <div class="text-break small"><?= e((string) $t['bezeichnung']) ?></div>
                                    <div class="small text-muted"><?= e($preis((string) $t['wunschpreis'])) ?></div>
                                </div>
                                <?php if (in_array((int) $t['id'], $imKorb, true)): ?>
                                    <span class="badge text-bg-light border">im Warenkorb</span>
                                <?php else: ?>
                                    <form method="post" action="<?= e(url($basis . '/hinzu')) ?>" class="m-0">
                                        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                                        <button type="submit" class="btn btn-outline-primary kasse-knopf" name="angebot_id" value="<?= (int) $t['id'] ?>">Hinzufügen</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($treffer) >= $trefferMax): ?><p class="small text-muted mt-1">Nur die ersten <?= (int) $trefferMax ?> Treffer – bitte genauer suchen.</p><?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <h2 class="h5">Warenkorb<?= $karten !== [] ? ' (' . count($karten) . ')' : '' ?></h2>
    <?php if ($karten === []): ?>
        <p class="text-muted">Noch nichts im Warenkorb. Nummer vom Preisschild eintippen und „Hinzufügen“.</p>
    <?php else: ?>
        <form method="post" action="<?= e(url($basis . '/abschliessen')) ?>" data-kasse-korb data-senden>
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php // Eingabetaste in einem Preisfeld: Preise übernehmen (nicht „Entfernen“ der ersten Karte). ?>
            <button type="submit" class="visually-hidden" formaction="<?= e(url($basis . '/preis')) ?>" tabindex="-1" aria-hidden="true">Preise übernehmen</button>
            <?php foreach ($karten as $k): ?>
                <?php $a = $k['angebot']; $aid = (int) $k['angebot_id']; ?>
                <div class="card kasse-karte mb-2<?= $k['fehler'] ? ' kasse-karte-fehler border-danger' : '' ?>" data-angebot="<?= $aid ?>">
                    <div class="card-body d-flex gap-3 pb-2">
                        <?php if ($a !== null): ?>
                            <?= $angebotTitelbild($a) ?>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-bold fs-5"><?= e((string) $a['nummer']) ?>
                                    <?php if ($a['status'] !== 'angeboten'): ?><?= $angebotStatusBadge((string) $a['status']) ?><?php endif; ?>
                                </div>
                                <div class="text-break"><?= e((string) $a['bezeichnung']) ?></div>
                                <div class="small text-muted">Wunschpreis <?= e($preis((string) $a['wunschpreis'])) ?></div>
                            </div>
                        <?php else: ?>
                            <div class="text-danger">Dieses Angebot gibt es nicht mehr.</div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body pt-0 d-flex gap-2 align-items-end">
                        <div class="flex-grow-1">
                            <label class="form-label small mb-0" for="preis-<?= $aid ?>">Verkaufspreis</label>
                            <div class="input-group input-group-lg">
                                <input type="text" inputmode="decimal" class="form-control kasse-preis" id="preis-<?= $aid ?>" name="preis[<?= $aid ?>]"
                                       value="<?= e($k['preis']) ?>" autocomplete="off" maxlength="12" data-kasse-preis>
                                <span class="input-group-text">€</span>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-outline-danger kasse-knopf" formaction="<?= e(url($basis . '/entfernen')) ?>" name="angebot_id" value="<?= $aid ?>">Entfernen</button>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="d-flex justify-content-between align-items-baseline mt-3">
                <span class="fs-5">Summe</span>
                <span class="kasse-summe" data-kasse-summe><?= e($preis($summe)) ?></span>
            </div>
            <div class="text-end mb-3">
                <button type="submit" class="btn btn-link px-0" formaction="<?= e(url($basis . '/preis')) ?>">Geänderte Preise übernehmen</button>
            </div>

            <?php if ($laeuft): ?>
                <fieldset class="mb-3">
                    <legend class="h6">Zahlart</legend>
                    <div class="kasse-zahlart">
                        <?php foreach (KasseDienst::ZAHLARTEN as $wert => $text): ?>
                            <input type="radio" class="btn-check" name="zahlart" id="zahlart-<?= e($wert) ?>" value="<?= e($wert) ?>" autocomplete="off"<?= $zahlart === $wert ? ' checked' : '' ?>>
                            <label class="btn btn-outline-primary btn-lg kasse-kachel" for="zahlart-<?= e($wert) ?>"><?= e($text) ?></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?php if ($nullpreis): ?>
                    <div class="form-check kasse-check mb-3">
                        <input class="form-check-input" type="checkbox" name="nullpreis_ok" value="1" id="nullpreis-ok" data-nullpreis-ok>
                        <label class="form-check-label" for="nullpreis-ok">Ja, Stücke mit Verkaufspreis 0,00 € so abgeben</label>
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-success btn-lg w-100 kasse-knopf kasse-abschluss" data-kasse-abschluss>Verkauf abschließen</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    <p class="mt-4 small"><a href="<?= e(url('/boersen/' . $id)) ?>">&larr; Zur Börse</a></p>
</div>
