<?php
/** @var ?\Emc\Auth\Benutzer $benutzer */
/** @var callable $darf */
$darf = $darf ?? fn(string $aktion): bool => false;
$benutzer = $benutzer ?? null;
/** @var ?callable $abstellgleisAnzahl liefert die Anzahl ausgemusterter Fahrzeuge (nur angemeldet abgefragt) */
$abstellgleisAnzahl = $abstellgleisAnzahl ?? null;
/** @var ?callable $neueDefekte liefert die Anzahl neuer Defektmeldungen oder null (ohne Recht auf Defekthinweise) */
$neueDefekte = $neueDefekte ?? null;
use Emc\Auth\Berechtigung;
?>
<nav class="navbar navbar-expand-xl emc-navbar navbar-dark">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(url('/')) ?>" aria-label="<?= e($app_name ?? '') ?>">
            <span class="navbar-logo"><img src="<?= e(url('assets/marke/logo-128.png')) ?>" alt="" width="36" height="36"></span>
            <span<?= $benutzer !== null ? ' class="d-none d-sm-inline d-xl-none d-xxl-inline"' : '' ?>>Lokschuppen</span>
        </a>
        <?php if ($benutzer !== null): ?>
        <form method="get" action="<?= e(url('/code')) ?>" role="search" class="emc-codefeld d-flex ms-auto me-2 order-xl-last ms-xl-3 me-xl-0">
            <label for="code-q" class="visually-hidden">Code</label>
            <input type="search" class="form-control form-control-sm" id="code-q" name="q" autocapitalize="characters"
                   autocomplete="off" spellcheck="false" placeholder="Code, z. B. F12">
        </form>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#hauptmenue"
                aria-controls="hauptmenue" aria-expanded="false" aria-label="Menü umschalten">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="hauptmenue">
            <ul class="navbar-nav me-auto mb-2 mb-xl-0">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Fahrzeuge</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= e(url('/fahrzeuge')) ?>">Fahrzeugliste</a></li>
                        <li><a class="dropdown-item d-flex justify-content-between align-items-center gap-3" href="<?= e(url('/abstellgleis')) ?>">Abstellgleis<?php if ($abstellgleisAnzahl !== null): ?> <span class="badge rounded-pill text-bg-secondary"><?= (int) $abstellgleisAnzahl() ?></span><?php endif; ?></a></li>
                    </ul>
                </li>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/anlagen')) ?>">Anlagen</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/wartung')) ?>">Wartung</a></li>
                <?php $neu = $neueDefekte !== null ? $neueDefekte() : null; ?>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/reparaturen')) ?>">Reparaturen<?php if ($neu !== null && $neu > 0): ?> <span class="badge rounded-pill text-bg-warning" data-neue-defekte="<?= (int) $neu ?>" title="<?= e($neu === 1 ? '1 neue Defektmeldung' : $neu . ' neue Defektmeldungen') ?>"><?= $neu > 99 ? '99+' : (int) $neu ?> <span class="visually-hidden">neue Meldungen</span></span><?php endif; ?></a></li>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/defekt')) ?>">Defekt melden</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/adressen')) ?>">Adressen</a></li>
                <?php if ($darf(Berechtigung::PERSON_BEARBEITEN)): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e(url('/personen')) ?>">Personen</a></li>
                <?php endif; ?>
                <?php
                $verwaltung = [];
                if ($darf(Berechtigung::BENUTZER_VERWALTEN)) {
                    $verwaltung['/admin/benutzer'] = 'Benutzer';
                }
                if ($darf(Berechtigung::STAMMDATEN_VERWALTEN)) {
                    $verwaltung['/admin/spurweiten'] = 'Spurweiten';
                }
                if ($darf(Berechtigung::WARTUNGSART_VERWALTEN)) {
                    $verwaltung['/admin/wartungsarten'] = 'Wartungsarten';
                }
                if ($darf(Berechtigung::BETRIEB)) {
                    $verwaltung['/admin/sicherung'] = 'Sicherung';
                    $verwaltung['/admin/onedrive'] = 'OneDrive';
                    $verwaltung['/admin/update'] = 'Update';
                    if (!empty($testumgebung)) {
                        $verwaltung['/admin/testdaten'] = 'Testdaten';
                    }
                }
                if ($verwaltung !== []): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Verwaltung</a>
                        <ul class="dropdown-menu">
                            <?php foreach ($verwaltung as $pfad => $text): ?>
                                <li><a class="dropdown-item" href="<?= e(url($pfad)) ?>"><?= e($text) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><span class="emc-benutzername" title="<?= e($benutzer->anzeigename) ?>"><?= e($benutzer->anzeigename) ?></span></a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text text-muted small"><?= e($benutzer->rolle->label()) ?></span></li>
                        <li><a class="dropdown-item" href="<?= e(url('/konto/passwort')) ?>">Passwort ändern</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= e(url('/logout')) ?>" class="px-3">
                                <?php include __DIR__ . '/csrf.php'; ?>
                                <button type="submit" class="btn btn-link dropdown-item px-0">Abmelden</button>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</nav>
