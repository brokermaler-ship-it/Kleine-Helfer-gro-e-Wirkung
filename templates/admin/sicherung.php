<?php /** @var ?\DateTimeImmutable $letzte */ /** @var array{anzahl:int,ziel:string} $nurOneDrive */ ?>
<h1 class="h3 mb-3">Datensicherung</h1>
<?php if (($nurOneDrive['anzahl'] ?? 0) > 0): ?>
    <div class="alert alert-warning">
        <?= (int) $nurOneDrive['anzahl'] ?> <?= (int) $nurOneDrive['anzahl'] === 1 ? 'Original liegt' : 'Originale liegen' ?> nur im OneDrive
        (Zielordner: <?= e($nurOneDrive['ziel'] !== '' ? $nurOneDrive['ziel'] : 'unbekannt') ?>) und <?= (int) $nurOneDrive['anzahl'] === 1 ? 'ist' : 'sind' ?> in dieser Sicherung nicht enthalten.
        Bitte den OneDrive-Ordner zusätzlich sichern.
    </div>
<?php endif; ?>
<div class="card mb-4">
    <div class="card-body">
        <p class="mb-2">Letzte Sicherung:
            <strong><?= $letzte === null ? 'noch nie' : e($letzte->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i')) ?></strong>
        </p>
        <p class="text-muted">Die Sicherung enthält alle Daten der Datenbank sowie alle hochgeladenen Dateien (Fotos, Anleitungen usw.) in einer ZIP-Datei.
            Bitte speichere sie an einem sicheren Ort außerhalb des Webspace.</p>
        <form method="post" action="<?= e(url('/admin/sicherung')) ?>">
            <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
            <button type="submit" class="btn btn-primary">Sicherung herunterladen</button>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-body">
        <h2 class="h6">So stellst du eine Sicherung wieder her</h2>
        <ol class="mb-0">
            <li>ZIP-Datei auf dem Computer entpacken.</li>
            <li>Die Datei <code>datenbank.sql</code> im Verwaltungswerkzeug des Hosters (z. B. phpMyAdmin) in die leere Datenbank importieren.</li>
            <li>Den Ordner <code>dateien/</code> per FTP nach <code>storage/dateien/</code> hochladen.</li>
        </ol>
    </div>
</div>
