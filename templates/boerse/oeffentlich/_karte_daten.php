<?php
/**
 * Gemeinsame Anzeige eines öffentlichen Angebots (Kategorie, Spurweite, Zustand, Preis, Nummer, „reserviert“).
 *
 * @var array<string,mixed> $a aus AngebotRepository::oeffentliche bzw. oeffentlichNachNummer
 */
use Emc\Magazin\MagazinLabels;
use Emc\Reparatur\ReparaturValidator;
?>
<p class="small text-muted mb-1">
    <?= e((string) $a['kategorie_name']) ?><?php if ($a['spurweite_name'] !== null): ?> · Spur <?= e((string) $a['spurweite_name']) ?><?php endif; ?>
    · <?= e(MagazinLabels::ZUSTAND[$a['zustand']] ?? (string) $a['zustand']) ?>
</p>
<p class="mb-0 d-flex flex-wrap align-items-center gap-2">
    <span class="fs-5 fw-semibold"><?= e(ReparaturValidator::betrag((string) $a['wunschpreis'])) ?></span>
    <span class="badge text-bg-light border"><?= e((string) $a['nummer']) ?></span>
    <?php if ($a['status'] === 'reserviert'): ?><span class="badge text-bg-warning">reserviert</span><?php endif; ?>
</p>
