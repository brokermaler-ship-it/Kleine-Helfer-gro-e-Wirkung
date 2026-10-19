<?php
/** @var ?string $rueckrufUrl */ /** @var bool $urlFehlt */ /** @var bool $konfiguriert */ /** @var string $schluesselVorschlag */
/** @var bool $clientIdFehlt */ /** @var bool $secretFehlt */ /** @var bool $schluesselFehlt */
/** @var string $status */ /** @var string $konto */ /** @var ?array{drive_id:string,item_id:string,name:string} $ziel */
/** @var array{wartet:int,fehler:int,erledigt:int} $zaehler */ /** @var string $letzterFehler */
/** @var list<array{dokument_id:?int,art:string,versuche:int,letzter_fehler:string}> $letzteFehler */
$statusText = ['verbunden' => 'verbunden', 'abgelaufen' => 'abgelaufen – bitte neu verbinden', 'getrennt' => 'getrennt'];
$statusKlasse = ['verbunden' => 'success', 'abgelaufen' => 'warning', 'getrennt' => 'secondary'];
?>
<h1 class="h3 mb-3">OneDrive</h1>
<p class="text-muted">Fotos und Dokumente werden zusätzlich im OneDrive-Ordner des Vereins abgelegt. Ohne Anbindung bleibt alles lokal.</p>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h6">Rückruf-URL</h2>
        <p class="mb-1">Diese Adresse bei der App-Registrierung (Microsoft Entra) als Weiterleitungs-URI eintragen:</p>
        <?php if ($rueckrufUrl !== null): ?>
            <input type="text" class="form-control font-monospace" readonly value="<?= e($rueckrufUrl) ?>" onclick="this.select()" aria-label="Rückruf-URL">
        <?php else: ?>
            <p class="text-warning-emphasis mb-0">Erst <code>oeffentliche_url</code> in der <code>config.php</code> setzen (z. B. <code>https://mitglieder.emc78.de</code>, nur https); dann erscheint hier die Rückruf-URL.</p>
        <?php endif; ?>
    </div>
</div>

<?php if (!$konfiguriert): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6">OneDrive einrichten</h2>
            <ol>
                <li>Unter <code>entra.microsoft.com</code> eine App registrieren (Kontotyp: persönliche Microsoft-Konten) und oben stehende Rückruf-URL als Web-Weiterleitungs-URI eintragen.</li>
                <li>Ein Client-Secret erzeugen.</li>
                <li>In der <code>config.php</code> eintragen:
                    <ul>
                        <li><code>oeffentliche_url</code> <?= $urlFehlt ? '<span class="badge text-bg-warning">fehlt oder nicht https</span>' : '<span class="badge text-bg-success">gesetzt</span>' ?></li>
                        <li><code>onedrive_client_id</code> <?= $clientIdFehlt ? '<span class="badge text-bg-warning">fehlt</span>' : '<span class="badge text-bg-success">gesetzt</span>' ?></li>
                        <li><code>onedrive_client_secret</code> <?= $secretFehlt ? '<span class="badge text-bg-warning">fehlt</span>' : '<span class="badge text-bg-success">gesetzt</span>' ?></li>
                        <li><code>app_schluessel</code> <?= $schluesselFehlt ? '<span class="badge text-bg-warning">fehlt oder ungültig</span>' : '<span class="badge text-bg-warning">ungültig (32 Byte, base64)</span>' ?></li>
                    </ul>
                </li>
            </ol>
            <p class="mb-1">Vorschlag für <code>app_schluessel</code> (wird nicht gespeichert; nach dem Verbinden nicht mehr ändern):</p>
            <code id="schluessel-vorschlag" class="user-select-all"><?= e($schluesselVorschlag) ?></code>
            <p class="text-muted mt-3 mb-0">Danach diese Seite neu laden.</p>
        </div>
    </div>
<?php else: ?>
    <?php if ($letzterFehler !== ''): ?>
        <div class="alert alert-danger">Letzter Fehler bei der Anmeldung: <?= e($letzterFehler) ?></div>
    <?php endif; ?>
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6">Verbindung</h2>
            <p class="mb-1">Status: <span class="badge text-bg-<?= e($statusKlasse[$status] ?? 'secondary') ?>"><?= e($statusText[$status] ?? $status) ?></span></p>
            <p class="mb-1">Konto: <strong><?= $konto !== '' ? e($konto) : '–' ?></strong></p>
            <p class="mb-3">Zielordner: <strong><?= $ziel !== null ? e($ziel['name']) : 'noch nicht gewählt' ?></strong></p>
            <div class="d-flex flex-wrap gap-2">
                <form method="post" action="<?= e(url('/admin/onedrive/verbinden')) ?>">
                    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                    <button type="submit" class="btn btn-primary"><?= $status === 'verbunden' ? 'Neu verbinden' : 'Mit OneDrive verbinden' ?></button>
                </form>
                <?php if ($status === 'verbunden'): ?>
                    <a class="btn btn-outline-primary" href="<?= e(url('/admin/onedrive/ordner')) ?>">Zielordner wählen</a>
                <?php endif; ?>
                <?php if ($status !== 'getrennt'): ?>
                    <form method="post" action="<?= e(url('/admin/onedrive/trennen')) ?>" onsubmit="return confirm('OneDrive wirklich trennen? Neue Dateien werden dann nur noch lokal gespeichert.');">
                        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                        <button type="submit" class="btn btn-outline-danger">Trennen</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6">Warteschlange</h2>
            <p class="mb-3">
                <span class="badge text-bg-secondary"><?= (int) $zaehler['wartet'] ?> wartet</span>
                <span class="badge text-bg-<?= (int) $zaehler['fehler'] > 0 ? 'danger' : 'secondary' ?>"><?= (int) $zaehler['fehler'] ?> Fehler</span>
                <span class="badge text-bg-success"><?= (int) $zaehler['erledigt'] ?> erledigt</span>
            </p>
            <?php if ($letzteFehler !== []): ?>
                <h3 class="h6">Letzte Fehler</h3>
                <ul class="small">
                    <?php foreach ($letzteFehler as $f): ?>
                        <li><?= e($f['art'] === 'loeschen' ? 'Löschen' : 'Hochladen') ?><?= $f['dokument_id'] !== null ? ' (Dokument ' . (int) $f['dokument_id'] . ')' : '' ?>,
                            <?= (int) $f['versuche'] ?> Versuch(e): <?= e($f['letzter_fehler']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($status === 'verbunden' && $ziel !== null): ?>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" action="<?= e(url('/admin/onedrive/uebertragen')) ?>">
                        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                        <button type="submit" class="btn btn-outline-primary">Jetzt übertragen</button>
                    </form>
                    <form method="post" action="<?= e(url('/admin/onedrive/umzug')) ?>">
                        <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
                        <button type="submit" class="btn btn-outline-primary">Vorhandene Dateien nach OneDrive umziehen</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
