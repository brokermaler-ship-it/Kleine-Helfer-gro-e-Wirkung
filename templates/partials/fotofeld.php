<?php
/**
 * Auswahlfeld für Fotos (Defekt melden, Fotos an einer Reparatur). assets/app.js verkleinert die Bilder vor dem
 * Absenden (längste Seite 2000 px, JPEG) und hält das Formular an, wenn sie zusammen zu groß sind; ohne JavaScript
 * gehen die Originale hinaus, die Grenzen nennt der Hilfetext.
 *
 * @var string $fotoId ID des Eingabefelds
 * @var string $fotoBeschriftung Beschriftung (wird escaped)
 * @var bool $fotoGross große Darstellung (Handy-Formular)
 * @var \Emc\Dateien\Uploadgrenzen $uploadGrenzen
 */
$fotoGross = $fotoGross ?? false;
?>
<label for="<?= e($fotoId) ?>" class="form-label<?= $fotoGross ? ' fw-semibold' : ' small mb-0' ?>"><?= e($fotoBeschriftung) ?></label>
<input type="file" class="form-control<?= $fotoGross ? ' form-control-lg' : '' ?>" id="<?= e($fotoId) ?>" name="fotos[]"
       accept="image/jpeg,image/png,image/webp,image/heic,image/heif" multiple
       data-foto-feld data-max-datei="<?= (int) $uploadGrenzen->dateiBytes ?>" data-max-gesamt="<?= (int) $uploadGrenzen->fotoBytes() ?>"
       aria-describedby="<?= e($fotoId) ?>-hilfe">
<div class="form-text" id="<?= e($fotoId) ?>-hilfe">
    <?= $fotoGross ? 'Mit der Kamera aufnehmen oder aus der Galerie wählen, auch mehrere. ' : '' ?>JPEG, PNG, WebP oder HEIC,
    <?= e($uploadGrenzen->text()) ?>. Große Fotos werden vor dem Senden automatisch verkleinert.
</div>
<div class="alert alert-danger mt-2 mb-0" role="alert" data-foto-fehler hidden></div>
