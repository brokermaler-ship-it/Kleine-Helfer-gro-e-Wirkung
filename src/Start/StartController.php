<?php

declare(strict_types=1);

namespace Emc\Start;

use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\View;
use Emc\Wartung\Wartungsplan;

/** Startseite mit Kacheln (Wartung; Stufe 2c ergänzt „Offene Defekte“) und Schnellzugriffen. */
final class StartController
{
    public function __construct(
        private View $view,
        private Wartungsplan $plan,
    ) {
    }

    public function start(Request $r): Response
    {
        $html = $this->view->render('start', [
            'titel' => 'Start',
            'wartung' => $this->wartung(new \DateTimeImmutable('today')),
        ]);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @return ?array{rot:int, gelb:int, dringendste: list<array<string,mixed>>} null, solange das Datenbank-Update aussteht */
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
}
