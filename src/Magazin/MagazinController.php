<?php

declare(strict_types=1);

namespace Emc\Magazin;

use Emc\Auth\Auth;
use Emc\Auth\Berechtigung;
use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Dateien\DateiController;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\ObjektFotos;
use Emc\Dateien\UploadService;
use Emc\Fahrzeuge\KonfliktFehler;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Stammdaten\SpurweiteRepository;

/**
 * Magazin: Liste, Artikel und Sammlungen lesen alle Angemeldeten (Route „lesen“); anlegen, bearbeiten, buchen,
 * gegenbuchen, Fotos und Sammlungen pflegen nur Börsenwarte (Berechtigung::verlangeBoersenwart, sonst 403).
 * Artikel und Buchungen werden nie gelöscht.
 */
final class MagazinController
{
    private const ARTIKEL_FELDER = ['bezeichnung', 'kategorie_id', 'hersteller', 'artikelnummer', 'spurweite_id', 'zustand', 'lagerort', 'notiz', 'autor_verlag', 'jahr', 'isbn'];
    private const EINGANG_FELDER = ['menge', 'herkunft', 'freitext', 'datum', 'betrag'];
    private const BUCHUNG_FELDER = ['datum', 'menge', 'herkunft', 'ausgang_art', 'freitext', 'betrag', 'lagerort_neu'];

    public function __construct(
        private View $view,
        private MagazinRepository $magazin,
        private MagazinDienst $dienst,
        private SpurweiteRepository $spurweiten,
        private DokumentRepository $dokumente,
        private UploadService $upload,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
    ) {
    }

    /** GET /magazin */
    public function liste(Request $r): Response
    {
        $filter = MagazinFilter::ausRequest($r);
        return $this->seite('magazin/liste', [
            'titel' => 'Magazin',
            'filter' => $filter,
            'artikel' => $this->magazin->suche($filter),
            'kategorien' => $this->magazin->kategorien(),
            'spurweiten' => $this->spurweiten->alle(),
            'lagerorte' => $this->magazin->lagerorte(),
            'istBoersenwart' => $this->istBoersenwart(),
        ]);
    }

    /** GET /magazin/neu */
    public function neu(Request $r): Response
    {
        $this->verlangeBoersenwart();
        return $this->formular(null, ['zustand' => 'gebraucht', 'menge' => '1', 'herkunft' => '', 'datum' => date('Y-m-d')], []);
    }

    /** POST /magazin: Artikel mit erster Eingangsbuchung anlegen. */
    public function anlegen(Request $r): Response
    {
        $this->verlangeBoersenwart();
        $werte = $this->werte($r, [...self::ARTIKEL_FELDER, ...self::EINGANG_FELDER]);
        [$artikel, $fehler] = $this->pruefeArtikel($werte, true);
        [$eingang, $fehlerEingang] = BuchungValidator::pruefe($werte, 'eingang');
        $fehler += $fehlerEingang;
        if ($fehler !== []) {
            return $this->formular(null, $werte, $fehler, 422);
        }
        try {
            $id = $this->dienst->legeAnMitEingang($artikel, $eingang, $this->auth->benutzer()?->id);
        } catch (DomainFehler $e) {
            return $this->formular(null, $werte, [], 422, $e->getMessage());
        }
        $this->session->meldung('erfolg', 'Der Artikel wurde als M' . $id . ' angelegt.');
        return Response::redirect(url('/magazin/' . $id));
    }

    /** GET /magazin/{id} */
    public function anzeigen(Request $r, array $p): Response
    {
        return $this->detail($this->lade($p));
    }

    /** GET /magazin/{id}/bearbeiten */
    public function bearbeiten(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $a = $this->lade($p);
        $werte = [];
        foreach (self::ARTIKEL_FELDER as $f) {
            $werte[$f] = $a[$f] === null ? '' : (string) $a[$f];
        }
        return $this->formular($a, $werte, [], 200, null, (string) $a['geaendert_am']);
    }

    /** POST /magazin/{id}: Felder ändern (ohne Lagerort), Stand-Schutz wie bei Fahrzeugen (409). */
    public function aendern(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $a = $this->lade($p);
        $id = (int) $a['id'];
        $stand = $r->text('stand');
        $werte = $this->werte($r, self::ARTIKEL_FELDER);
        $werte['lagerort'] = (string) ($a['lagerort'] ?? '');
        [$d, $fehler] = $this->pruefeArtikel($werte, false);
        if ($fehler !== []) {
            return $this->formular($a, $werte, $fehler, 422, null, $stand);
        }
        try {
            $this->magazin->aktualisieren($id, $d, $stand);
        } catch (KonfliktFehler $e) {
            return $this->formular($a, $werte, [], 409, $e->getMessage(), $stand);
        }
        $alt = array_intersect_key($a, array_flip(MagazinRepository::FELDER));
        $diff = Aenderungsprotokoll::diff($alt, $d);
        if ($diff !== []) {
            $this->protokoll->schreibe($this->auth->benutzer()?->id, MagazinDienst::OBJEKT_ARTIKEL, $id, 'geaendert', $diff);
        }
        $this->session->meldung('erfolg', 'Der Artikel wurde gespeichert.');
        return Response::redirect(url('/magazin/' . $id));
    }

    /** POST /magazin/{id}/buchungen: Eingang, Ausgang oder Umlagerung. */
    public function buchen(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $a = $this->lade($p);
        $art = $r->text('art');
        $werte = $this->werte($r, self::BUCHUNG_FELDER);
        [$d, $fehler] = BuchungValidator::pruefe($werte, $art);
        if ($fehler !== []) {
            return $this->detail($a, ['art' => $art, 'werte' => $werte, 'fehler' => $fehler], 422);
        }
        try {
            $this->dienst->buche((int) $a['id'], $art, $d, $this->auth->benutzer()?->id);
        } catch (DomainFehler $e) {
            return $this->detail($this->lade($p), ['art' => $art, 'werte' => $werte, 'fehler' => [], 'meldung' => $e->getMessage()], 422);
        }
        $this->session->meldung('erfolg', 'Die Buchung wurde gespeichert.');
        return Response::redirect(url('/magazin/' . (int) $a['id']));
    }

    /** POST /magazin/buchungen/{id}/gegenbuchung */
    public function gegenbuchen(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $buchungId = self::id($p);
        $b = $buchungId > 0 ? $this->magazin->buchung($buchungId) : null;
        if ($b === null) {
            throw new HttpFehler(404);
        }
        try {
            $this->dienst->gegenbuchung($buchungId, $this->auth->benutzer()?->id);
            $this->session->meldung('erfolg', 'Die Gegenbuchung wurde gespeichert.');
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/magazin/' . (int) $b['artikel_id']));
    }

    /** POST /magazin/{id}/fotos: nur Bilder; das erste wird Titelbild, solange keins gesetzt ist. */
    public function fotos(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $a = $this->lade($p);
        $id = (int) $a['id'];
        $ziel = Response::redirect(url('/magazin/' . $id) . '#fotos');
        if (DateiController::normalisiere($r->file('fotos')) === []) {
            $this->session->meldung('fehler', 'Es wurde kein Foto ausgewählt.');
            return $ziel;
        }
        $benutzerId = $this->auth->benutzer()?->id;
        $gespeichert = (new ObjektFotos($this->upload, $this->dokumente, $this->session))
            ->speichere($r->file('fotos'), MagazinDienst::OBJEKT_ARTIKEL, $id, $benutzerId, 'einen Magazinartikel');
        foreach ($gespeichert as $foto) {
            $titel = (string) ($this->dokumente->finde($foto['id'])['titel'] ?? $foto['name']);
            $this->protokoll->schreibe($benutzerId, MagazinDienst::OBJEKT_ARTIKEL, $id, 'geaendert', ['datei' => ['alt' => null, 'neu' => $titel]]);
        }
        $n = count($gespeichert);
        if ($n > 0) {
            $this->session->meldung('erfolg', $n === 1 ? 'Das Foto wurde hinzugefügt.' : $n . ' Fotos wurden hinzugefügt.');
        }
        return $ziel;
    }

    // ---- Sammlungen -------------------------------------------------------------------------------------------------

    /** GET /magazin/sammlungen */
    public function sammlungen(Request $r): Response
    {
        return $this->seite('magazin/sammlungen', [
            'titel' => 'Sammlungen',
            'sammlungen' => $this->magazin->sammlungen(),
            'istBoersenwart' => $this->istBoersenwart(),
        ]);
    }

    /** GET /magazin/sammlungen/neu */
    public function sammlungNeu(Request $r): Response
    {
        $this->verlangeBoersenwart();
        return $this->sammlungFormular(null, ['name' => '', 'notiz' => '', 'artikel' => []], []);
    }

    /** POST /magazin/sammlungen */
    public function sammlungAnlegen(Request $r): Response
    {
        $this->verlangeBoersenwart();
        [$werte, $fehler] = $this->sammlungEingabe($r);
        if ($fehler !== []) {
            return $this->sammlungFormular(null, $werte, $fehler, 422);
        }
        try {
            $id = $this->dienst->legeSammlungAn($werte['name'], self::oderNull($werte['notiz']), $werte['artikel'], $this->auth->benutzer()?->id);
        } catch (DomainFehler $e) {
            return $this->sammlungFormular(null, $werte, [], 422, $e->getMessage());
        }
        $this->session->meldung('erfolg', 'Die Sammlung wurde angelegt.');
        return Response::redirect(url('/magazin/sammlungen/' . $id));
    }

    /** GET /magazin/sammlungen/{id} (mit ?bearbeiten=1 das Formular, nur Börsenwart) */
    public function sammlungAnzeigen(Request $r, array $p): Response
    {
        $s = $this->ladeSammlung($p);
        if ($r->text('bearbeiten') === '1') {
            $this->verlangeBoersenwart();
            $werte = ['name' => (string) $s['name'], 'notiz' => (string) ($s['notiz'] ?? ''), 'artikel' => array_map(fn(array $a): int => (int) $a['id'], $s['artikel'])];
            return $this->sammlungFormular($s, $werte, [], 200, null, (string) $s['geaendert_am']);
        }
        return $this->sammlungSeite($s);
    }

    /** POST /magazin/sammlungen/{id} */
    public function sammlungAendern(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $s = $this->ladeSammlung($p);
        $stand = $r->text('stand');
        [$werte, $fehler] = $this->sammlungEingabe($r);
        if ($fehler !== []) {
            return $this->sammlungFormular($s, $werte, $fehler, 422, null, $stand);
        }
        try {
            $this->dienst->aktualisiereSammlung((int) $s['id'], $werte['name'], self::oderNull($werte['notiz']), $werte['artikel'], $stand, $this->auth->benutzer()?->id);
        } catch (KonfliktFehler $e) {
            return $this->sammlungFormular($s, $werte, [], 409, $e->getMessage(), $stand);
        } catch (DomainFehler $e) {
            return $this->sammlungFormular($s, $werte, [], 422, $e->getMessage(), $stand);
        }
        $this->session->meldung('erfolg', 'Die Sammlung wurde gespeichert.');
        return Response::redirect(url('/magazin/sammlungen/' . (int) $s['id']));
    }

    /** POST /magazin/sammlungen/{id}/umlagern: alle Artikel der Sammlung an einen neuen Lagerort. */
    public function sammlungUmlagern(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $s = $this->ladeSammlung($p);
        $lagerort = trim($r->text('lagerort'));
        $fehler = null;
        if ($lagerort === '') {
            $fehler = BuchungValidator::KEIN_LAGERORT;
        } elseif (mb_strlen($lagerort) > BuchungValidator::LAGERORT_MAX) {
            $fehler = 'Bitte höchstens ' . BuchungValidator::LAGERORT_MAX . ' Zeichen eingeben.';
        }
        if ($fehler === null) {
            try {
                $n = $this->dienst->umlagereSammlung((int) $s['id'], $lagerort, $this->auth->benutzer()?->id);
                $this->session->meldung('erfolg', $n === 1 ? '1 Artikel wurde umgelagert.' : $n . ' Artikel wurden umgelagert.');
                return Response::redirect(url('/magazin/sammlungen/' . (int) $s['id']));
            } catch (DomainFehler $e) {
                $fehler = $e->getMessage();
            }
        }
        return $this->sammlungSeite($s, ['lagerort' => $lagerort, 'fehler' => $fehler], 422);
    }

    // ---- Hilfen -----------------------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $a
     * @param array{art?:string, werte?:array<string,string>, fehler?:array<string,string>, meldung?:string} $buchung
     */
    private function detail(array $a, array $buchung = [], int $status = 200): Response
    {
        $id = (int) $a['id'];
        return $this->seite('magazin/detail', [
            'titel' => $a['code'] . ' ' . $a['bezeichnung'],
            'artikel' => $a,
            'buchungen' => $this->magazin->buchungen($id),
            'fotos' => array_values(array_filter($this->dokumente->fuerObjekt(MagazinDienst::OBJEKT_ARTIKEL, $id), fn(array $d): bool => $d['kategorie'] === 'foto')),
            'verlauf' => $this->protokoll->fuerObjekt(MagazinDienst::OBJEKT_ARTIKEL, $id),
            'istBoersenwart' => $this->istBoersenwart(),
            'buchungArt' => $buchung['art'] ?? 'eingang',
            'buchungWerte' => $buchung['werte'] ?? [],
            'buchungFehler' => $buchung['fehler'] ?? [],
            'buchungMeldung' => $buchung['meldung'] ?? null,
        ], $status);
    }

    /**
     * @param ?array<string,mixed> $artikel null bei der Neuanlage
     * @param array<string,string> $werte
     * @param array<string,string> $fehler
     */
    private function formular(?array $artikel, array $werte, array $fehler, int $status = 200, ?string $meldung = null, string $stand = ''): Response
    {
        return $this->seite('magazin/formular', [
            'titel' => $artikel === null ? 'Artikel anlegen' : 'Artikel bearbeiten',
            'artikel' => $artikel,
            'werte' => $werte,
            'fehler' => $fehler,
            'meldung' => $meldung,
            'stand' => $stand,
            'kategorien' => $this->magazin->kategorien(),
            'spurweiten' => $this->spurweiten->alle(),
        ], $status);
    }

    /**
     * @param array<string,mixed> $s
     * @param array{lagerort?:string, fehler?:?string} $umlagern
     */
    private function sammlungSeite(array $s, array $umlagern = [], int $status = 200): Response
    {
        return $this->seite('magazin/sammlung', [
            'titel' => 'Sammlung ' . $s['name'],
            'sammlung' => $s,
            'verlauf' => $this->protokoll->fuerObjekt(MagazinDienst::OBJEKT_SAMMLUNG, (int) $s['id']),
            'istBoersenwart' => $this->istBoersenwart(),
            'umlagernWert' => $umlagern['lagerort'] ?? '',
            'umlagernFehler' => $umlagern['fehler'] ?? null,
        ], $status);
    }

    /**
     * @param ?array<string,mixed> $s
     * @param array{name:string, notiz:string, artikel:list<int>} $werte
     * @param array<string,string> $fehler
     */
    private function sammlungFormular(?array $s, array $werte, array $fehler, int $status = 200, ?string $meldung = null, string $stand = ''): Response
    {
        return $this->seite('magazin/sammlung_formular', [
            'titel' => $s === null ? 'Sammlung anlegen' : 'Sammlung bearbeiten',
            'sammlung' => $s,
            'werte' => $werte,
            'fehler' => $fehler,
            'meldung' => $meldung,
            'stand' => $stand,
            'auswahl' => $this->magazin->artikelFuerSammlung($s === null ? null : (int) $s['id']),
        ], $status);
    }

    /** @return array{0: array{name:string, notiz:string, artikel:list<int>}, 1: array<string,string>} */
    private function sammlungEingabe(Request $r): array
    {
        $name = trim($r->text('name'));
        $notiz = trim($r->text('notiz'));
        $roh = $r->post('artikel', []);
        $ids = [];
        foreach (is_array($roh) ? $roh : [] as $wert) {
            if (is_string($wert) && preg_match('/^\d{1,10}$/', $wert) === 1 && (int) $wert > 0 && (int) $wert <= 4294967295) {
                $ids[] = (int) $wert;
            }
        }
        $fehler = [];
        if ($name === '') {
            $fehler['name'] = 'Bitte einen Namen eingeben.';
        } elseif (mb_strlen($name) > 150) {
            $fehler['name'] = 'Bitte höchstens 150 Zeichen eingeben.';
        }
        if (mb_strlen($notiz) > 5000) {
            $fehler['notiz'] = 'Bitte höchstens 5000 Zeichen eingeben.';
        }
        return [['name' => $name, 'notiz' => $notiz, 'artikel' => array_values(array_unique($ids))], $fehler];
    }

    /**
     * @param array<string,string> $werte
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    private function pruefeArtikel(array $werte, bool $neu): array
    {
        $kategorien = $this->magazin->kategorien();
        $literatur = null;
        foreach ($kategorien as $k) {
            if ($k['schluessel'] === 'literatur') {
                $literatur = (int) $k['id'];
            }
        }
        return ArtikelValidator::pruefe(
            $werte,
            array_map(fn(array $k): int => (int) $k['id'], $kategorien),
            array_map(fn(array $s): int => (int) $s['id'], $this->spurweiten->alle()),
            $literatur,
            $neu
        );
    }

    /**
     * Eingaben so, wie sie getippt wurden (für das Formular nach Fehlern).
     *
     * @param list<string> $felder
     * @return array<string,string>
     */
    private function werte(Request $r, array $felder): array
    {
        $w = [];
        foreach ($felder as $f) {
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
        $id = self::id($p);
        return ($id > 0 ? $this->magazin->finde($id) : null) ?? throw new HttpFehler(404);
    }

    /** @return array<string,mixed> */
    private function ladeSammlung(array $p): array
    {
        $id = self::id($p);
        return ($id > 0 ? $this->magazin->sammlung($id) : null) ?? throw new HttpFehler(404);
    }

    private static function id(array $p): int
    {
        $roh = (string) ($p['id'] ?? '');
        return preg_match('/^\d{1,10}$/', $roh) === 1 && (int) $roh <= 4294967295 ? (int) $roh : 0;
    }

    private static function oderNull(string $w): ?string
    {
        return $w === '' ? null : $w;
    }
}
