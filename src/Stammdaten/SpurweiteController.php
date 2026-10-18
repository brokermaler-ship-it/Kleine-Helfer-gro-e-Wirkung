<?php

declare(strict_types=1);

namespace Emc\Stammdaten;

use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;

final class SpurweiteController
{
    public function __construct(
        private View $view,
        private SpurweiteRepository $spurweiten,
        private Session $session,
    ) {
    }

    public function liste(Request $r): Response
    {
        $bearbeiteId = ctype_digit((string) $r->query('bearbeiten', '')) ? (int) $r->query('bearbeiten') : 0;
        $zeile = $bearbeiteId > 0 ? $this->spurweiten->finde($bearbeiteId) : null;
        return $this->seite($zeile ?? ['name' => '', 'massstab' => '', 'sortierung' => 100], [], $zeile['id'] ?? null);
    }

    public function speichern(Request $r): Response
    {
        $id = ctype_digit((string) $r->post('id', '')) && (int) $r->post('id') > 0 ? (int) $r->post('id') : null;
        if ($id !== null && $this->spurweiten->finde($id) === null) {
            throw new HttpFehler(404);
        }
        $werte = [
            'name' => trim((string) $r->post('name', '')),
            'massstab' => trim((string) $r->post('massstab', '')),
            'sortierung' => trim((string) $r->post('sortierung', '0')),
        ];
        $fehler = [];
        if ($werte['name'] === '') {
            $fehler['name'] = 'Bitte gib einen Namen an.';
        } elseif (mb_strlen($werte['name']) > 30) {
            $fehler['name'] = 'Bitte höchstens 30 Zeichen.';
        }
        if (mb_strlen($werte['massstab']) > 20) {
            $fehler['massstab'] = 'Bitte höchstens 20 Zeichen.';
        }
        if (filter_var($werte['sortierung'], FILTER_VALIDATE_INT) === false) {
            $fehler['sortierung'] = 'Bitte gib eine ganze Zahl an.';
        }
        if ($fehler === []) {
            try {
                $this->spurweiten->speichern($werte['name'], $werte['massstab'], (int) $werte['sortierung'], $id);
                $this->session->meldung('erfolg', 'Die Spurweite wurde gespeichert.');
                return Response::redirect(url('/admin/spurweiten'));
            } catch (DomainFehler $e) {
                $fehler['name'] = $e->getMessage();
            }
        }
        return $this->seite($werte, $fehler, $id, 422);
    }

    public function loeschen(Request $r, array $p): Response
    {
        $id = ctype_digit((string) ($p['id'] ?? '')) ? (int) $p['id'] : 0;
        if ($id === 0 || $this->spurweiten->finde($id) === null) {
            throw new HttpFehler(404);
        }
        try {
            $this->spurweiten->loeschen($id);
            $this->session->meldung('erfolg', 'Die Spurweite wurde gelöscht.');
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/admin/spurweiten'));
    }

    /**
     * @param array<string,mixed> $werte
     * @param array<string,string> $fehler
     */
    private function seite(array $werte, array $fehler, ?int $bearbeiteId, int $status = 200): Response
    {
        $html = $this->view->render('admin/spurweiten', [
            'titel' => 'Spurweiten',
            'spurweiten' => $this->spurweiten->alle(),
            'werte' => $werte,
            'fehler' => $fehler,
            'bearbeiteId' => $bearbeiteId,
        ]);
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
