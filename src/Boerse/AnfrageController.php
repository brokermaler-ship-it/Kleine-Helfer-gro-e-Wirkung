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
use Emc\Magazin\ArtikelValidator;
use Emc\Magazin\BuchungValidator;
use Emc\Magazin\MagazinRepository;
use Emc\Stammdaten\SpurweiteRepository;

/**
 * Eingang der Börsenanfragen aus dem öffentlichen Bereich (nur Börsenwart, sonst 403): Liste, Anfrage (Öffnen setzt
 * offen → in_bearbeitung), Status, Antwort per mailto:, Übernahme einer angebotenen Sammlung ins Magazin, Löschen.
 * Alle Angaben stammen von Besuchern ohne Anmeldung: Ausgabe nur mit e(), Freitext wird nie verlinkt.
 */
final class AnfrageController
{
    private const ARTIKEL_FELDER = ['bezeichnung', 'kategorie_id', 'hersteller', 'artikelnummer', 'spurweite_id', 'zustand', 'lagerort', 'notiz', 'autor_verlag', 'jahr', 'isbn'];
    private const EINGANG_FELDER = ['menge', 'herkunft', 'freitext', 'datum', 'betrag'];
    public const STATUS_TEXTE = ['offen' => 'offen', 'in_bearbeitung' => 'in Bearbeitung', 'erledigt' => 'erledigt'];

    public function __construct(
        private View $view,
        private AnfrageRepository $anfragen,
        private AnfrageDienst $dienst,
        private MagazinRepository $magazin,
        private SpurweiteRepository $spurweiten,
        private Auth $auth,
        private Session $session,
    ) {
    }

    /** GET /anfragen (?status=offen|in_bearbeitung|erledigt|alle; Standard offen und in Bearbeitung) */
    public function liste(Request $r): Response
    {
        $this->verlangeBoersenwart();
        try {
            $this->dienst->raeumeAuf(new \DateTimeImmutable());
        } catch (\Throwable $t) {
            error_log('Börsenanfragen: Aufräumen fehlgeschlagen: ' . $t::class);
        }
        $status = $r->text('status');
        if ($status !== 'alle' && !in_array($status, AnfrageRepository::STATUS, true)) {
            $status = '';
        }
        return $this->seite('boerse/anfragen', [
            'titel' => 'Börsenanfragen',
            'status' => $status,
            'anfragen' => $this->anfragen->suche($status === '' ? null : $status),
        ]);
    }

    /** GET /anfragen/{id}: Öffnen setzt offen → in_bearbeitung. */
    public function anzeigen(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $a = $this->lade($p);
        // Nur bei einer Navigation von der eigenen Seite (bzw. eingetippt/Lesezeichen) ändert der Aufruf den Status –
        // eine fremde Seite, die den Börsenwart hierher leitet, blendet so keine Anfragen aus dem Hinweis aus.
        $herkunft = strtolower(trim((string) $r->server('HTTP_SEC_FETCH_SITE', '')));
        if ($a['status'] === 'offen' && in_array($herkunft, ['', 'same-origin', 'none'], true)) {
            try {
                $this->dienst->oeffne((int) $a['id'], $this->auth->benutzer()?->id);
                $a = $this->lade($p);
            } catch (DomainFehler) {
                // Sperrkonflikt: die Anfrage bleibt offen, die Seite wird trotzdem gezeigt.
            }
        }
        return $this->detail($a);
    }

    /** POST /anfragen/{id}/status */
    public function status(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $a = $this->lade($p);
        $status = $r->text('status');
        if (!in_array($status, AnfrageRepository::STATUS, true)) {
            return $this->detail($a, 'Bitte einen gültigen Status wählen.', 422);
        }
        try {
            if ($this->dienst->setzeStatus((int) $a['id'], $status, $this->auth->benutzer()?->id)) {
                $this->session->meldung('erfolg', 'Der Status ist jetzt „' . self::STATUS_TEXTE[$status] . '“.');
            }
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/anfragen/' . (int) $a['id']));
    }

    /** POST /anfragen/{id}/loeschen: Anfrage samt nur ihr zugeordneten Fotos (übernommene bleiben am Artikel). */
    public function loeschen(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $a = $this->lade($p);
        try {
            $this->dienst->loesche((int) $a['id'], $this->auth->benutzer()?->id);
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
            return Response::redirect(url('/anfragen/' . (int) $a['id']));
        }
        $this->session->meldung('erfolg', 'Die Anfrage wurde gelöscht.');
        return Response::redirect(url('/anfragen'));
    }

    /** GET /anfragen/{id}/uebernehmen: Magazin-Formular, vorbelegt aus der Anfrage. */
    public function uebernehmenFormular(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $a = $this->ladeSammlung($p);
        if ($a['magazin_artikel_id'] !== null) {
            return $this->schonUebernommen($a);
        }
        return $this->formular($a, self::vorbelegung($a, $this->kategorieSonstiges()), []);
    }

    /** POST /anfragen/{id}/uebernehmen */
    public function uebernehmen(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $a = $this->ladeSammlung($p);
        if ($a['magazin_artikel_id'] !== null) {
            return $this->schonUebernommen($a);
        }
        $werte = [];
        foreach ([...self::ARTIKEL_FELDER, ...self::EINGANG_FELDER] as $f) {
            $werte[$f] = $r->text($f);
        }
        [$artikel, $fehler] = $this->pruefeArtikel($werte);
        [$eingang, $fehlerEingang] = BuchungValidator::pruefe($werte, 'eingang');
        $fehler += $fehlerEingang;
        if ($fehler !== []) {
            return $this->formular($a, $werte, $fehler, 422);
        }
        try {
            $id = $this->dienst->uebernimmInsMagazin((int) $a['id'], $artikel, $eingang, $this->auth->benutzer()?->id);
        } catch (DomainFehler $e) {
            if ($e->getMessage() === AnfrageDienst::SCHON_UEBERNOMMEN) {
                return $this->schonUebernommen($a);
            }
            return $this->formular($a, $werte, [], 422, $e->getMessage());
        }
        $this->session->meldung('erfolg', 'Die Sammlung wurde als M' . $id . ' ins Magazin übernommen.');
        return Response::redirect(url('/magazin/' . $id));
    }

    /**
     * Vorbelegung: Bezeichnung leer (der Text des Besuchers kann Namen und Telefonnummern enthalten, die sonst in
     * Artikel und Protokoll dauerhaft stünden – er steht nur als Text neben dem Formular), Kategorie „Sonstiges“,
     * Herkunft spende bei Wunsch „spenden“, sonst kauf, Freitext „Anfrage über die Webseite (Nr. <id>)“ – ohne den
     * Namen, denn die Buchung ist unveränderlich und überdauerte sonst die Löschung der Anfrage nach 12 Monaten –,
     * Menge 1, heute.
     *
     * @param array<string,mixed> $a
     * @return array<string,string>
     */
    public static function vorbelegung(array $a, ?int $kategorieSonstiges): array
    {
        return [
            'bezeichnung' => '',
            'kategorie_id' => $kategorieSonstiges === null ? '' : (string) $kategorieSonstiges,
            'zustand' => 'gebraucht',
            'menge' => '1',
            'herkunft' => $a['wunsch'] === 'spenden' ? 'spende' : 'kauf',
            'freitext' => 'Anfrage über die Webseite (Nr. ' . (int) $a['id'] . ')',
            'datum' => date('Y-m-d'),
        ];
    }

    // ---- Hilfen -----------------------------------------------------------------------------------------------------

    /** @param array<string,mixed> $a */
    private function detail(array $a, ?string $meldung = null, int $status = 200): Response
    {
        $eingang = new \DateTimeImmutable((string) $a['eingegangen_am']);
        $email = $a['email'] === null ? null : (string) $a['email'];
        return $this->seite('boerse/anfrage', [
            'titel' => $a['art'] === 'sammlung' ? 'Sammlung angeboten' : 'Kaufanfrage',
            'anfrage' => $a,
            'mailto' => Antwortlink::mailto($email, Antwortlink::betreff($a), Antwortlink::anrede((string) $a['name'])),
            'tel' => Antwortlink::tel($a['telefon'] === null ? null : (string) $a['telefon']),
            'geloeschtAm' => $eingang->modify('+' . AnfrageDienst::AUFBEWAHRUNG_MONATE . ' months'),
            'meldung' => $meldung,
        ], $status);
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,string> $werte
     * @param array<string,string> $fehler
     */
    private function formular(array $a, array $werte, array $fehler, int $status = 200, ?string $meldung = null): Response
    {
        $id = (int) $a['id'];
        return $this->seite('magazin/formular', [
            'titel' => 'Sammlung ins Magazin übernehmen',
            'artikel' => null,
            'werte' => $werte,
            'fehler' => $fehler,
            'meldung' => $meldung,
            'stand' => '',
            'kategorien' => $this->magazin->kategorien(),
            'spurweiten' => $this->spurweiten->alle(),
            'formularAktion' => url('/anfragen/' . $id . '/uebernehmen'),
            'formularAbbrechen' => url('/anfragen/' . $id),
            'formularHinweis' => self::fotoHinweis(count($a['fotos'])),
            'formularBezeichnungHinweis' => 'Bitte eine eigene Bezeichnung eingeben – ohne Namen und Kontaktdaten aus der Anfrage.',
            'formularVorlage' => (string) $a['nachricht'],
        ], $status);
    }

    private static function fotoHinweis(int $n): string
    {
        return match ($n) {
            0 => 'Die Anfrage hat keine Fotos. Fotos fügst du nach dem Speichern auf der Artikelseite hinzu.',
            1 => 'Das Foto der Anfrage wird übernommen und Titelbild des Artikels.',
            default => 'Die ' . $n . ' Fotos der Anfrage werden übernommen; das erste wird Titelbild des Artikels.',
        };
    }

    /** @param array<string,mixed> $a */
    private function schonUebernommen(array $a): Response
    {
        $this->session->meldung('fehler', AnfrageDienst::SCHON_UEBERNOMMEN);
        return Response::redirect(url('/anfragen/' . (int) $a['id']));
    }

    /**
     * @param array<string,string> $werte
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    private function pruefeArtikel(array $werte): array
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
            true
        );
    }

    private function kategorieSonstiges(): ?int
    {
        $k = $this->magazin->kategorieNachSchluessel('sonstiges');
        return $k === null ? null : (int) $k['id'];
    }

    /** @param array<string,mixed> $daten */
    private function seite(string $template, array $daten, int $status = 200): Response
    {
        return new Response($this->view->render($template, $daten), $status, ['Content-Type' => 'text/html; charset=utf-8']);
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
        return ($id > 0 ? $this->anfragen->finde($id) : null) ?? throw new HttpFehler(404);
    }

    /** Nur eine angebotene Sammlung lässt sich übernehmen; eine Kaufanfrage antwortet wie unbekannt (404). */
    private function ladeSammlung(array $p): array
    {
        $a = $this->lade($p);
        if ($a['art'] !== 'sammlung') {
            throw new HttpFehler(404);
        }
        return $a;
    }
}
