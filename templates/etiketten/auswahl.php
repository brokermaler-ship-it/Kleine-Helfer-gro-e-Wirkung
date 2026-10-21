<?php
/** @var string $art */
/** @var string $auswahlFahrzeug */
/** @var string $auswahlAnlage */
/** @var list<array<string,mixed>> $einzelFahrzeuge */
/** @var list<array<string,mixed>> $einzelAnlagen */
/** @var list<array<string,mixed>> $spurweiten */
/** @var ?int $spurweiteId */
/** @var array<int,int> $anzahlJeSpurweite */
/** @var int $anzahlLoks */
/** @var int $anzahlFahrzeugeUngedruckt */
/** @var int $anzahlAnlagen */
/** @var int $anzahlAnlagenUngedruckt */
/** @var bool $urlFehlt */
/** @var string $qrBasis */
/** @var list<string> $ohneCodeUngedruckt */
/** @var int $startFahrzeug */
/** @var int $startAnlage */
/** @var \Emc\Etiketten\Bogen $klebchen */
/** @var \Emc\Etiketten\Bogen $anlageBogen */
use Emc\Codes\Code;
use Emc\Etiketten\EtikettController;

$anzahlText = fn(int $n): string => $n === 1 ? '1 Fahrzeug' : $n . ' Fahrzeuge';
$anlagenText = fn(int $n): string => $n === 1 ? '1 Anlage' : $n . ' Anlagen';
$fzAuswahl = $einzelFahrzeuge !== [] ? $auswahlFahrzeug : ($auswahlFahrzeug === 'einzeln' ? 'ungedruckt' : $auswahlFahrzeug);
$anlAuswahl = $einzelAnlagen !== [] ? $auswahlAnlage : ($auswahlAnlage === 'einzeln' ? 'ungedruckt' : $auswahlAnlage);
?>
<h1 class="h3 mb-3">Etiketten</h1>
<p class="text-muted">Die Druckseite zeigt die Etiketten auf einem A4-Bogen in Originalgröße. Beim Drucken „Tatsächliche Größe“ bzw. 100&nbsp;% und Ränder „Keine“ wählen. Bei einem neuen Bogen zuerst einen Probedruck auf Normalpapier machen und gegen den Bogen halten.</p>

<div class="row g-4">
    <div class="col-lg-6">
        <form method="get" action="<?= e(url('/etiketten/druck')) ?>" class="card h-100">
            <div class="card-body">
                <h2 class="h5 card-title">Fahrzeuge</h2>
                <fieldset class="mb-3">
                    <legend class="form-label fs-6">Art</legend>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="art" id="art-klebchen" value="klebchen"<?= $art !== 'verpackung' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="art-klebchen">Klebchen – nur der Code (z.&nbsp;B. F12), <?= e(str_replace('.', ',', (string) $klebchen->breite)) ?> × <?= e((string) $klebchen->hoehe) ?> mm, <?= $klebchen->proBogen() ?> je Bogen (z.&nbsp;B. Avery Zweckform L4731)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="art" id="art-verpackung" value="verpackung"<?= $art === 'verpackung' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="art-verpackung">Verpackung – Code und Bezeichnung (gleicher Bogen)</label>
                    </div>
                </fieldset>
                <fieldset class="mb-3">
                    <legend class="form-label fs-6">Auswahl</legend>
                    <?php if ($einzelFahrzeuge !== []): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="auswahl" id="fz-einzeln" value="einzeln"<?= $fzAuswahl === 'einzeln' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="fz-einzeln">Einzeln:</label>
                        <ul class="list-unstyled ms-1 mt-1">
                            <?php foreach ($einzelFahrzeuge as $f): ?>
                            <?php $code = Code::fahrzeug((string) $f['inventarnummer']); ?>
                            <li class="form-check">
                                <input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $f['id'] ?>" id="fz-<?= (int) $f['id'] ?>" checked>
                                <label class="form-check-label" for="fz-<?= (int) $f['id'] ?>"><strong><?= e($code ?? '–') ?></strong> <?= e($f['bezeichnung']) ?> <span class="text-muted">(<?= e($f['inventarnummer']) ?><?= $code === null ? ', ohne Code' : '' ?>)</span></label>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="auswahl" id="fz-ungedruckt" value="ungedruckt"<?= $fzAuswahl === 'ungedruckt' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="fz-ungedruckt">Noch nicht gedruckt (<?= e($anzahlText($anzahlFahrzeugeUngedruckt)) ?>)</label>
                        <?php if ($ohneCodeUngedruckt !== []): ?>
                        <div class="form-text"><?= e($anzahlText(count($ohneCodeUngedruckt))) ?> ohne Code (Inventarnummer nicht im Format EMC-&lt;Zahl&gt;, daher kein Klebchen): <?= e(implode(', ', array_slice($ohneCodeUngedruckt, 0, 30))) ?><?= count($ohneCodeUngedruckt) > 30 ? ' …' : '' ?></div>
                        <?php endif; ?>
                        <div class="form-text">Der Vermerk „gedruckt“ gilt für Klebchen und Verpackung gemeinsam.</div>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="auswahl" id="fz-loks" value="loks"<?= $fzAuswahl === 'loks' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="fz-loks">Alle Loks und Triebwagen (<?= e($anzahlText($anzahlLoks)) ?>)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="auswahl" id="fz-spurweite" value="spurweite"<?= $fzAuswahl === 'spurweite' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="fz-spurweite">Alle einer Spurweite:</label>
                        <label for="spurweite_id" class="visually-hidden">Spurweite</label>
                        <select class="form-select form-select-sm d-inline-block w-auto ms-1" name="spurweite_id" id="spurweite_id" onchange="document.getElementById('fz-spurweite').checked = true">
                            <?php foreach ($spurweiten as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"<?= $spurweiteId === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?> (<?= (int) ($anzahlJeSpurweite[(int) $s['id']] ?? 0) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-text">Ausgemusterte Fahrzeuge nur einzeln (Seite des Fahrzeugs › „Etikett drucken“).</div>
                </fieldset>
                <div class="mb-3">
                    <label class="form-label" for="start-fz">Startposition auf dem Bogen</label>
                    <input class="form-control" type="number" name="start" id="start-fz" min="1" max="<?= $klebchen->proBogen() ?>" value="<?= (int) $startFahrzeug ?>" inputmode="numeric" style="max-width: 8rem">
                    <div class="form-text">1–<?= $klebchen->proBogen() ?>, zeilenweise von links oben (<?= $klebchen->spalten ?> je Zeile) – so lässt sich ein angefangener Bogen weiterverwenden.</div>
                </div>
                <button type="submit" class="btn btn-primary">Druckseite öffnen</button>
            </div>
        </form>
    </div>
    <div class="col-lg-6">
        <form method="get" action="<?= e(url('/etiketten/druck')) ?>" class="card h-100">
            <input type="hidden" name="art" value="anlage">
            <div class="card-body">
                <h2 class="h5 card-title">Anlagen</h2>
                <p class="mb-3">Etikett <?= e((string) $anlageBogen->breite) ?> × <?= e((string) $anlageBogen->hoehe) ?> mm (<?= $anlageBogen->proBogen() ?> je Bogen, z.&nbsp;B. Avery Zweckform 3474 – nicht 3475, der hat 70 × 36 mm) mit QR-Code (Kurzlink zur Anlage), Name und Code.</p>
                <?php if ($urlFehlt): ?>
                <div class="alert alert-warning" role="alert"><?= e(EtikettController::URL_WARNUNG) ?> Die QR-Codes zeigen jetzt auf <strong><?= e($qrBasis) ?>/c/…</strong>.</div>
                <?php endif; ?>
                <fieldset class="mb-3">
                    <legend class="form-label fs-6">Auswahl</legend>
                    <?php if ($einzelAnlagen !== []): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="auswahl" id="anl-einzeln" value="einzeln"<?= $anlAuswahl === 'einzeln' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="anl-einzeln">Einzeln:</label>
                        <ul class="list-unstyled ms-1 mt-1">
                            <?php foreach ($einzelAnlagen as $a): ?>
                            <li class="form-check">
                                <input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $a['id'] ?>" id="anl-<?= (int) $a['id'] ?>" checked>
                                <label class="form-check-label" for="anl-<?= (int) $a['id'] ?>"><strong><?= e(\Emc\Anlagen\AnlagenCode::formatiere((int) $a['nummer'])) ?></strong> <?= e($a['name']) ?></label>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="auswahl" id="anl-ungedruckt" value="ungedruckt"<?= $anlAuswahl === 'ungedruckt' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="anl-ungedruckt">Noch nicht gedruckt (<?= e($anlagenText($anzahlAnlagenUngedruckt)) ?>)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="auswahl" id="anl-alle" value="alle"<?= $anlAuswahl === 'alle' ? ' checked' : '' ?>>
                        <label class="form-check-label" for="anl-alle">Alle Anlagen (<?= e($anlagenText($anzahlAnlagen)) ?>)</label>
                    </div>
                </fieldset>
                <div class="mb-3">
                    <label class="form-label" for="start-anl">Startposition auf dem Bogen</label>
                    <input class="form-control" type="number" name="start" id="start-anl" min="1" max="<?= $anlageBogen->proBogen() ?>" value="<?= (int) $startAnlage ?>" inputmode="numeric" style="max-width: 8rem">
                    <div class="form-text">1–<?= $anlageBogen->proBogen() ?>, zeilenweise von links oben (<?= $anlageBogen->spalten ?> je Zeile).</div>
                </div>
                <button type="submit" class="btn btn-primary">Druckseite öffnen</button>
            </div>
        </form>
    </div>
</div>
