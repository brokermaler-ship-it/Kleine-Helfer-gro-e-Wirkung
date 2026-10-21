<?php

declare(strict_types=1);

namespace Emc\Wartung;

use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\View;
use Emc\Stammdaten\SpurweiteRepository;

/** Seite „Wartung“: alle Fälligkeiten, sortiert nach Dringlichkeit, mit Filter. */
final class UebersichtController
{
    public function __construct(
        private View $view,
        private Wartungsplan $plan,
        private SpurweiteRepository $spurweiten,
    ) {
    }

    public function uebersicht(Request $r): Response
    {
        $filter = WartungFilter::ausRequest($r);
        $heute = new \DateTimeImmutable('today');
        // Zählung je Stand über die übrigen Filter, danach erst nach Stand filtern (eine Berechnung).
        $alle = $this->plan->uebersicht($filter->ohneStand(), $heute);
        $html = $this->view->render('wartung/uebersicht', [
            'titel' => 'Wartung',
            'filter' => $filter,
            'spurweiten' => $this->spurweiten->alle(),
            'anzahl' => Wartungsplan::zaehle($alle),
            'zeilen' => array_values(array_filter($alle, fn(array $z): bool => $filter->passtStand($z['faelligkeit']))),
        ]);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
