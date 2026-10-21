<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Auth\Auth;
use Emc\Auth\Berechtigung;
use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Etiketten\Bogen;
use Emc\Etiketten\EtikettAuswahl;
use Emc\Etiketten\EtikettController;
use Emc\Etiketten\QrCode;
use Emc\Fahrzeuge\KonfliktFehler;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturValidator;

/**
 * Börsen (Veranstaltungen): Liste und Seite lesen alle Angemeldeten; anlegen, bearbeiten, starten, Preisschilder,
 * Gesamtliste, Abrechnung (auch Druckansicht), Auszahlungen und Abschluss nur der Börsenwart
 * (Berechtigung::verlangeBoersenwart, sonst 403); wieder öffnen nur der Administrator (Route boerse_wieder_oeffnen).
 */
final class BoerseController
{
    public const AUSWAHL = ['boerse', 'dauerhaft', 'einzeln'];

    /** @param array<string,mixed> $config für oeffentliche_url und basis_url (Kurzlink im QR-Code) */
    public function __construct(
        private View $view,
        private BoerseRepository $boersen,
        private BoerseDienst $dienst,
        private AngebotRepository $angebote,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
        private array $config,
        private AbrechnungRepository $abrechnung,
    ) {
    }

    /** GET /boersen */
    public function liste(Request $r): Response
    {
        return $this->seite('boerse/boersen', [
            'titel' => 'Börsen',
            'boersen' => $this->boersen->alle(),
            'istBoersenwart' => $this->istBoersenwart(),
        ]);
    }

    /** GET /boersen/neu */
    public function neu(Request $r): Response
    {
        $this->verlangeBoersenwart();
        return $this->formular(null, ['name' => '', 'datum' => '', 'ort' => '', 'provision_prozent' => '10'], []);
    }

    /** POST /boersen */
    public function anlegen(Request $r): Response
    {
        $this->verlangeBoersenwart();
        $werte = $this->werte($r);
        [$d, $fehler] = BoerseValidator::pruefe($werte, false);
        if ($fehler !== []) {
            return $this->formular(null, $werte, $fehler, 422);
        }
        $id = $this->dienst->anlegen($d, $this->auth->benutzer()?->id);
        $this->session->meldung('erfolg', 'Die Börse wurde angelegt.');
        return Response::redirect(url('/boersen/' . $id));
    }

    /** GET /boersen/{id} */
    public function anzeigen(Request $r, array $p): Response
    {
        $b = $this->lade($p);
        $id = (int) $b['id'];
        return $this->seite('boerse/boerse', [
            'titel' => (string) $b['name'],
            'boerse' => $b,
            'angebote' => $this->angebote->suche(new AngebotFilter(boerseId: $id, status: AngebotFilter::ALLE)),
            'dauerhafte' => $this->angebote->suche(new AngebotFilter(dauerhaft: true)),
            'verlauf' => $this->protokoll->fuerObjekt(BoerseDienst::OBJEKT, $id),
            'istBoersenwart' => $this->istBoersenwart(),
            'preisschild' => Bogen::preisschild(),
        ]);
    }

    /** GET /boersen/{id}/bearbeiten */
    public function bearbeiten(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        try {
            BoerseDienst::verlangeOffen($b);
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
            return Response::redirect(url('/boersen/' . (int) $b['id']));
        }
        $werte = [
            'name' => (string) $b['name'],
            'datum' => (string) $b['datum'],
            'ort' => (string) ($b['ort'] ?? ''),
            'provision_prozent' => BoerseValidator::provisionAnzeige((string) $b['provision_prozent']),
        ];
        return $this->formular($b, $werte, [], 200, null, (string) $b['geaendert_am']);
    }

    /** POST /boersen/{id} */
    public function aendern(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        $stand = $r->text('stand');
        $werte = $this->werte($r);
        [$d, $fehler] = BoerseValidator::pruefe($werte, $b['anzahl_verkaeufe'] > 0, (string) $b['provision_prozent']);
        if ($fehler !== []) {
            return $this->formular($b, $werte, $fehler, 422, null, $stand);
        }
        try {
            $this->dienst->aendern((int) $b['id'], $d, $stand, $this->auth->benutzer()?->id);
        } catch (KonfliktFehler $e) {
            return $this->formular($b, $werte, [], 409, $e->getMessage(), $stand);
        } catch (DomainFehler $e) {
            return $this->formular($b, $werte, [], 422, $e->getMessage(), $stand);
        }
        $this->session->meldung('erfolg', 'Die Börse wurde gespeichert.');
        return Response::redirect(url('/boersen/' . (int) $b['id']));
    }

    /** POST /boersen/{id}/starten: in_vorbereitung → laeuft */
    public function starten(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        try {
            $this->dienst->starten((int) $b['id'], $this->auth->benutzer()?->id);
            $this->session->meldung('erfolg', 'Die Börse läuft. Die Kasse ist offen.');
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/boersen/' . (int) $b['id']));
    }

    /**
     * GET /boersen/{id}/preisschilder: auswahl = boerse (offene Angebote dieser Börse), dauerhaft (offene dauerhafte)
     * oder einzeln (ids[]); start = erstes freies Feld des ersten Bogens (1–8). Druckseite ohne Menü.
     */
    public function preisschilder(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        $bogen = Bogen::preisschild();
        $auswahl = in_array($r->text('auswahl'), self::AUSWAHL, true) ? $r->text('auswahl') : 'boerse';
        $ids = $r->query('ids');
        $ids = is_array($ids) ? EtikettAuswahl::bereinige($ids) : [];
        $start = $r->ganzzahl('start', 1, $bogen->proBogen()) ?? 1;
        $filter = match ($auswahl) {
            'dauerhaft' => new AngebotFilter(dauerhaft: true),
            'einzeln' => new AngebotFilter(ids: $ids),
            default => new AngebotFilter(boerseId: (int) $b['id']),
        };
        [$basis, $urlFehlt] = EtikettController::kurzlinkBasis($this->config, $r);
        $schilder = [];
        foreach ($this->angebote->suche($filter) as $a) {
            $link = $basis . '/c/' . $a['nummer'];
            $schilder[] = [
                'id' => (int) $a['id'],
                'nummer' => (string) $a['nummer'],
                'bezeichnung' => (string) $a['bezeichnung'],
                'menge' => (int) $a['menge'],
                'preis' => ReparaturValidator::betrag((string) $a['wunschpreis']),
                'link' => $link,
                'linkText' => (string) preg_replace('~^https?://~', '', $link),
                'svg' => QrCode::svg($link),
            ];
        }
        $zurueck = ['auswahl' => $auswahl, 'start' => $start] + ($auswahl === 'einzeln' ? ['ids' => $ids] : []);
        $html = $this->view->render('boerse/preisschilder', [
            'titel' => 'Preisschilder drucken',
            'boerse' => $b,
            'bogen' => $bogen,
            'boegen' => $bogen->verteile($schilder, $start),
            'anzahl' => count($schilder),
            'start' => $start,
            'urlFehlt' => $urlFehlt,
            'qrBasis' => $basis,
            'zurueck' => $zurueck,
        ], null);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** GET /boersen/{id}/liste: Gesamtliste zum Drucken (Nummer, Bezeichnung, Preis, Herkunft Verein/Mitglied – ohne Namen). */
    public function gesamtliste(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        $html = $this->view->render('boerse/gesamtliste', [
            'titel' => 'Gesamtliste ' . $b['name'],
            'boerse' => $b,
            'angebote' => $this->angebote->suche(new AngebotFilter(boerseId: (int) $b['id'], status: AngebotFilter::ALLE)),
            'dauerhafte' => $this->angebote->suche(new AngebotFilter(dauerhaft: true)),
        ], null);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** GET /boersen/{id}/abrechnung (?druck=1: Druckansicht ohne Menü und ohne Formulare) */
    public function abrechnung(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        $id = (int) $b['id'];
        $daten = [
            'titel' => 'Abrechnung ' . $b['name'],
            'boerse' => $b,
            'abrechnung' => Abrechnung::berechne($this->abrechnung->positionen($id), $this->abrechnung->summeVerkaeufe($id)),
            'auszahlungen' => $this->abrechnung->auszahlungen($id),
            'darfWiederOeffnen' => Berechtigung::darf($this->auth->benutzer(), Berechtigung::BOERSE_WIEDER_OEFFNEN),
            'heute' => (new \DateTimeImmutable())->format('Y-m-d'),
        ];
        if ($r->text('druck') === '1') {
            $html = $this->view->render('boerse/abrechnung_druck', $daten, null);
            return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        return $this->seite('boerse/abrechnung', $daten);
    }

    /** POST /boersen/{id}/abschliessen: laeuft → abgeschlossen, offene Stücke gehen zurück. */
    public function abschliessen(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        try {
            $n = $this->dienst->abschliessen((int) $b['id'], $this->auth->benutzer()?->id, new \DateTimeImmutable());
            $this->session->meldung('erfolg', 'Die Börse ist abgeschlossen. ' . ($n === 1 ? '1 Stück geht zurück.' : $n . ' Stücke gehen zurück.'));
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/boersen/' . (int) $b['id'] . '/abrechnung'));
    }

    /** POST /boersen/{id}/wieder-oeffnen (nur Admin, prüft die Route): abgeschlossen → laeuft. */
    public function wiederOeffnen(Request $r, array $p): Response
    {
        $b = $this->lade($p);
        try {
            $this->dienst->wiederOeffnen((int) $b['id'], $this->auth->benutzer()?->id);
            $this->session->meldung('erfolg', 'Die Börse ist wieder geöffnet.');
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/boersen/' . (int) $b['id'] . '/abrechnung'));
    }

    /** POST /boersen/{id}/auszahlungen (person_id, ausgezahlt_am – Standard heute): Haken „ausgezahlt“. */
    public function auszahlung(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        $person = self::id($r->text('person_id'));
        $datum = trim($r->text('ausgezahlt_am'));
        $datum = $datum === '' ? (new \DateTimeImmutable())->format('Y-m-d') : $datum;
        try {
            $this->dienst->vermerkeAuszahlung((int) $b['id'], $person, $datum, $this->auth->benutzer()?->id, new \DateTimeImmutable());
            $this->session->meldung('erfolg', 'Auszahlung an ' . $this->name((int) $b['id'], $person) . ' vermerkt.');
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/boersen/' . (int) $b['id'] . '/abrechnung'));
    }

    /** POST /boersen/{id}/auszahlungen/{personId}/loeschen: Haken „ausgezahlt“ entfernen. */
    public function auszahlungLoeschen(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        $person = self::id((string) ($p['personId'] ?? ''));
        try {
            $this->dienst->entferneAuszahlung((int) $b['id'], $person, $this->auth->benutzer()?->id);
            $this->session->meldung('erfolg', 'Die Auszahlung an ' . $this->name((int) $b['id'], $person) . ' ist nicht mehr vermerkt.');
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/boersen/' . (int) $b['id'] . '/abrechnung'));
    }

    /** Name der Person aus der Abrechnung (wie in der Tabelle). */
    private function name(int $boerseId, int $personId): string
    {
        foreach (Abrechnung::berechne($this->abrechnung->positionen($boerseId))['mitglieder'] as $m) {
            if ($m['person_id'] === $personId) {
                return $m['name'];
            }
        }
        return Aenderungsprotokoll::EHEMALIGES_MITGLIED;
    }

    /** Positive id aus Formular oder Pfad, sonst 404. */
    private static function id(string $roh): int
    {
        $roh = trim($roh);
        if (preg_match('/^\d{1,10}$/', $roh) !== 1 || (int) $roh < 1 || (int) $roh > 4294967295) {
            throw new HttpFehler(404);
        }
        return (int) $roh;
    }

    // ---- Hilfen -----------------------------------------------------------------------------------------------------

    /**
     * @param ?array<string,mixed> $boerse
     * @param array<string,string> $werte
     * @param array<string,string> $fehler
     */
    private function formular(?array $boerse, array $werte, array $fehler, int $status = 200, ?string $meldung = null, string $stand = ''): Response
    {
        return $this->seite('boerse/boerse_formular', [
            'titel' => $boerse === null ? 'Börse anlegen' : 'Börse bearbeiten',
            'boerse' => $boerse,
            'werte' => $werte,
            'fehler' => $fehler,
            'meldung' => $meldung,
            'stand' => $stand,
        ], $status);
    }

    /** @return array<string,string> */
    private function werte(Request $r): array
    {
        $w = [];
        foreach (['name', 'datum', 'ort', 'provision_prozent'] as $f) {
            $w[$f] = $r->text($f);
        }
        return $w;
    }

    /** @param array<string,mixed> $daten */
    private function seite(string $template, array $daten, int $status = 200): Response
    {
        return new Response($this->view->render($template, $daten), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function istBoersenwart(): bool
    {
        return Berechtigung::istBoersenwart($this->auth->benutzer());
    }

    private function verlangeBoersenwart(): void
    {
        Berechtigung::verlangeBoersenwart($this->auth->benutzer());
    }

    /** @return array<string,mixed> */
    private function lade(array $p): array
    {
        $roh = (string) ($p['id'] ?? '');
        $id = preg_match('/^\d{1,10}$/', $roh) === 1 && (int) $roh <= 4294967295 ? (int) $roh : 0;
        return ($id > 0 ? $this->boersen->finde($id) : null) ?? throw new HttpFehler(404);
    }
}
