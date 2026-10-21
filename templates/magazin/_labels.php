<?php
use Emc\Magazin\MagazinLabels;
use Emc\Reparatur\ReparaturValidator;

$magazinArten = MagazinLabels::ART;
$magazinHerkunft = MagazinLabels::HERKUNFT;
$magazinAusgang = MagazinLabels::AUSGANG;
$magazinZustand = MagazinLabels::ZUSTAND;
$magazinZustandKlassen = ['neu' => 'success', 'neuwertig' => 'success', 'gebraucht' => 'secondary', 'defekt' => 'danger'];
$magazinZustandBadge = fn(string $z): string => '<span class="badge text-bg-' . e($magazinZustandKlassen[$z] ?? 'secondary') . ' fw-normal">' . e($magazinZustand[$z] ?? $z) . '</span>';
$magazinBetrag = fn(?string $w): string => ReparaturValidator::betrag($w);
$magazinDatum = fn(?string $d): string => $d === null || $d === '' ? '' : date('d.m.Y', (int) strtotime($d));
$magazinBestandBadge = fn(int $n): string => '<span class="badge rounded-pill ' . ($n > 0 ? 'text-bg-primary' : 'text-bg-light border') . '">' . $n . '</span>';
$magazinTitelbild = fn(array $a): string => ($a['titelbild_dokument_id'] ?? null) === null
    ? '<div class="fahrzeug-bild fahrzeug-bild-leer" aria-hidden="true">&#128230;</div>'
    : '<img class="fahrzeug-bild" alt="" loading="lazy" src="' . e(url('/dateien/' . $a['titelbild_dokument_id'] . '/vorschau')) . '">';
