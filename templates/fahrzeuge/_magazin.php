<?php
/**
 * Block „Magazin“ auf der Fahrzeugseite (nur ausgemusterte Vereinsfahrzeuge bzw. Fahrzeuge mit Magazinartikel).
 *
 * @var array<string,mixed> $fz
 * @var array{artikel: ?array{id:int, code:string, bestand:int, abgegeben_am:?string}, darfUebergeben: bool} $magazin
 */
use Emc\Magazin\BuchungValidator;

$ma = $magazin['artikel'];
?>
<?php if ($ma !== null): ?>
    <div class="alert alert-info d-flex flex-wrap gap-2 align-items-center" role="status">
        <?php if ($ma['bestand'] > 0): ?>
            <span>Im Magazin als <a class="fw-semibold" href="<?= e(url('/magazin/' . $ma['id'])) ?>"><?= e($ma['code']) ?></a>.</span>
        <?php elseif ($ma['abgegeben_am'] !== null): ?>
            <span>abgegeben am <?= e(date('d.m.Y', (int) strtotime($ma['abgegeben_am']))) ?> (Magazin <?= e($ma['code']) ?>)</span>
            <a href="<?= e(url('/magazin/' . $ma['id'])) ?>">Zum Magazinartikel</a>
        <?php else: ?>
            <span>Nicht mehr im Magazin (<?= e($ma['code']) ?>)</span>
            <a href="<?= e(url('/magazin/' . $ma['id'])) ?>">Zum Magazinartikel</a>
        <?php endif; ?>
    </div>
<?php elseif ($magazin['darfUebergeben']): ?>
    <section class="card mb-3" aria-labelledby="an-magazin-titel">
        <div class="card-body">
            <h2 class="h6" id="an-magazin-titel">Ans Magazin übergeben</h2>
            <p class="small text-muted mb-2">Es entsteht ein Magazinartikel (Kategorie Fahrzeuge, Menge 1, mit Titelfoto). Das Fahrzeug bleibt als Datensatz auf dem Abstellgleis.</p>
            <form method="post" action="<?= e(url('/fahrzeuge/' . $fz['id'] . '/an-magazin')) ?>" class="row g-2 align-items-end"
                  onsubmit="return confirm('Fahrzeug ans Magazin übergeben? Es kann danach nicht mehr in Betrieb genommen werden.');">
                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                <div class="col-12 col-sm">
                    <label class="form-label small mb-0" for="an-magazin-lagerort">Lagerort im Magazin (optional)</label>
                    <input type="text" class="form-control" id="an-magazin-lagerort" name="lagerort" maxlength="<?= BuchungValidator::LAGERORT_MAX ?>">
                </div>
                <div class="col-12 col-sm-auto"><button type="submit" class="btn btn-outline-primary">An Magazin übergeben</button></div>
            </form>
        </div>
    </section>
<?php endif; ?>
