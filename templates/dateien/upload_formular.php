<?php
/** @var string $uploadUrl Ziel des Formulars (bereits mit url()) */
$kategorien = ['anleitung' => 'Anleitung', 'schaltplan' => 'Schaltplan', 'gleisplan' => 'Gleisplan', 'rechnung' => 'Rechnung', 'sonstiges' => 'Sonstiges'];
?>
<form method="post" action="<?= e($uploadUrl) ?>" enctype="multipart/form-data" class="card card-body mb-4">
    <?php include dirname(__DIR__) . '/partials/csrf.php'; ?>
    <h2 class="h6">Fotos und Dokumente hochladen</h2>
    <div class="row g-2">
        <div class="col-12 col-md-5">
            <label class="form-label" for="datei-auswahl">Dateien</label>
            <input class="form-control" type="file" id="datei-auswahl" name="datei[]" multiple required accept="<?= e(\Emc\Dateien\DateiTyp::acceptAttribut()) ?>">
            <div class="form-text">Fotos (JPG, PNG, WebP), PDF, Text, Office-Dokumente.</div>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" for="datei-titel">Titel (optional, bei einer Datei)</label>
            <input class="form-control" type="text" id="datei-titel" name="titel" maxlength="200">
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label" for="datei-kategorie">Kategorie (Fotos automatisch „Foto“)</label>
            <select class="form-select" id="datei-kategorie" name="kategorie">
                <?php foreach ($kategorien as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="mt-3"><button type="submit" class="btn btn-primary">Hochladen</button></div>
</form>
