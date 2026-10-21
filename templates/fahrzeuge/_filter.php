<?php
/**
 * Such- und Filterleiste für Fahrzeuglisten.
 *
 * @var \Emc\Fahrzeuge\FahrzeugFilter $filter
 * @var list<array<string,mixed>> $spurweiten
 * @var list<array<string,mixed>> $personen
 * @var string $filterZiel Pfad, an den das Formular geht
 * @var bool $mitStatusfilter
 */
?>
<button class="btn btn-outline-secondary d-md-none mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#filterleiste"
        aria-expanded="<?= $filter->istGesetzt() ? 'true' : 'false' ?>" aria-controls="filterleiste">Suche und Filter</button>
<form method="get" action="<?= e(url($filterZiel)) ?>" id="filterleiste" class="collapse d-md-block card card-body mb-3<?= $filter->istGesetzt() ? ' show' : '' ?>">
    <div class="row g-2">
        <div class="col-12 col-md-4">
            <label for="q" class="form-label small mb-0">Suche</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e((string) $filter->text) ?>" placeholder="Nummer, Bezeichnung, Baureihe …">
            <?php if ($mitStatusfilter): ?>
                <div class="form-text">Die Suche findet auch Fahrzeuge auf dem Abstellgleis.</div>
            <?php endif; ?>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-spurweite" class="form-label small mb-0">Spurweite</label>
            <select class="form-select" id="f-spurweite" name="spurweite">
                <option value="">alle</option>
                <?php foreach ($spurweiten as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= $filter->spurweiteId === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="f-gattung" class="form-label small mb-0">Gattung</label>
            <select class="form-select" id="f-gattung" name="gattung">
                <option value="">alle</option>
                <?php foreach ($gattungen as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $filter->gattung === $wert ? ' selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($mitStatusfilter): ?>
        <div class="col-6 col-md-2">
            <label for="f-status" class="form-label small mb-0">Status</label>
            <select class="form-select" id="f-status" name="status">
                <option value=""><?= $filter->text === null ? 'ohne Abstellgleis' : 'alle (auch Abstellgleis)' ?></option>
                <?php foreach ($statusLabels as $wert => $text): ?>
                    <option value="<?= e($wert) ?>"<?= $filter->status === $wert ? ' selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-6 col-md-2">
            <label for="f-eigentuemer" class="form-label small mb-0">Eigentum</label>
            <select class="form-select" id="f-eigentuemer" name="eigentuemer">
                <option value="">alle</option>
                <option value="verein"<?= $filter->eigentuemer === 'verein' ? ' selected' : '' ?>>Verein</option>
                <option value="mitglied"<?= $filter->eigentuemer === 'mitglied' ? ' selected' : '' ?>>Mitglieder (extern)</option>
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label for="f-person" class="form-label small mb-0">Eigentümer</label>
            <select class="form-select" id="f-person" name="person">
                <option value="">alle</option>
                <?php foreach ($personen as $pe): ?>
                    <option value="<?= (int) $pe['id'] ?>"<?= $filter->personId === (int) $pe['id'] ? ' selected' : '' ?>><?= e($pe['nachname'] . ', ' . $pe['vorname']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($mitStatusfilter): ?>
        <div class="col-12 col-md-4 d-flex align-items-end">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="f-ungeprueft" name="ungeprueft" value="1"<?= $filter->ungeprueft ? ' checked' : '' ?>>
                <label class="form-check-label" for="f-ungeprueft">nur ungeprüfte (importierte)</label>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-12 col-md-8 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-primary">Suchen</button>
            <a class="btn btn-link" href="<?= e(url($filterZiel)) ?>">Zurücksetzen</a>
        </div>
    </div>
</form>
