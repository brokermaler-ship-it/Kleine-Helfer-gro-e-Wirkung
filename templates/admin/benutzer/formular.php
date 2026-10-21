<?php
/** @var ?array<string,mixed> $benutzerEintrag */ /** @var array<string,mixed> $werte */ /** @var array<string,string> $fehler */
/** @var list<array<string,mixed>> $personen */ /** @var list<\Emc\Auth\Rolle> $rollen */
$neu = $benutzerEintrag === null;
$ungueltig = fn(string $n): string => isset($fehler[$n]) ? ' is-invalid' : '';
$meldung = function (string $n) use ($fehler): void {
    if (isset($fehler[$n])) { echo '<div class="invalid-feedback">' . e($fehler[$n]) . '</div>'; }
};
$rollenAuswahl = function () use ($rollen, $werte, $ungueltig, $meldung): void { ?>
    <div class="mb-3">
        <label for="rolle" class="form-label">Rolle</label>
        <select class="form-select<?= $ungueltig('rolle') ?>" id="rolle" name="rolle">
            <?php foreach ($rollen as $rolle): ?>
                <option value="<?= e($rolle->value) ?>"<?= ($werte['rolle'] ?? '') === $rolle->value ? ' selected' : '' ?>><?= e($rolle->label()) ?></option>
            <?php endforeach; ?>
        </select>
        <?php $meldung('rolle'); ?>
    </div>
<?php };
$boersenwartFeld = function () use ($werte): void { ?>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="ist_boersenwart" name="ist_boersenwart" value="1"<?= !empty($werte['ist_boersenwart']) ? ' checked' : '' ?> aria-describedby="ist_boersenwart_hilfe">
        <label class="form-check-label" for="ist_boersenwart">Börsenwart (Magazin und Modellbahnbörse)</label>
        <div class="form-text" id="ist_boersenwart_hilfe">Zusatzaufgabe, unabhängig von der Rolle. Bearbeiter und Administratoren dürfen das ohnehin.</div>
    </div>
<?php }; ?>
<div class="row">
    <div class="col-lg-6">
        <h1 class="h3 mb-3"><?= e($titel) ?></h1>
        <?php if (isset($fehler['allgemein'])): ?><div class="alert alert-danger" role="alert"><?= e($fehler['allgemein']) ?></div><?php endif; ?>
        <?php if ($neu): ?>
            <form method="post" action="<?= e(url('/admin/benutzer')) ?>">
                <?php include dirname(__DIR__, 2) . '/partials/csrf.php'; ?>
                <div class="mb-3">
                    <label for="person_id" class="form-label">Person</label>
                    <select class="form-select<?= $ungueltig('person_id') ?>" id="person_id" name="person_id" required>
                        <option value="">– bitte wählen –</option>
                        <?php foreach ($personen as $p): ?>
                            <option value="<?= e((string) $p['id']) ?>"<?= (string) ($werte['person_id'] ?? '') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['nachname'] . ', ' . $p['vorname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php $meldung('person_id'); ?>
                    <div class="form-text">Nur Personen ohne Zugang. Neue Personen zuerst unter „Personen“ anlegen.</div>
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">E-Mail (Benutzername)</label>
                    <input type="email" class="form-control<?= $ungueltig('email') ?>" id="email" name="email" value="<?= e((string) ($werte['email'] ?? '')) ?>" required>
                    <?php $meldung('email'); ?>
                </div>
                <?php $rollenAuswahl(); ?>
                <?php $boersenwartFeld(); ?>
                <div class="mb-3">
                    <label for="passwort" class="form-label">Startpasswort (mindestens 10 Zeichen)</label>
                    <input type="text" class="form-control<?= $ungueltig('passwort') ?>" id="passwort" name="passwort" autocomplete="off" required>
                    <?php $meldung('passwort'); ?>
                    <div class="form-text">Muss beim ersten Anmelden geändert werden.</div>
                </div>
                <button type="submit" class="btn btn-primary">Anlegen</button>
                <a class="btn btn-link" href="<?= e(url('/admin/benutzer')) ?>">Abbrechen</a>
            </form>
        <?php else: ?>
            <p><strong><?= e($benutzerEintrag['vorname'] . ' ' . $benutzerEintrag['nachname']) ?></strong> · <?= e($benutzerEintrag['email']) ?></p>
            <form method="post" action="<?= e(url('/admin/benutzer/' . $benutzerEintrag['id'])) ?>">
                <?php include dirname(__DIR__, 2) . '/partials/csrf.php'; ?>
                <?php $rollenAuswahl(); ?>
                <?php $boersenwartFeld(); ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="aktiv" name="aktiv" value="1"<?= !empty($werte['aktiv']) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="aktiv">Zugang aktiv</label>
                </div>
                <button type="submit" class="btn btn-primary">Speichern</button>
                <a class="btn btn-link" href="<?= e(url('/admin/benutzer')) ?>">Abbrechen</a>
            </form>
            <hr>
            <h2 class="h5">Passwort zurücksetzen</h2>
            <form method="post" action="<?= e(url('/admin/benutzer/' . $benutzerEintrag['id'] . '/passwort')) ?>">
                <?php include dirname(__DIR__, 2) . '/partials/csrf.php'; ?>
                <div class="mb-3">
                    <label for="passwort" class="form-label">Neues Startpasswort (mindestens 10 Zeichen)</label>
                    <input type="text" class="form-control<?= $ungueltig('passwort') ?>" id="passwort" name="passwort" autocomplete="off" required>
                    <?php $meldung('passwort'); ?>
                    <div class="form-text">Gib das Passwort der Person persönlich weiter. Sie muss es beim nächsten Anmelden ändern.</div>
                </div>
                <button type="submit" class="btn btn-outline-warning">Startpasswort setzen</button>
            </form>
            <?php include dirname(__DIR__, 2) . '/partials/verlauf.php'; ?>
        <?php endif; ?>
    </div>
</div>
