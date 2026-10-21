<?php
/**
 * Sammlung anlegen bzw. bearbeiten: Name, Notiz und Artikel (nur solche ohne andere Sammlung).
 *
 * @var ?array<string,mixed> $sammlung
 * @var array{name:string, notiz:string, artikel:list<int>} $werte
 * @var array<string,string> $fehler
 * @var ?string $meldung
 * @var string $stand
 * @var list<array<string,mixed>> $auswahl aus MagazinRepository::artikelFuerSammlung
 */
$neu = $sammlung === null;
$aktion = $neu ? url('/magazin/sammlungen') : url('/magazin/sammlungen/' . (int) $sammlung['id']);
$abbrechen = $neu ? url('/magazin/sammlungen') : url('/magazin/sammlungen/' . (int) $sammlung['id']);
$gewaehlt = array_flip($werte['artikel']);
?>
<div class="row">
    <div class="col-lg-8">
        <h1 class="h3 mb-3"><?= e($titel) ?></h1>
        <?php if ($meldung !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($meldung) ?></div>
        <?php elseif ($fehler !== []): ?>
            <div class="alert alert-danger" role="alert">Bitte prüfe die markierten Felder.</div>
        <?php endif; ?>
        <form method="post" action="<?= e($aktion) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php if (!$neu): ?><input type="hidden" name="stand" value="<?= e($stand) ?>"><?php endif; ?>
            <div class="mb-3">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control<?= isset($fehler['name']) ? ' is-invalid' : '' ?>" id="name" name="name" maxlength="150" value="<?= e($werte['name']) ?>" required>
                <div class="form-text">z. B. Nachlass Märklin-Gleise, 3 Kisten</div>
                <?php if (isset($fehler['name'])): ?><div class="invalid-feedback d-block"><?= e($fehler['name']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label for="notiz" class="form-label">Notiz</label>
                <textarea class="form-control<?= isset($fehler['notiz']) ? ' is-invalid' : '' ?>" id="notiz" name="notiz" rows="3" maxlength="5000"><?= e($werte['notiz']) ?></textarea>
                <?php if (isset($fehler['notiz'])): ?><div class="invalid-feedback d-block"><?= e($fehler['notiz']) ?></div><?php endif; ?>
            </div>
            <fieldset class="mb-3">
                <legend class="h6">Artikel</legend>
                <?php if ($auswahl === []): ?>
                    <p class="text-muted small">Es gibt keine Artikel ohne Sammlung.</p>
                <?php else: ?>
                    <p class="form-text mt-0">Ein Artikel gehört höchstens zu einer Sammlung; Artikel anderer Sammlungen fehlen hier.</p>
                    <div class="list-group sammlung-auswahl">
                        <?php foreach ($auswahl as $a): ?>
                            <label class="list-group-item d-flex gap-2 align-items-start">
                                <input class="form-check-input flex-shrink-0 mt-1" type="checkbox" name="artikel[]" value="<?= (int) $a['id'] ?>"<?= isset($gewaehlt[(int) $a['id']]) ? ' checked' : '' ?>>
                                <span class="min-w-0 text-break"><span class="fw-bold"><?= e((string) $a['code']) ?></span> <?= e((string) $a['bezeichnung']) ?>
                                    <span class="small text-muted">· Bestand <?= (int) $a['bestand'] ?><?= $a['lagerort'] !== null ? ' · ' . e((string) $a['lagerort']) : '' ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>
            <button type="submit" class="btn btn-primary">Speichern</button>
            <a class="btn btn-link" href="<?= e($abbrechen) ?>">Abbrechen</a>
        </form>
    </div>
</div>
