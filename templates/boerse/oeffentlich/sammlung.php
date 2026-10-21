<?php
/**
 * „Sammlung anbieten“: Beschreibung, bis zu 5 Fotos (auch ohne JavaScript; der Server entfernt Metadaten wie den
 * Aufnahmeort), Wunsch verkaufen oder spenden.
 *
 * @var array<string,string> $eingabe
 * @var array<string,string> $fehler
 * @var ?string $hinweis
 * @var \Emc\Dateien\Uploadgrenzen $uploadGrenzen
 */
$wunsch = $eingabe['wunsch'] ?? '';
?>
<p class="mb-2"><a href="<?= e(url('/boerse')) ?>">← Alle Angebote</a></p>
<div class="row">
    <div class="col-12 col-lg-8">
        <h1 class="h3">Sammlung anbieten</h1>
        <p>Du möchtest eine Modellbahn-Sammlung abgeben? Beschreib sie uns kurz, gern mit Fotos. Wir melden uns bei dir.</p>
        <?php if ($hinweis !== null): ?><div class="alert alert-danger" role="alert"><?= e($hinweis) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('/boerse/sammlung-anbieten')) ?>" enctype="multipart/form-data" class="card card-body">
            <?php include __DIR__ . '/_anfrage_formular.php'; ?>
            <div class="mb-3">
                <label for="a-nachricht" class="form-label">Beschreibung der Sammlung</label>
                <textarea class="form-control<?= isset($fehler['nachricht']) ? ' is-invalid' : '' ?>" id="a-nachricht" name="nachricht" rows="5" maxlength="3000" required
                          placeholder="z. B. Spur H0, etwa 30 Wagen und 2 Loks, Gleise, Originalkartons"><?= e($eingabe['nachricht'] ?? '') ?></textarea>
                <?php if (isset($fehler['nachricht'])): ?><div class="invalid-feedback d-block"><?= e($fehler['nachricht']) ?></div><?php endif; ?>
            </div>
            <fieldset class="mb-3">
                <legend class="form-label fs-6">Was möchtest du?</legend>
                <div class="form-check">
                    <input class="form-check-input<?= isset($fehler['wunsch']) ? ' is-invalid' : '' ?>" type="radio" name="wunsch" id="a-verkaufen" value="verkaufen"<?= $wunsch === 'verkaufen' ? ' checked' : '' ?>>
                    <label class="form-check-label" for="a-verkaufen">an den Verein verkaufen</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input<?= isset($fehler['wunsch']) ? ' is-invalid' : '' ?>" type="radio" name="wunsch" id="a-spenden" value="spenden"<?= $wunsch === 'spenden' ? ' checked' : '' ?>>
                    <label class="form-check-label" for="a-spenden">dem Verein spenden</label>
                </div>
                <?php if (isset($fehler['wunsch'])): ?><div class="invalid-feedback d-block"><?= e($fehler['wunsch']) ?></div><?php endif; ?>
            </fieldset>
            <div class="mb-3">
                <?php $fotoId = 'a-fotos'; $fotoBeschriftung = 'Fotos (höchstens 5)'; $fotoGross = false; include dirname(__DIR__, 2) . '/partials/fotofeld.php'; ?>
                <div class="form-text">Aufnahmeort und andere Angaben aus den Fotos speichern wir nicht.</div>
                <?php if (isset($fehler['fotos'])): ?><div class="invalid-feedback d-block"><?= e($fehler['fotos']) ?></div><?php endif; ?>
            </div>
            <?php include __DIR__ . '/_einwilligung.php'; ?>
            <div><button type="submit" class="btn btn-primary" data-senden>Anfrage senden</button></div>
        </form>
    </div>
</div>
