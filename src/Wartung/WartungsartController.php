<?php

declare(strict_types=1);

namespace Emc\Wartung;

use Emc\Auth\Auth;
use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Pflege der Wartungsarten (eine Seite mit Liste und Formular, `?bearbeiten=<id>`), nach dem Muster der Spurweiten.
 * Die Routen verlangen Berechtigung::WARTUNGSART_VERWALTEN (ab Fahrzeugwart).
 */
final class WartungsartController
{
    public function __construct(
        private View $view,
        private WartungsartRepository $arten,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
    ) {
    }

    public function liste(Request $r): Response
    {
        $bearbeiteId = $r->ganzzahl('bearbeiten', 1, 4294967295);
        $zeile = $bearbeiteId !== null ? $this->arten->finde($bearbeiteId) : null;
        if ($zeile === null) {
            $alle = $this->arten->alle();
            $naechste = $alle === [] ? 10 : max(array_column($alle, 'sortierung')) + 10;
            $zeile = ['name' => '', 'gilt_fuer' => '', 'intervall_monate' => '', 'intervall_stunden' => '', 'aktiv' => 1, 'sortierung' => $naechste];
        }
        return $this->seite($zeile, [], isset($zeile['id']) ? (int) $zeile['id'] : null);
    }

    public function speichern(Request $r): Response
    {
        $id = $r->ganzzahl('id', 1, 4294967295);
        $alt = $id !== null ? $this->arten->finde($id) : null;
        if ($id !== null && $alt === null) {
            throw new HttpFehler(404);
        }
        $eingabe = [];
        foreach (WartungsartRepository::FELDER as $feld) {
            $eingabe[$feld] = $r->post($feld);
        }
        [$daten, $fehler] = WartungsartValidator::pruefe($eingabe);
        if ($fehler === []) {
            try {
                $neueId = $this->arten->speichern($daten, $id);
                $benutzerId = $this->auth->benutzer()?->id;
                if ($alt === null) {
                    $this->protokoll->schreibe($benutzerId, 'wartungsart', $neueId, 'angelegt', Aenderungsprotokoll::diff([], $daten));
                } else {
                    $diff = Aenderungsprotokoll::diff(array_intersect_key($alt, $daten), $daten);
                    if ($diff !== []) {
                        $this->protokoll->schreibe($benutzerId, 'wartungsart', $neueId, 'geaendert', $diff);
                    }
                }
                $this->session->meldung('erfolg', 'Die Wartungsart wurde gespeichert.');
                return Response::redirect(url('/admin/wartungsarten'));
            } catch (DomainFehler $e) {
                $fehler['name'] = $e->getMessage();
            }
        }
        $werte = array_map(fn($w) => is_string($w) ? trim($w) : '', $eingabe);
        $werte['aktiv'] = $daten['aktiv'];
        return $this->seite($werte, $fehler, $id, 422);
    }

    public function loeschen(Request $r, array $p): Response
    {
        $id = ctype_digit((string) ($p['id'] ?? '')) ? (int) $p['id'] : 0;
        $alt = $id > 0 ? $this->arten->finde($id) : null;
        if ($alt === null) {
            throw new HttpFehler(404);
        }
        try {
            $this->arten->loeschen($id);
            $werte = array_intersect_key($alt, array_flip(WartungsartRepository::FELDER));
            $this->protokoll->schreibe(
                $this->auth->benutzer()?->id,
                'wartungsart',
                $id,
                'geloescht',
                Aenderungsprotokoll::diff($werte, array_fill_keys(array_keys($werte), null))
            );
            $this->session->meldung('erfolg', 'Die Wartungsart wurde gelöscht.');
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/admin/wartungsarten'));
    }

    /**
     * @param array<string,mixed> $werte
     * @param array<string,string> $fehler
     */
    private function seite(array $werte, array $fehler, ?int $bearbeiteId, int $status = 200): Response
    {
        $html = $this->view->render('wartung/wartungsarten', [
            'titel' => 'Wartungsarten',
            'arten' => $this->arten->alle(),
            'werte' => $werte,
            'fehler' => $fehler,
            'bearbeiteId' => $bearbeiteId,
        ]);
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
