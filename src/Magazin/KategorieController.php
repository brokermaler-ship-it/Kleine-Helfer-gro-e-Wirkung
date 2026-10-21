<?php

declare(strict_types=1);

namespace Emc\Magazin;

use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;

/**
 * Verwaltung › Magazin-Kategorien (Administratoren, Route „stammdaten_verwalten“). Kategorien mit festem Schlüssel
 * (Fahrzeuge, Literatur, Sonstiges) lassen sich umbenennen, aber nicht löschen; benutzte Kategorien bleiben.
 */
final class KategorieController
{
    public function __construct(private View $view, private MagazinRepository $magazin, private Session $session)
    {
    }

    /** GET /admin/magazin-kategorien (?bearbeiten=<id>) */
    public function liste(Request $r): Response
    {
        $id = $r->ganzzahl('bearbeiten', 1, 4294967295);
        $zeile = $id === null ? null : $this->magazin->kategorie($id);
        $werte = $zeile === null ? ['name' => '', 'sortierung' => '100'] : ['name' => (string) $zeile['name'], 'sortierung' => (string) $zeile['sortierung']];
        return $this->seite($werte, [], $zeile === null ? null : (int) $zeile['id']);
    }

    /** POST /admin/magazin-kategorien (mit id: umbenennen) */
    public function speichern(Request $r): Response
    {
        $id = $r->ganzzahl('id', 1, 4294967295);
        if ($r->text('id') !== '' && ($id === null || $this->magazin->kategorie($id) === null)) {
            throw new HttpFehler(404);
        }
        $werte = ['name' => trim($r->text('name')), 'sortierung' => trim($r->text('sortierung', '0'))];
        $fehler = [];
        if ($werte['name'] === '') {
            $fehler['name'] = 'Bitte einen Namen eingeben.';
        } elseif (mb_strlen($werte['name']) > 80) {
            $fehler['name'] = 'Bitte höchstens 80 Zeichen eingeben.';
        }
        if (preg_match('/^-?\d{1,9}$/', $werte['sortierung']) !== 1) {
            $fehler['sortierung'] = 'Bitte eine ganze Zahl angeben.';
        }
        if ($fehler === []) {
            try {
                $this->magazin->kategorieSpeichern($id, $werte['name'], (int) $werte['sortierung']);
                $this->session->meldung('erfolg', 'Die Kategorie wurde gespeichert.');
                return Response::redirect(url('/admin/magazin-kategorien'));
            } catch (DomainFehler $e) {
                $fehler['name'] = $e->getMessage();
            }
        }
        return $this->seite($werte, $fehler, $id, 422);
    }

    /** POST /admin/magazin-kategorien/{id}/loeschen */
    public function loeschen(Request $r, array $p): Response
    {
        $roh = (string) ($p['id'] ?? '');
        $k = preg_match('/^\d{1,10}$/', $roh) === 1 ? $this->magazin->kategorie((int) $roh) : null;
        if ($k === null) {
            throw new HttpFehler(404);
        }
        if ($k['schluessel'] !== null) {
            $this->session->meldung('fehler', 'Die Kategorie „' . $k['name'] . '“ braucht das Programm; du kannst sie umbenennen, aber nicht löschen.');
        } elseif ($this->magazin->kategorieLoeschen((int) $k['id'])) {
            $this->session->meldung('erfolg', 'Die Kategorie wurde gelöscht.');
        } else {
            $this->session->meldung('fehler', 'Die Kategorie wird noch verwendet.');
        }
        return Response::redirect(url('/admin/magazin-kategorien'));
    }

    /**
     * @param array<string,string> $werte
     * @param array<string,string> $fehler
     */
    private function seite(array $werte, array $fehler, ?int $bearbeiteId, int $status = 200): Response
    {
        $html = $this->view->render('admin/magazin_kategorien', [
            'titel' => 'Magazin-Kategorien',
            'kategorien' => $this->magazin->kategorien(),
            'werte' => $werte,
            'fehler' => $fehler,
            'bearbeiteId' => $bearbeiteId,
        ]);
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
