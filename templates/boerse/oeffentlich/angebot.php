<?php
/**
 * Öffentliche Angebotsseite mit Fotos (nur Anzeige-Variante) und Formular „Kaufanfrage“.
 *
 * @var array<string,mixed> $a
 * @var array<string,string> $eingabe
 * @var array<string,string> $fehler
 * @var ?string $hinweis Meldung oberhalb des Formulars (z. B. Begrenzung)
 */
?>
<p class="mb-2"><a href="<?= e(url('/boerse')) ?>">← Alle Angebote</a></p>
<div class="row g-4">
    <div class="col-12 col-lg-7">
        <h1 class="h3"><?= e((string) $a['bezeichnung']) ?></h1>
        <?php include __DIR__ . '/_karte_daten.php'; ?>
        <?php if ($a['beschreibung'] !== null && trim((string) $a['beschreibung']) !== ''): ?>
            <p class="mt-3" style="white-space: pre-line"><?= e((string) $a['beschreibung']) ?></p>
        <?php endif; ?>
        <?php foreach ($a['foto_ids'] as $i => $fotoId): ?>
            <img class="img-fluid rounded border mt-3 d-block boerse-foto" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"
                 alt="Foto <?= $i + 1 ?> zu <?= e((string) $a['bezeichnung']) ?>"
                 src="<?= e(url('/boerse/foto/' . $a['nummer'] . '/' . (int) $fotoId . '/anzeige')) ?>">
        <?php endforeach; ?>
    </div>
    <div class="col-12 col-lg-5">
        <section class="card card-body" id="anfrage" aria-labelledby="anfrage-titel">
            <h2 class="h5" id="anfrage-titel">Kaufanfrage</h2>
            <p class="small text-muted">Schreib uns, wenn du <?= e((string) $a['nummer']) ?> kaufen möchtest. Die Anfrage reserviert das Stück noch nicht – wir melden uns bei dir.</p>
            <?php if ($hinweis !== null): ?><div class="alert alert-danger" role="alert"><?= e($hinweis) ?></div><?php endif; ?>
            <form method="post" action="<?= e(url('/boerse/angebot/' . $a['nummer'] . '/anfrage')) ?>#anfrage">
                <?php include __DIR__ . '/_anfrage_formular.php'; ?>
                <div class="mb-3">
                    <label for="a-nachricht" class="form-label">Nachricht</label>
                    <textarea class="form-control<?= isset($fehler['nachricht']) ? ' is-invalid' : '' ?>" id="a-nachricht" name="nachricht" rows="4" maxlength="3000" required><?= e($eingabe['nachricht'] ?? '') ?></textarea>
                    <?php if (isset($fehler['nachricht'])): ?><div class="invalid-feedback d-block"><?= e($fehler['nachricht']) ?></div><?php endif; ?>
                </div>
                <?php include __DIR__ . '/_einwilligung.php'; ?>
                <button type="submit" class="btn btn-primary" data-senden>Anfrage senden</button>
            </form>
        </section>
    </div>
</div>
