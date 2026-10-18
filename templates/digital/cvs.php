<?php
/**
 * @var array<string,mixed> $decoder
 * @var array<string,mixed> $fahrzeug
 * @var list<array<string,mixed>> $cvs
 * @var array<string,string> $werte
 * @var array<string,string> $fehler
 */
$zurueck = url('/fahrzeuge/' . $fahrzeug['id']) . '#digital';
$bearbeiten = $darfFz($fahrzeug);
?>
<h1 class="h3 mb-1">CVs: <?= e($decoder['bezeichnung']) ?></h1>
<p class="text-muted"><a href="<?= e($zurueck) ?>"><?= e($fahrzeug['inventarnummer']) ?> <?= e($fahrzeug['bezeichnung']) ?></a></p>

<?php if ($cvs === []): ?>
    <p class="text-muted">Für diesen Decoder sind noch keine CVs erfasst.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th scope="col">CV</th><th scope="col">Wert</th><th scope="col">Kommentar</th><?php if ($bearbeiten): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($cvs as $z): ?>
                <tr>
                    <th scope="row"><?= (int) $z['cv'] ?></th>
                    <td><?= (int) $z['wert'] ?></td>
                    <td><?= e($z['kommentar']) ?></td>
                    <?php if ($bearbeiten): ?>
                        <td class="text-end">
                            <form method="post" action="<?= e(url('/decoder/' . $decoder['id'] . '/cvs/' . $z['cv'] . '/loeschen')) ?>"
                                  onsubmit="return confirm('CV <?= (int) $z['cv'] ?> löschen?');">
                                <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($bearbeiten): ?>
    <section aria-labelledby="cv-neu">
        <h2 class="h5" id="cv-neu">CV speichern</h2>
        <p class="small text-muted">Ist die CV schon vorhanden, wird ihr Wert überschrieben.</p>
        <form method="post" action="<?= e(url('/decoder/' . $decoder['id'] . '/cvs')) ?>" class="row g-2" novalidate>
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <div class="col-6 col-md-2">
                <label for="cv" class="form-label">CV</label>
                <input type="number" class="form-control<?= isset($fehler['cv']) ? ' is-invalid' : '' ?>" id="cv" name="cv" min="1" max="1024" inputmode="numeric" value="<?= e($werte['cv'] ?? '') ?>" required>
                <?php if (isset($fehler['cv'])): ?><div class="invalid-feedback"><?= e($fehler['cv']) ?></div><?php endif; ?>
            </div>
            <div class="col-6 col-md-2">
                <label for="wert" class="form-label">Wert</label>
                <input type="number" class="form-control<?= isset($fehler['wert']) ? ' is-invalid' : '' ?>" id="wert" name="wert" min="0" max="255" inputmode="numeric" value="<?= e($werte['wert'] ?? '') ?>" required>
                <?php if (isset($fehler['wert'])): ?><div class="invalid-feedback"><?= e($fehler['wert']) ?></div><?php endif; ?>
            </div>
            <div class="col-12 col-md-5">
                <label for="kommentar" class="form-label">Kommentar</label>
                <input type="text" class="form-control<?= isset($fehler['kommentar']) ? ' is-invalid' : '' ?>" id="kommentar" name="kommentar" maxlength="200" value="<?= e($werte['kommentar'] ?? '') ?>">
                <?php if (isset($fehler['kommentar'])): ?><div class="invalid-feedback"><?= e($fehler['kommentar']) ?></div><?php endif; ?>
            </div>
            <div class="col-12 col-md-auto align-self-end"><button type="submit" class="btn btn-primary">Speichern</button></div>
        </form>
    </section>
<?php endif; ?>
