<?php
/** @var array<string,mixed> $werte */ /** @var array<string,string> $fehler */ /** @var ?array<string,mixed> $person */
$aktion = $person === null ? url('/personen') : url('/personen/' . $person['id']);
$feld = function (string $name, string $label, string $typ = 'text', bool $pflicht = false) use ($werte, $fehler): void { ?>
    <div class="mb-3">
        <label for="<?= e($name) ?>" class="form-label"><?= e($label) ?></label>
        <input type="<?= e($typ) ?>" class="form-control<?= isset($fehler[$name]) ? ' is-invalid' : '' ?>" id="<?= e($name) ?>"
               name="<?= e($name) ?>" value="<?= e((string) ($werte[$name] ?? '')) ?>"<?= $pflicht ? ' required' : '' ?>>
        <?php if (isset($fehler[$name])): ?><div class="invalid-feedback"><?= e($fehler[$name]) ?></div><?php endif; ?>
    </div>
<?php }; ?>
<div class="row">
    <div class="col-lg-6">
        <h1 class="h3 mb-3"><?= e($titel) ?></h1>
        <form method="post" action="<?= e($aktion) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <?php $feld('vorname', 'Vorname', 'text', true); ?>
            <?php $feld('nachname', 'Nachname', 'text', true); ?>
            <?php $feld('email', 'E-Mail', 'email'); ?>
            <?php $feld('telefon', 'Telefon', 'tel'); ?>
            <div class="mb-3">
                <label for="notiz" class="form-label">Notiz</label>
                <textarea class="form-control" id="notiz" name="notiz" rows="3"><?= e((string) ($werte['notiz'] ?? '')) ?></textarea>
            </div>
            <?php if ($person !== null): ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="aktiv" name="aktiv" value="1"<?= !empty($werte['aktiv']) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="aktiv">Aktiv</label>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">Speichern</button>
            <a class="btn btn-link" href="<?= e(url('/personen')) ?>">Abbrechen</a>
        </form>
        <?php if ($person !== null): ?>
            <?php include dirname(__DIR__) . '/partials/verlauf.php'; ?>
        <?php endif; ?>
    </div>
</div>
