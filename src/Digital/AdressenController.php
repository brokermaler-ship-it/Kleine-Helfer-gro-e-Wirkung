<?php

declare(strict_types=1);

namespace Emc\Digital;

use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\View;
use Emc\Stammdaten\SpurweiteRepository;

final class AdressenController
{
    public function __construct(
        private View $view,
        private Adresspruefung $pruefung,
        private SpurweiteRepository $spurweiten,
    ) {
    }

    public function uebersicht(Request $r): Response
    {
        $alle = $this->spurweiten->alle();
        $aktuell = null;
        $gewuenscht = $r->ganzzahl('spurweite', 1, 4294967295);
        foreach ($alle as $s) {
            if ($gewuenscht !== null && (int) $s['id'] === $gewuenscht) {
                $aktuell = $s;
            }
        }
        $aktuell ??= $alle[0] ?? null;
        $html = $this->view->render('digital/adressen', [
            'titel' => 'Adressen',
            'spurweiten' => $alle,
            'aktuell' => $aktuell,
            'zeilen' => $aktuell === null ? [] : $this->pruefung->uebersicht((int) $aktuell['id']),
        ]);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** JSON für die Live-Prüfung im Decoder-Formular. */
    public function api(Request $r): Response
    {
        $leer = ['fehler' => null, 'adressart' => null, 'warnung' => null, 'konflikte' => []];
        $p = Protokoll::tryFrom($r->text('protokoll'));
        if ($p === null) {
            return Response::json(['fehler' => 'Unbekanntes Protokoll.'] + $leer);
        }
        [$adresse, $fehler] = AdressValidator::lies($r, $p);
        if ($fehler !== null) {
            return Response::json(['fehler' => $fehler] + $leer);
        }
        if ($adresse === null) { // mfx ohne Adresse
            return Response::json($leer);
        }
        $spurweite = $this->spurweiten->finde($r->ganzzahl('spurweite', 1, 4294967295) ?? 0);
        if ($spurweite === null) {
            return Response::json(['fehler' => 'Bitte eine Spurweite wählen.', 'adressart' => AdressValidator::adressart($p, $adresse)] + $leer);
        }
        $konflikte = $this->pruefung->konflikte((int) $spurweite['id'], $p, $adresse, $r->ganzzahl('fahrzeug', 1, 4294967295));
        return Response::json([
            'fehler' => null,
            'adressart' => AdressValidator::adressart($p, $adresse),
            'warnung' => Adresspruefung::warntext($p, $adresse, (string) $spurweite['name'], $konflikte),
            'konflikte' => $konflikte,
        ]);
    }
}
