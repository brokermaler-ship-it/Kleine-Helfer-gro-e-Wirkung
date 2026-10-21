<?php

declare(strict_types=1);

namespace Emc\Start;

use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\View;
use Emc\Reparatur\ReparaturRepository;
use Emc\Wartung\Wartungsplan;

/** Startseite mit Kacheln „Offene Defekte“ (Stufe 2c) und „Wartung“ sowie Schnellzugriffen. */
final class StartController
{
    public function __construct(
        private View $view,
        private Wartungsplan $plan,
        private ReparaturRepository $reparaturen,
    ) {
    }

    public function start(Request $r): Response
    {
        $html = $this->view->render('start', [
            'titel' => 'Start',
            'defekte' => $this->defekte(),
            'wartung' => $this->wartung(new \DateTimeImmutable('today')),
        ]);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @return ?array{rot:int, gelb:int, nie:int, dringendste: list<array<string,mixed>>} null, solange das Datenbank-Update aussteht */
    private function wartung(\DateTimeImmutable $heute): ?array
    {
        try {
            return $this->plan->zusammenfassung($heute);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return null; // Tabellen aus Migration 011–013 fehlen noch: Startseite ohne Wartungs-Kachel.
            }
            throw $e;
        }
    }

    /** @return ?array{anzahl:int, dringend:int, liste:list<array<string,mixed>>} null, solange das Datenbank-Update aussteht */
    private function defekte(): ?array
    {
        try {
            return $this->reparaturen->offeneDefekte(5);
        } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
                return null; // Tabellen aus Migration 014 fehlen noch: Startseite ohne Kachel „Offene Defekte“.
            }
            throw $e;
        }
    }
}
