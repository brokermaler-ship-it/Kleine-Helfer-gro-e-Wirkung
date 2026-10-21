<?php
/** @var list<array{zeitpunkt:string,aktion:string,anzeigename:?string,aenderungen:array<string,array{alt:mixed,neu:mixed}>}> $verlauf */
$verlauf = $verlauf ?? [];
$aktionen = ['angelegt' => 'angelegt', 'geaendert' => 'geändert', 'geloescht' => 'gelöscht'];
$zeige = function (mixed $w): string {
    if ($w === null || $w === '') {
        return '–';
    }
    return is_scalar($w) ? (string) $w : (string) json_encode($w, JSON_UNESCAPED_UNICODE);
};
?>
<section class="mt-4" aria-labelledby="verlauf-titel">
    <h2 class="h5" id="verlauf-titel"><?= e($verlaufTitel ?? 'Verlauf') ?></h2>
    <?php if ($verlauf === []): ?>
        <p class="text-muted">Noch keine Einträge.</p>
    <?php else: ?>
        <ul class="list-group">
            <?php foreach ($verlauf as $eintrag): ?>
                <li class="list-group-item">
                    <div class="small text-muted">
                        <?= e(date('d.m.Y H:i', (int) strtotime($eintrag['zeitpunkt']))) ?>
                        · <?= e($eintrag['anzeigename'] ?? \Emc\Protokoll\Aenderungsprotokoll::EHEMALIGES_MITGLIED) ?>
                        · <?= e($aktionen[$eintrag['aktion']] ?? $eintrag['aktion']) ?>
                    </div>
                    <?php foreach ($eintrag['aenderungen'] as $feld => $wert): ?>
                        <div><strong><?= e((string) $feld) ?></strong>:
                            <?= e($zeige($wert['alt'] ?? null)) ?> &rarr; <?= e($zeige($wert['neu'] ?? null)) ?></div>
                    <?php endforeach; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
