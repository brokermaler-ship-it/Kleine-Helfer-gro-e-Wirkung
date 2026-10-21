<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

use Emc\Auth\Auth;
use Emc\Auth\Berechtigung;
use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\Transaktion;
use Emc\Core\View;
use Emc\Dateien\DokumentRepository;
use Emc\Digital\Adresspruefung;
use Emc\Digital\AdressValidator;
use Emc\Digital\DecoderRepository;
use Emc\Digital\Protokoll;
use Emc\Magazin\BuchungValidator;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\OneDrive\Uebertragung;
use Emc\Personen\PersonRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Reparatur\ReparaturDienst;
use Emc\Stammdaten\SpurweiteRepository;
use Emc\Wartung\WartungDienst;

final class FahrzeugController
{
    private const FORMULARFELDER = [
        'bezeichnung', 'baureihe', 'bahnverwaltung', 'epoche', 'gattung', 'hersteller', 'artikelnummer',
        'spurweite_id', 'stromsystem', 'eigentuemer_person_id', 'status', 'betriebsstunden', 'notiz',
    ];

    public function __construct(
        private View $view,
        private FahrzeugRepository $fahrzeuge,
        private SpurweiteRepository $spurweiten,
        private PersonRepository $personen,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
        private DecoderRepository $decoder,
        private Adresspruefung $adresspruefung,
        private DokumentRepository $dokumente,
        private bool $onedriveKonfiguriert = false,
        private ?Uebertragung $uebertragung = null,
        private ?WartungDienst $wartung = null,
        private ?ReparaturDienst $reparatur = null,
        private ?MagazinDienst $magazin = null,
        private ?MagazinRepository $magazinArtikel = null,
    ) {
    }

    public function liste(Request $r): Response
    {
        $filter = FahrzeugFilter::ausRequest($r);
        $benutzer = $this->auth->benutzer();
        $meine = $benutzer !== null && !$filter->istGesetzt() ? $this->fahrzeuge->sucheEigene($benutzer->personId) : [];
        try {
            $fahrzeuge = $this->fahrzeuge->suche($filter);
        } catch (\PDOException $e) {
            // Filter „nur ungeprüfte“ ohne die Spalte aus Migration 020 (Update steht aus oder ist abgebrochen).
            if (!$filter->ungeprueft || (string) $e->getCode() !== '42S22') {
                throw $e;
            }
            $this->session->meldung('warnung', 'Bitte zuerst das Datenbank-Update ausführen.');
            return Response::redirect(url('/admin/update'));
        }
        return $this->html('fahrzeuge/liste', [
            'titel' => 'Fahrzeuge',
            'meine' => $meine,
            'fahrzeuge' => $fahrzeuge,
            'filter' => $filter,
            'spurweiten' => $this->spurweiten->alle(),
            'personen' => $this->personen->alle(false),
        ]);
    }

    /** Ausgemusterte Fahrzeuge mit denselben Filtern wie die Fahrzeugliste (der Status ist fest). */
    public function abstellgleis(Request $r): Response
    {
        $f = FahrzeugFilter::ausRequest($r);
        $filter = new FahrzeugFilter($f->text, $f->spurweiteId, $f->eigentuemer, $f->personId, 'ausgemustert', $f->gattung);
        $fahrzeuge = $this->fahrzeuge->suche($filter);
        return $this->html('fahrzeuge/abstellgleis', [
            'titel' => 'Abstellgleis',
            'fahrzeuge' => $fahrzeuge,
            'magazinInfo' => $this->magazinFuer(array_map(fn(array $fz): int => (int) $fz['id'], $fahrzeuge)),
            'filter' => $f,
            'spurweiten' => $this->spurweiten->alle(),
            'personen' => $this->personen->alle(false),
        ]);
    }

    public function abstellen(Request $r, array $p): Response
    {
        return $this->stelleAb($p, true, 'Das Fahrzeug steht jetzt auf dem Abstellgleis.');
    }

    public function zurueckholen(Request $r, array $p): Response
    {
        return $this->stelleAb($p, false, 'Das Fahrzeug ist wieder in Betrieb.');
    }

    /**
     * POST /fahrzeuge/{id}/an-magazin: ausgemustertes Vereinsfahrzeug ans Magazin übergeben (Börsenwart oder
     * Fahrzeugwart und höher). Die fachlichen Prüfungen macht der MagazinDienst unter der Sperre des Fahrzeugs.
     */
    public function anMagazin(Request $r, array $p): Response
    {
        $benutzer = $this->auth->benutzer();
        if (!Berechtigung::darfFahrzeugAnMagazin($benutzer)) {
            throw new HttpFehler(403);
        }
        $fz = $this->lade($p);
        $dienst = $this->magazin ?? throw new \LogicException('FahrzeugController braucht den MagazinDienst für die Übergabe.');
        $zurueck = Response::redirect(url('/fahrzeuge/' . $fz['id']));
        $lagerort = trim($r->text('lagerort'));
        if (mb_strlen($lagerort) > BuchungValidator::LAGERORT_MAX) {
            $this->session->meldung('fehler', 'Bitte für den Lagerort höchstens ' . BuchungValidator::LAGERORT_MAX . ' Zeichen eingeben.');
            return $zurueck;
        }
        try {
            $id = $dienst->uebernimmFahrzeug((int) $fz['id'], $lagerort, $benutzer?->id, new \DateTimeImmutable());
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
            return $zurueck;
        }
        $this->session->meldung('erfolg', 'Das Fahrzeug wurde als M' . $id . ' ans Magazin übergeben.');
        return Response::redirect(url('/magazin/' . $id));
    }

    /**
     * POST /fahrzeuge/{id}/geprueft: Kennzeichen „ungeprüft“ (aus dem Import) löschen; wer das Fahrzeug bearbeiten darf.
     * Ein schon geprüftes Fahrzeug bleibt unverändert (kein weiterer Protokolleintrag).
     */
    public function alsGeprueft(Request $r, array $p): Response
    {
        $fz = $this->ladeBearbeitbar($p);
        try {
            $geaendert = $this->fahrzeuge->markiereGeprueft((int) $fz['id']);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '42S22') {
                throw $e;
            }
            $this->session->meldung('warnung', 'Bitte zuerst das Datenbank-Update ausführen.');
            return Response::redirect(url('/admin/update'));
        }
        if ($geaendert) {
            $this->protokoll->schreibe($this->auth->benutzer()?->id, 'fahrzeug', (int) $fz['id'], 'geaendert', [
                'geprüft' => ['alt' => 'nein', 'neu' => 'ja'],
            ]);
        }
        $this->session->meldung('erfolg', 'Das Fahrzeug ist jetzt als geprüft markiert.');
        return Response::redirect(url('/fahrzeuge/' . $fz['id']));
    }

    private function stelleAb(array $p, bool $abgestellt, string $meldung): Response
    {
        $fz = $this->ladeBearbeitbar($p);
        if (($fz['status'] === 'ausgemustert') !== $abgestellt) {
            $id = (int) $fz['id'];
            $benutzerId = $this->auth->benutzer()?->id;
            $arbeit = function () use ($fz, $id, $abgestellt, $benutzerId): bool {
                // Unter der Sperre des Fahrzeugs (fahrzeug → magazin_artikel): ein ans Magazin übergebenes Fahrzeug
                // bleibt auf dem Abstellgleis.
                $imMagazin = $abgestellt ? null : $this->magazinFuer([$id])[$id] ?? null;
                if ($imMagazin !== null) {
                    throw new DomainFehler('Das Fahrzeug wurde ans Magazin übergeben (' . $imMagazin['code'] . ') und kann nicht zurückgeholt werden.');
                }
                $this->fahrzeuge->setzeAbgestellt($id, $abgestellt);
                $neu = $this->fahrzeuge->finde($id);
                if ($neu === null) {
                    return false;
                }
                $diff = Aenderungsprotokoll::diff($this->vergleichswerte($fz), $this->vergleichswerte($neu));
                if ($diff !== []) {
                    $this->protokoll->schreibe($benutzerId, 'fahrzeug', $id, 'geaendert', $diff);
                }
                return true;
            };
            // Mit Reparaturen: unter der Sperre des Fahrzeugs und mit automatischem Status – wer ein Fahrzeug mit offener
            // dringender Reparatur zurückholt, bekommt „defekt“ statt „einsatzbereit“.
            try {
                $ok = $this->reparatur !== null ? $this->reparatur->inTransaktion('fahrzeug', $id, $benutzerId, $arbeit) : $arbeit();
            } catch (DomainFehler $e) {
                $this->session->meldung('fehler', $e->getMessage());
                return Response::redirect(url('/fahrzeuge/' . $fz['id']));
            }
            if ($ok !== true) {
                throw new HttpFehler(404); // zwischenzeitlich gelöscht
            }
            $this->session->meldung('erfolg', $meldung);
        }
        return Response::redirect(url('/fahrzeuge/' . $fz['id']));
    }

    public function anzeigen(Request $r, array $p): Response
    {
        $fz = $this->lade($p);
        return $this->html('fahrzeuge/detail', [
            'titel' => $fz['inventarnummer'] . ' ' . $fz['bezeichnung'],
            'fahrzeug' => $fz,
            'verlauf' => $this->protokoll->fuerObjekt('fahrzeug', (int) $fz['id']),
            'decoderListe' => $this->decoderMitPruefung($fz),
            'dateien' => $this->dokumente->fuerObjekt('fahrzeug', (int) $fz['id']),
            'onedriveKonfiguriert' => $this->onedriveKonfiguriert,
            'wartung' => $this->wartung?->blockDaten('fahrzeug', $fz, $this->auth->benutzer(), heute: new \DateTimeImmutable('today')),
            'reparaturen' => $this->reparatur?->blockDaten('fahrzeug', $fz, $this->auth->benutzer()),
            'magazin' => $this->magazinBlock($fz),
        ]);
    }

    /**
     * Block „Magazin“ auf der Fahrzeugseite: der Artikel, der aus dem Fahrzeug entstanden ist, und ob es übergeben
     * werden kann (ausgemustert, Vereinseigentum, noch nicht im Magazin, Recht). null ohne Magazin bzw. vor Migration 016.
     *
     * @param array<string,mixed> $fz
     * @return ?array{artikel: ?array{id:int, code:string, bestand:int, abgegeben_am:?string}, darfUebergeben: bool}
     */
    private function magazinBlock(array $fz): ?array
    {
        if ($this->magazinArtikel === null) {
            return null;
        }
        $id = (int) $fz['id'];
        $info = $this->magazinFuer([$id]);
        if ($info === null) {
            return null;
        }
        $artikel = $info[$id] ?? null;
        $uebergebbar = $artikel === null && $fz['status'] === 'ausgemustert' && $fz['eigentuemer_person_id'] === null;
        if ($artikel === null && !$uebergebbar) {
            return null;
        }
        return [
            'artikel' => $artikel,
            'darfUebergeben' => $uebergebbar && Berechtigung::darfFahrzeugAnMagazin($this->auth->benutzer()),
        ];
    }

    /**
     * Magazinartikel der Fahrzeuge nach Fahrzeug-ID; null, solange das Datenbank-Update (Migration 016) aussteht.
     *
     * @param list<int> $ids
     * @return ?array<int, array{id:int, code:string, bestand:int, abgegeben_am:?string}>
     */
    private function magazinFuer(array $ids): ?array
    {
        if ($this->magazinArtikel === null) {
            return null;
        }
        try {
            return $this->magazinArtikel->fuerFahrzeuge($ids);
        } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
                return null;
            }
            throw $e;
        }
    }

    public function neu(Request $r): Response
    {
        $werte = ['gattung' => 'lok', 'stromsystem' => '2L', 'status' => 'einsatzbereit', 'betriebsstunden' => '0'];
        if (!$this->darfEigentuemerWaehlen()) {
            $werte['eigentuemer_person_id'] = (string) $this->auth->benutzer()?->personId;
        }
        return $this->formular($werte, [], null);
    }

    public function bearbeiten(Request $r, array $p): Response
    {
        $fz = $this->ladeBearbeitbar($p);
        $werte = $fz;
        $werte['betriebsstunden'] = str_replace('.', ',', (string) (float) $fz['betriebsstunden']);
        return $this->formular($werte, [], $fz);
    }

    public function anlegen(Request $r): Response
    {
        [$daten, $fehler] = $this->lies($r, null);
        if ($fehler !== []) {
            return $this->formular($this->anzeigeWerte($daten, $r), $fehler, null, 422);
        }
        $id = $this->fahrzeuge->anlegen($daten, $this->auth->benutzer()?->id);
        $this->protokoll->schreibe($this->auth->benutzer()?->id, 'fahrzeug', $id, 'angelegt', Aenderungsprotokoll::diff([], $daten));
        $this->session->meldung('erfolg', 'Das Fahrzeug wurde angelegt.');
        return Response::redirect(url('/fahrzeuge/' . $id));
    }

    public function aendern(Request $r, array $p): Response
    {
        $fz = $this->ladeBearbeitbar($p);
        $stand = $r->text('_stand'); // Stand des ursprünglich geladenen Formulars, nie der aktuelle DB-Stand
        [$daten, $fehler] = $this->lies($r, $fz);
        // Ein ans Magazin übergebenes Fahrzeug bleibt ausgemustert, auch über das Formular.
        $imMagazin = $this->magazinFuer([(int) $fz['id']])[(int) $fz['id']] ?? null;
        if ($imMagazin !== null && $daten['status'] !== 'ausgemustert' && !isset($fehler['status'])) {
            $fehler['status'] = 'Das Fahrzeug wurde ans Magazin übergeben (' . $imMagazin['code'] . ') und bleibt auf dem Abstellgleis.';
        }
        if ($imMagazin !== null && $daten['eigentuemer_person_id'] !== null && !isset($fehler['eigentuemer_person_id'])) {
            $fehler['eigentuemer_person_id'] = 'Das Fahrzeug wurde ans Magazin übergeben (' . $imMagazin['code'] . ') und bleibt Eigentum des Vereins.';
        }
        if ($fehler !== []) {
            return $this->formular($this->anzeigeWerte($daten, $r), $fehler, $fz, 422, null, $stand);
        }
        try {
            $this->fahrzeuge->aktualisieren((int) $fz['id'], $daten, $stand);
        } catch (KonfliktFehler $e) {
            if ($this->fahrzeuge->finde((int) $fz['id']) === null) {
                throw new HttpFehler(404); // zwischenzeitlich gelöscht
            }
            return $this->formular($this->anzeigeWerte($daten, $r), [], $fz, 409, $e->getMessage(), $stand);
        }
        $diff = Aenderungsprotokoll::diff($this->vergleichswerte($fz), $daten);
        if ($diff !== []) {
            $this->protokoll->schreibe($this->auth->benutzer()?->id, 'fahrzeug', (int) $fz['id'], 'geaendert', $diff);
        }
        $this->session->meldung('erfolg', 'Das Fahrzeug wurde gespeichert.');
        return Response::redirect(url('/fahrzeuge/' . $fz['id']));
    }

    public function loeschen(Request $r, array $p): Response
    {
        $fz = $this->lade($p);
        $id = (int) $fz['id'];
        // Unter der Sperre des Fahrzeugs: eine gleichzeitige Defektmeldung wird mitgelöscht oder bekommt 404.
        $dienst = $this->reparatur ?? throw new \LogicException('FahrzeugController braucht den ReparaturDienst zum Löschen.');
        try {
            $geloescht = $dienst->loescheObjekt('fahrzeug', $id, function () use ($id): array {
                $pfade = $this->wartung?->entferneFuerObjektInTransaktion('fahrzeug', $id) ?? [];
                array_push($pfade, ...$this->dokumente->loeseVonObjekt('fahrzeug', $id));
                $this->fahrzeuge->loeschen($id); // Decoder, Funktionen, CVs per CASCADE
                return $pfade;
            });
        } catch (\Throwable $t) {
            if (!Transaktion::istSperrkonflikt($t)) {
                throw $t;
            }
            // Verklemmung (z. B. zwei Löschungen mit geteiltem Dokument) oder Wartezeit überschritten: zurückgerollt,
            // nichts gelöscht.
            $this->session->meldung('fehler', 'Das Fahrzeug konnte gerade nicht gelöscht werden. Bitte erneut versuchen.');
            return Response::redirect(url('/fahrzeuge/' . $id));
        }
        if (!$geloescht) {
            throw new HttpFehler(404); // zwischenzeitlich gelöscht
        }
        // Lösch-Aufträge für die OneDrive-Dateien gleich nebenbei ausführen (ohne Cronjob sonst erst später).
        $this->uebertragung?->nebenbei();
        $alt = $this->vergleichswerte($fz);
        $this->protokoll->schreibe(
            $this->auth->benutzer()?->id,
            'fahrzeug',
            (int) $fz['id'],
            'geloescht',
            Aenderungsprotokoll::diff($alt, array_fill_keys(array_keys($alt), null))
        );
        $this->session->meldung('erfolg', 'Das Fahrzeug wurde gelöscht.');
        return Response::redirect(url('/fahrzeuge'));
    }

    /**
     * Decoder des Fahrzeugs samt Adressart und Adresskonflikten für den Tab „Digital“.
     *
     * @param array<string,mixed> $fz
     * @return list<array<string,mixed>>
     */
    private function decoderMitPruefung(array $fz): array
    {
        $liste = [];
        foreach ($this->decoder->fuerFahrzeug((int) $fz['id']) as $d) {
            $p = Protokoll::from((string) $d['protokoll']);
            $adresse = $d['adresse'] === null ? null : (int) $d['adresse'];
            $d['protokoll_label'] = $p->label();
            $d['adressart'] = $adresse === null ? null : AdressValidator::adressart($p, $adresse);
            $d['konflikte'] = $adresse === null || $fz['status'] === 'ausgemustert'
                ? []
                : $this->adresspruefung->konflikte((int) $fz['spurweite_id'], $p, $adresse, (int) $fz['id']);
            $liste[] = $d;
        }
        return $liste;
    }

    /** @return array<string,mixed> */
    private function lade(array $p): array
    {
        $id = preg_match('/^\d{1,10}$/', (string) ($p['id'] ?? '')) === 1 ? (int) $p['id'] : 0;
        $fz = $id > 0 && $id <= 4294967295 ? $this->fahrzeuge->finde($id) : null;
        if ($fz === null) {
            throw new HttpFehler(404);
        }
        return $fz;
    }

    /** Nur Fahrzeugwart und höher wählen den Eigentümer frei; für Mitglieder ist er serverseitig festgelegt. */
    private function darfEigentuemerWaehlen(): bool
    {
        return Berechtigung::darf($this->auth->benutzer(), Berechtigung::FAHRZEUG_BEARBEITEN);
    }

    /** @return array<string,mixed> */
    private function ladeBearbeitbar(array $p): array
    {
        $fz = $this->lade($p);
        Berechtigung::verlangeFahrzeugBearbeiten($this->auth->benutzer(), $fz);
        return $fz;
    }

    /**
     * @param ?array<string,mixed> $fz bestehendes Fahrzeug (Ändern) oder null (Anlegen)
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    private function lies(Request $r, ?array $fz): array
    {
        $roh = [];
        foreach (self::FORMULARFELDER as $feld) {
            $roh[$feld] = $r->text($feld);
        }
        if (!$this->darfEigentuemerWaehlen()) {
            // Mitglieder: Eigentümer nie aus der Eingabe, sondern beim Anlegen die eigene Person, beim Ändern der bisherige Stand.
            $roh['eigentuemer_person_id'] = $fz === null
                ? (string) $this->auth->benutzer()?->personId
                : (string) ($fz['eigentuemer_person_id'] ?? '');
        }
        $daten = FahrzeugValidator::normalisiere($roh);
        $fehler = FahrzeugValidator::pruefe($daten);
        if (!isset($fehler['spurweite_id']) && $this->spurweiten->finde((int) $daten['spurweite_id']) === null) {
            $fehler['spurweite_id'] = 'Bitte eine Spurweite wählen.';
        }
        if ($daten['eigentuemer_person_id'] !== null && $this->darfEigentuemerWaehlen() && $this->personen->finde((int) $daten['eigentuemer_person_id']) === null) {
            $fehler['eigentuemer_person_id'] = 'Bitte einen Eigentümer aus der Liste wählen.';
        }
        return [$daten, $fehler];
    }

    /**
     * Zurück ins Formular: Texteingaben bleiben so erhalten, wie sie getippt wurden.
     *
     * @param array<string,mixed> $daten
     * @return array<string,mixed>
     */
    private function anzeigeWerte(array $daten, Request $r): array
    {
        $w = $daten;
        $w['betriebsstunden'] = $r->text('betriebsstunden');
        return $w;
    }

    /**
     * @param array<string,mixed> $fz
     * @return array<string,mixed>
     */
    private function vergleichswerte(array $fz): array
    {
        $w = array_intersect_key($fz, array_flip(self::FORMULARFELDER));
        $w['betriebsstunden'] = (float) $w['betriebsstunden'];
        return $w;
    }

    /**
     * @param array<string,mixed> $werte
     * @param array<string,string> $fehler
     * @param ?array<string,mixed> $fz
     */
    private function formular(array $werte, array $fehler, ?array $fz, int $status = 200, ?string $konflikt = null, ?string $stand = null): Response
    {
        return $this->html('fahrzeuge/formular', [
            'titel' => $fz === null ? 'Fahrzeug anlegen' : 'Fahrzeug bearbeiten',
            'werte' => $werte,
            'fehler' => $fehler,
            'fahrzeug' => $fz,
            'konflikt' => $konflikt,
            'stand' => $stand ?? ($fz['geaendert_am'] ?? ''),
            'spurweiten' => $this->spurweiten->alle(),
            'personen' => $this->personen->alle(true),
            'eigentuemerFest' => $this->darfEigentuemerWaehlen() ? null : $this->eigentuemerText($werte, $fz),
        ], $status);
    }

    /**
     * Fester Eigentümer-Text im Formular für Mitglieder.
     *
     * @param array<string,mixed> $werte
     * @param ?array<string,mixed> $fz
     */
    private function eigentuemerText(array $werte, ?array $fz): string
    {
        $eigene = $this->auth->benutzer();
        $personId = $fz === null ? $eigene?->personId : ($fz['eigentuemer_person_id'] === null ? null : (int) $fz['eigentuemer_person_id']);
        if ($personId === null) {
            return 'Eigentum: Verein';
        }
        if ($eigene !== null && $personId === $eigene->personId) {
            return 'Eigentum: ' . $eigene->anzeigename . ' (selbst angelegte Fahrzeuge gehören dir)';
        }
        return 'Eigentum: ' . (string) ($fz['eigentuemer_name'] ?? '');
    }

    /** @param array<string,mixed> $daten */
    private function html(string $template, array $daten, int $status = 200): Response
    {
        return new Response($this->view->render($template, $daten), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
