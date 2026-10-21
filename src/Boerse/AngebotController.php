<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Auth\Auth;
use Emc\Auth\Benutzer;
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
use Emc\Magazin\MagazinRepository;
use Emc\Personen\PersonRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturValidator;
use Emc\Stammdaten\SpurweiteRepository;

/**
 * Angebote der Börse (intern): lesen alle Angemeldeten. Mitglieder legen eigene Angebote an, bearbeiten sie, ziehen
 * sie zurück und schlagen sie zur Veröffentlichung vor; der Börsenwart legt Vereinsangebote an (auch aus dem Magazin),
 * gibt frei, reserviert und bietet wieder an. Rechte am einzelnen Angebot prüft der AngebotDienst (403).
 */
final class AngebotController
{
    private const FELDER = ['bezeichnung', 'beschreibung', 'kategorie_id', 'spurweite_id', 'zustand', 'wunschpreis', 'menge', 'herkunft', 'person_id', 'erloes_spende', 'boerse_id', 'notiz'];
    public const RUECKFALL = 'Das Angebot wurde gespeichert. Weil es öffentlich war, muss der Börsenwart es erneut freigeben.';

    public function __construct(
        private View $view,
        private AngebotRepository $angebote,
        private AngebotDienst $dienst,
        private BoerseRepository $boersen,
        private MagazinRepository $magazin,
        private SpurweiteRepository $spurweiten,
        private PersonRepository $personen,
        private DokumentRepository $dokumente,
        private UploadService $upload,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
    ) {
    }

    /** GET /angebote (meine=1: eigene Angebote) */
    public function liste(Request $r): Response
    {
        $b = $this->benutzer();
        $filter = AngebotFilter::ausRequest($r, $b);
        return $this->seite('boerse/angebote', [
            'titel' => $filter->personId !== null ? 'Meine Angebote' : 'Angebote',
            'filter' => $filter,
            'angebote' => $this->angebote->suche($filter),
            'boersen' => $this->boersen->alle(),
            'istBoersenwart' => Berechtigung::istBoersenwart($b),
            'personId' => $b->personId,
        ]);
    }

    /** GET /angebote/neu (?magazin=<id> bzw. ?sammlung=<id>: Vereinsangebot aus dem Magazin, nur Börsenwart) */
    public function neu(Request $r): Response
    {
        $b = $this->benutzer();
        $boersenwart = Berechtigung::istBoersenwart($b);
        $werte = ['zustand' => 'gebraucht', 'menge' => '1', 'herkunft' => $boersenwart ? 'verein' : 'mitglied', 'boerse_id' => self::vorgabeBoerse($this->boersen->offene())];
        $quelle = null;
        $artikelId = $r->ganzzahl('magazin', 1, 4294967295);
        $sammlungId = $r->ganzzahl('sammlung', 1, 4294967295);
        if ($artikelId !== null || $sammlungId !== null) {
            Berechtigung::verlangeBoersenwart($b);
            [$quelle, $vorbelegt] = $this->quelle($artikelId, $sammlungId);
            $werte = $vorbelegt + $werte;
        }
        return $this->formular(null, $werte, [], $quelle);
    }

    /** POST /angebote */
    public function anlegen(Request $r): Response
    {
        $b = $this->benutzer();
        $boersenwart = Berechtigung::istBoersenwart($b);
        $werte = $this->werte($r);
        $artikelId = $r->ganzzahl('magazin_artikel_id', 1, 4294967295);
        $sammlungId = $r->ganzzahl('magazin_sammlung_id', 1, 4294967295);
        $quelle = null;
        if ($artikelId !== null || $sammlungId !== null) {
            // Vereinsangebote aus dem Magazin legt nur der Börsenwart an.
            Berechtigung::verlangeBoersenwart($b);
            [$quelle] = $this->quelle($artikelId, $sammlungId);
            $werte['herkunft'] = 'verein';
        }
        [$d, $fehler] = $this->pruefe($werte, $b);
        if ($fehler !== []) {
            return $this->formular(null, $werte, $fehler, $quelle, 422);
        }
        $d['magazin_artikel_id'] = $artikelId;
        $d['magazin_sammlung_id'] = $artikelId === null ? $sammlungId : null;
        $d['oeffentlich'] = $r->text('veroeffentlichen') === '1' ? ($boersenwart ? 'freigegeben' : 'vorgeschlagen') : 'nein';
        try {
            $id = $this->dienst->anlegen($d, $b, new \DateTimeImmutable());
        } catch (DomainFehler $e) {
            return $this->formular(null, $werte, [], $quelle, 422, $e->getMessage());
        }
        $a = $this->angebote->finde($id);
        $this->session->meldung('erfolg', 'Das Angebot wurde als ' . ($a['nummer'] ?? '') . ' angelegt.');
        return Response::redirect(url('/angebote/' . $id));
    }

    /** GET /angebote/{id} */
    public function anzeigen(Request $r, array $p): Response
    {
        return $this->detail($this->lade($p));
    }

    /** GET /angebote/{id}/bearbeiten */
    public function bearbeiten(Request $r, array $p): Response
    {
        $b = $this->benutzer();
        $a = $this->lade($p);
        try {
            $this->dienst->pruefeAenderbar((int) $a['id'], $b);
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
            return Response::redirect(url('/angebote/' . (int) $a['id']));
        }
        $werte = [];
        foreach (self::FELDER as $f) {
            $werte[$f] = $a[$f] === null ? '' : (string) $a[$f];
        }
        $werte['wunschpreis'] = ReparaturValidator::betragEingabe((string) $a['wunschpreis']);
        $werte['boerse_id'] = $a['boerse_id'] === null ? 'dauerhaft' : (string) $a['boerse_id'];
        return $this->formular($a, $werte, [], null, 200, null, (string) $a['geaendert_am']);
    }

    /** POST /angebote/{id} */
    public function aendern(Request $r, array $p): Response
    {
        $b = $this->benutzer();
        $a = $this->lade($p);
        if (!Berechtigung::darfAngebotBearbeiten($b, $a)) {
            throw new HttpFehler(403);
        }
        $id = (int) $a['id'];
        $stand = $r->text('stand');
        $werte = $this->werte($r);
        $werte['herkunft'] = (string) $a['herkunft'];
        [$d, $fehler] = $this->pruefe($werte, $b, $a);
        if ($fehler !== []) {
            return $this->formular($a, $werte, $fehler, null, 422, null, $stand);
        }
        try {
            $rueckfall = $this->dienst->aendern($id, $d, $stand, $b);
        } catch (KonfliktFehler $e) {
            return $this->formular($a, $werte, [], null, 409, $e->getMessage(), $stand);
        } catch (DomainFehler $e) {
            return $this->formular($a, $werte, [], null, 422, $e->getMessage(), $stand);
        }
        $this->session->meldung($rueckfall ? 'warnung' : 'erfolg', $rueckfall ? self::RUECKFALL : 'Das Angebot wurde gespeichert.');
        return Response::redirect(url('/angebote/' . $id));
    }

    /** POST /angebote/{id}/fotos: nur Bilder; das erste wird Titelbild. Ein Mitglied löst den Rückfall der Freigabe aus. */
    public function fotos(Request $r, array $p): Response
    {
        $b = $this->benutzer();
        $a = $this->lade($p);
        $id = (int) $a['id'];
        $ziel = Response::redirect(url('/angebote/' . $id) . '#fotos');
        try {
            $this->dienst->pruefeAenderbar($id, $b);
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
            return $ziel;
        }
        if (DateiController::normalisiere($r->file('fotos')) === []) {
            $this->session->meldung('fehler', 'Es wurde kein Foto ausgewählt.');
            return $ziel;
        }
        $gespeichert = (new ObjektFotos($this->upload, $this->dokumente, $this->session))
            ->speichere($r->file('fotos'), AngebotDienst::OBJEKT, $id, $b->id, 'ein Angebot');
        foreach ($gespeichert as $foto) {
            $titel = (string) ($this->dokumente->finde($foto['id'])['titel'] ?? $foto['name']);
            $this->protokoll->schreibe($b->id, AngebotDienst::OBJEKT, $id, 'geaendert', ['datei' => ['alt' => null, 'neu' => $titel]]);
        }
        $n = count($gespeichert);
        if ($n > 0) {
            $this->session->meldung('erfolg', $n === 1 ? 'Das Foto wurde hinzugefügt.' : $n . ' Fotos wurden hinzugefügt.');
            if ($this->dienst->fotoGeaendert($id, $b)) {
                $this->session->meldung('warnung', 'Weil das Angebot öffentlich war, muss der Börsenwart es erneut freigeben.');
            }
        }
        return $ziel;
    }

    /** POST /angebote/{id}/oeffentlich (nach = nein, vorgeschlagen oder freigegeben) */
    public function oeffentlich(Request $r, array $p): Response
    {
        $b = $this->benutzer();
        $a = $this->lade($p);
        $nach = $r->text('nach');
        if (!in_array($nach, Freigabe::WERTE, true)) {
            throw new HttpFehler(403);
        }
        try {
            $this->dienst->setzeOeffentlich((int) $a['id'], $nach, $b);
            $this->session->meldung('erfolg', match ($nach) {
                'freigegeben' => 'Das Angebot ist jetzt öffentlich.',
                'vorgeschlagen' => 'Das Angebot ist zur Freigabe vorgeschlagen.',
                default => 'Das Angebot ist nicht mehr öffentlich.',
            });
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/angebote/' . (int) $a['id']));
    }

    /** POST /angebote/{id}/status (aktion = zurueckziehen, reservieren, reservierung_aufheben, wieder_anbieten) */
    public function status(Request $r, array $p): Response
    {
        $b = $this->benutzer();
        $a = $this->lade($p);
        $aktion = $r->text('aktion');
        if (!in_array($aktion, AngebotDienst::AKTIONEN, true)) {
            // „verkauft“ setzt nur die Kasse.
            return $this->detail($a, AngebotDienst::AKTION_UNBEKANNT, 422);
        }
        $boerse = $r->post('boerse_id');
        try {
            $this->dienst->setzeStatus((int) $a['id'], $aktion, $b, is_string($boerse) ? $boerse : null);
            $this->session->meldung('erfolg', match ($aktion) {
                'zurueckziehen' => 'Das Angebot wurde zurückgezogen.',
                'reservieren' => 'Das Angebot ist reserviert.',
                'reservierung_aufheben' => 'Die Reservierung wurde aufgehoben.',
                default => 'Das Angebot wird wieder angeboten.',
            });
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/angebote/' . (int) $a['id']));
    }

    // ---- Hilfen -----------------------------------------------------------------------------------------------------

    /** @param array<string,mixed> $a */
    private function detail(array $a, ?string $meldung = null, int $status = 200): Response
    {
        $b = $this->benutzer();
        $id = (int) $a['id'];
        $boersenwart = Berechtigung::istBoersenwart($b);
        $eigenes = $a['herkunft'] === 'mitglied' && $a['person_id'] !== null && (int) $a['person_id'] === $b->personId;
        $aenderbar = $a['status'] !== 'verkauft' && $a['boerse_status'] !== 'abgeschlossen';
        $freigaben = [];
        if (Berechtigung::darfAngebotBearbeiten($b, $a) && $aenderbar) {
            foreach (Freigabe::WERTE as $nach) {
                if (Freigabe::erlaubt((string) $a['oeffentlich'], $nach, $boersenwart, (string) $a['herkunft'])) {
                    $freigaben[] = $nach;
                }
            }
        }
        return $this->seite('boerse/angebot', [
            'titel' => $a['nummer'] . ' ' . $a['bezeichnung'],
            'angebot' => $a,
            'fotos' => array_values(array_filter($this->dokumente->fuerObjekt(AngebotDienst::OBJEKT, $id), fn(array $d): bool => $d['kategorie'] === 'foto')),
            'verlauf' => $this->protokoll->fuerObjekt(AngebotDienst::OBJEKT, $id),
            'istBoersenwart' => $boersenwart,
            'istEigenes' => $eigenes,
            'darfBearbeiten' => Berechtigung::darfAngebotBearbeiten($b, $a) && $aenderbar,
            'freigaben' => $freigaben,
            'offeneBoersen' => $this->boersen->offene(),
            'meldung' => $meldung,
        ], $status);
    }

    /**
     * @param ?array<string,mixed> $angebot null bei der Neuanlage
     * @param array<string,string> $werte
     * @param array<string,string> $fehler
     * @param ?array<string,mixed> $quelle Magazinartikel bzw. Sammlung (Neuanlage aus dem Magazin)
     */
    private function formular(?array $angebot, array $werte, array $fehler, ?array $quelle, int $status = 200, ?string $meldung = null, string $stand = ''): Response
    {
        $b = $this->benutzer();
        $boersenwart = Berechtigung::istBoersenwart($b);
        $boersen = $this->boersen->offene();
        return $this->seite('boerse/angebot_formular', [
            'titel' => $angebot === null ? 'Angebot anlegen' : 'Angebot bearbeiten',
            'angebot' => $angebot,
            'werte' => $werte,
            'fehler' => $fehler,
            'meldung' => $meldung,
            'stand' => $stand,
            'quelle' => $quelle,
            'istBoersenwart' => $boersenwart,
            'kategorien' => $this->magazin->kategorien(),
            'spurweiten' => $this->spurweiten->alle(),
            'personen' => $boersenwart ? $this->personen->alle() : [],
            'boersen' => $boersen,
        ], $status);
    }

    /**
     * Vereinsangebot aus dem Magazin: Quelle für die Anzeige und Vorbelegung des Formulars.
     *
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    private function quelle(?int $artikelId, ?int $sammlungId): array
    {
        if ($artikelId !== null) {
            $a = $this->magazin->finde($artikelId) ?? throw new HttpFehler(404);
            $frei = $a['bestand'] - $this->angebote->offeneMengeFuerArtikel($artikelId);
            return [
                ['art' => 'artikel', 'id' => $artikelId, 'code' => $a['code'], 'bezeichnung' => $a['bezeichnung'], 'frei' => $frei],
                [
                    'bezeichnung' => (string) $a['bezeichnung'],
                    'kategorie_id' => (string) $a['kategorie_id'],
                    'spurweite_id' => $a['spurweite_id'] === null ? '' : (string) $a['spurweite_id'],
                    'zustand' => (string) $a['zustand'],
                    'herkunft' => 'verein',
                ],
            ];
        }
        $s = $this->magazin->sammlung((int) $sammlungId) ?? throw new HttpFehler(404);
        $kategorie = $s['artikel'][0]['kategorie_id'] ?? ($this->magazin->kategorieNachSchluessel('sonstiges')['id'] ?? '');
        return [
            ['art' => 'sammlung', 'id' => (int) $s['id'], 'bezeichnung' => $s['name'], 'artikel' => count($s['artikel']), 'bestand' => array_sum(array_column($s['artikel'], 'bestand'))],
            ['bezeichnung' => (string) $s['name'], 'kategorie_id' => (string) $kategorie, 'zustand' => 'gebraucht', 'menge' => '1', 'herkunft' => 'verein', 'beschreibung' => (string) ($s['notiz'] ?? '')],
        ];
    }

    /**
     * @param array<string,string> $werte
     * @param ?array<string,mixed> $angebot beim Bearbeiten
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    private function pruefe(array $werte, Benutzer $b, ?array $angebot = null): array
    {
        $boersenwart = Berechtigung::istBoersenwart($b);
        $personIds = $boersenwart
            ? array_map(fn(array $p): int => (int) $p['id'], $this->personen->alle(false))
            : ($angebot !== null && $angebot['person_id'] !== null ? [(int) $angebot['person_id']] : [$b->personId]);
        $boersenIds = array_map(fn(array $x): int => (int) $x['id'], $this->boersen->offene());
        if ($angebot !== null && $angebot['boerse_id'] !== null) {
            $boersenIds[] = (int) $angebot['boerse_id']; // die bisherige Börse (ist sie abgeschlossen, meldet das der Dienst)
        }
        return AngebotValidator::pruefe(
            $werte,
            $boersenwart,
            $personIds,
            $boersenIds,
            array_map(fn(array $k): int => (int) $k['id'], $this->magazin->kategorien()),
            array_map(fn(array $s): int => (int) $s['id'], $this->spurweiten->alle()),
        );
    }

    /**
     * Vorgabe im Formular: die nächste offene Börse, sonst „dauerhaft“.
     *
     * @param list<array<string,mixed>> $offene nach Datum
     */
    private static function vorgabeBoerse(array $offene): string
    {
        $heute = date('Y-m-d');
        foreach ($offene as $x) {
            if ((string) $x['datum'] >= $heute) {
                return (string) $x['id'];
            }
        }
        return 'dauerhaft';
    }

    /** @return array<string,string> */
    private function werte(Request $r): array
    {
        $w = [];
        foreach (self::FELDER as $f) {
            $w[$f] = $r->text($f);
        }
        return $w;
    }

    /** @param array<string,mixed> $daten */
    private function seite(string $template, array $daten, int $status = 200): Response
    {
        return new Response($this->view->render($template, $daten), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function benutzer(): Benutzer
    {
        return $this->auth->benutzer() ?? throw new HttpFehler(403);
    }

    /** @return array<string,mixed> */
    private function lade(array $p): array
    {
        $roh = (string) ($p['id'] ?? '');
        $id = preg_match('/^\d{1,10}$/', $roh) === 1 && (int) $roh <= 4294967295 ? (int) $roh : 0;
        return ($id > 0 ? $this->angebote->finde($id) : null) ?? throw new HttpFehler(404);
    }
}
