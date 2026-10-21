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
use Emc\Reparatur\ReparaturValidator;

/**
 * Kasse am Handy (nur Börsenwart, sonst 403): Börse wählen, Warenkorb in der Sitzung (Warenkorb), Verkauf abschließen,
 * Verkaufsliste mit Storno am selben Tag. Jeder POST leitet um (303, Post/Redirect/Get): Fehler legen Meldungen, rot zu
 * markierende Karten und Eingaben einmalig in die Sitzung, der Warenkorb bleibt; Erfolg leert ihn. Käuferdaten gibt es nicht.
 */
final class KasseController
{
    private const TREFFER_MAX = 30;
    public const NULLPREIS = 'Verkaufspreis 0,00 € – bitte bestätigen oder den Preis ändern.';

    /** Sitzungsschlüssel für Markierungen nach einem Fehler (einmal angezeigt, Muster Post/Redirect/Get). */
    private static function merkSchluessel(int $boerseId): string
    {
        return 'kasse_merk_' . $boerseId;
    }

    public function __construct(
        private View $view,
        private BoerseRepository $boersen,
        private AngebotRepository $angebote,
        private KasseDienst $kasse,
        private VerkaufRepository $verkaeufe,
        private Auth $auth,
        private Session $session,
    ) {
    }

    /** GET /kasse: genau eine laufende Börse → deren Kasse, sonst Auswahl bzw. „Gerade läuft keine Börse.“ */
    public function waehlen(Request $r): Response
    {
        $this->verlangeBoersenwart();
        $laufende = $this->boersen->laufende();
        if (count($laufende) === 1) {
            return Response::redirect(url('/boersen/' . (int) $laufende[0]['id'] . '/kasse'));
        }
        return $this->seite('boerse/kasse_waehlen', ['titel' => 'Kasse', 'boersen' => $laufende]);
    }

    /** GET /boersen/{id}/kasse (?q= Textsuche in offenen Angeboten dieser Börse und dauerhaften) */
    public function anzeigen(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        $merk = $this->session->hole(self::merkSchluessel((int) $b['id']));
        $this->session->entferne(self::merkSchluessel((int) $b['id']));
        $merk = is_array($merk) ? $merk : [];
        return $this->kassenSeite(
            $b,
            $r->text('q'),
            array_values(array_filter(array_map('intval', (array) ($merk['ids'] ?? [])), fn(int $i): bool => $i > 0)),
            is_string($merk['nummer'] ?? null) ? $merk['nummer'] : '',
            array_filter((array) ($merk['preise'] ?? []), 'is_string'),
            is_string($merk['zahlart'] ?? null) ? $merk['zahlart'] : '',
        );
    }

    /** POST /boersen/{id}/kasse/hinzu: nummer (z. B. „118“) oder angebot_id (aus der Suche) */
    public function hinzu(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        $korb = new Warenkorb($this->session, (int) $b['id']);
        $eingabe = $r->text('nummer');
        $angebotId = $r->ganzzahl('angebot_id', 1, 4294967295);
        if ($eingabe === '' && $angebotId !== null) {
            $a = $this->angebote->finde($angebotId);
            $eingabe = $a === null ? (string) $angebotId : (string) $a['nummer'];
        }
        try {
            $a = $this->kasse->pruefeHinzu((int) $b['id'], $eingabe);
        } catch (KasseFehler $e) {
            return $this->fehlerSeite($b, $e->meldungen, $e->angebotIds, $eingabe);
        }
        if ($korb->enthaelt((int) $a['id'])) {
            return $this->fehlerSeite($b, [$a['nummer'] . ' ist schon im Warenkorb.'], [], '');
        }
        if (!$korb->hinzu((int) $a['id'], (string) $a['wunschpreis'])) {
            return $this->fehlerSeite($b, ['Der Warenkorb ist voll (höchstens ' . Warenkorb::MAX_POSITIONEN . ' Stück). Bitte erst abschließen.'], [], '');
        }
        return Response::redirect(url('/boersen/' . (int) $b['id'] . '/kasse'));
    }

    /** POST /boersen/{id}/kasse/preis: preis[<angebot_id>] (alle Karten) bzw. angebot_id + preis */
    public function preis(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        [$fehlerIds, $roh] = $this->uebernimmPreise($r, new Warenkorb($this->session, (int) $b['id']));
        if ($fehlerIds !== []) {
            return $this->fehlerSeite($b, [AngebotValidator::KEIN_PREIS], $fehlerIds, '', $roh);
        }
        return Response::redirect(url('/boersen/' . (int) $b['id'] . '/kasse'));
    }

    /** POST /boersen/{id}/kasse/entfernen: angebot_id */
    public function entfernen(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        $korb = new Warenkorb($this->session, (int) $b['id']);
        // Vorher eingetippte Preise der anderen Karten nicht verlieren (der Knopf sitzt im Formular des Warenkorbs).
        $this->uebernimmPreise($r, $korb);
        $id = $r->ganzzahl('angebot_id', 1, 4294967295);
        if ($id !== null) {
            $korb->entferne($id);
        }
        return Response::redirect(url('/boersen/' . (int) $b['id'] . '/kasse'));
    }

    /** POST /boersen/{id}/kasse/abschliessen: zahlart, preis[<angebot_id>] */
    public function abschliessen(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        $id = (int) $b['id'];
        $korb = new Warenkorb($this->session, $id);
        [$fehlerIds, $roh] = $this->uebernimmPreise($r, $korb);
        if ($fehlerIds !== []) {
            return $this->fehlerSeite($b, [AngebotValidator::KEIN_PREIS], $fehlerIds, '', $roh);
        }
        $zahlart = $r->text('zahlart');
        // 0,00 € ist meist ein Vertipper („0“ statt „10“): nur mit ausdrücklicher Bestätigung.
        $nullpreis = array_values(array_map(fn(array $p): int => $p['angebot_id'], array_filter($korb->positionen(), fn(array $p): bool => $p['preis'] === '0.00')));
        if ($nullpreis !== [] && $r->text('nullpreis_ok') !== '1') {
            $meldungen = [];
            foreach ($nullpreis as $aid) {
                $meldungen[] = ($this->angebote->finde($aid)['nummer'] ?? (string) $aid) . ': ' . self::NULLPREIS;
            }
            return $this->fehlerSeite($b, $meldungen, $nullpreis, '', [], $zahlart);
        }
        $summe = $korb->summeCent();
        try {
            $this->kasse->abschliessen($id, $korb->positionen(), $zahlart, $this->auth->benutzer()?->id, new \DateTimeImmutable());
        } catch (KasseFehler $e) {
            return $this->fehlerSeite($b, $e->meldungen, $e->angebotIds, '', [], $zahlart);
        } catch (DomainFehler $e) {
            return $this->fehlerSeite($b, [$e->getMessage()], [], '', [], $zahlart);
        }
        $korb->leere();
        $this->session->meldung(
            'erfolg',
            'Verkauf über ' . ReparaturValidator::betrag(Geld::dezimal($summe)) . ' abgeschlossen (' . KasseDienst::ZAHLARTEN[$zahlart] . ').',
            url('/boersen/' . $id . '/verkaeufe'),
            'Verkäufe ansehen'
        );
        return Response::redirect(url('/boersen/' . $id . '/kasse'));
    }

    /** GET /boersen/{id}/verkaeufe */
    public function verkaeufe(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $b = $this->lade($p);
        $jetzt = new \DateTimeImmutable();
        $liste = $this->verkaeufe->fuerBoerse((int) $b['id']);
        foreach ($liste as $i => $v) {
            $liste[$i]['stornierbar'] = KasseDienst::stornierbar($v, $b, $jetzt);
        }
        return $this->seite('boerse/verkaeufe', ['titel' => 'Verkäufe ' . $b['name'], 'boerse' => $b, 'verkaeufe' => $liste, 'heute' => $jetzt->format('Y-m-d')]);
    }

    /** POST /verkaeufe/{id}/storno: grund */
    public function storno(Request $r, array $p): Response
    {
        $this->verlangeBoersenwart();
        $roh = (string) ($p['id'] ?? '');
        $id = preg_match('/^\d{1,10}$/', $roh) === 1 && (int) $roh <= 4294967295 ? (int) $roh : 0;
        $v = $id > 0 ? $this->verkaeufe->finde($id) : null;
        if ($v === null) {
            throw new HttpFehler(404);
        }
        try {
            $this->kasse->storniere($id, $r->text('grund'), $this->auth->benutzer()?->id, new \DateTimeImmutable());
            $this->session->meldung('erfolg', 'Der Verkauf wurde storniert. Die Stücke sind wieder im Angebot.');
        } catch (DomainFehler $e) {
            $this->session->meldung('fehler', $e->getMessage());
        }
        return Response::redirect(url('/boersen/' . (int) $v['boerse_id'] . '/verkaeufe'));
    }

    // ---- Hilfen -----------------------------------------------------------------------------------------------------

    /**
     * Übernimmt die Verkaufspreise aus preis[<angebot_id>] (bzw. angebot_id + preis) in den Warenkorb.
     *
     * @return array{0: list<int>, 1: array<int,string>} ungültige Angebote und deren Eingaben
     */
    private function uebernimmPreise(Request $r, Warenkorb $korb): array
    {
        $eingaben = $r->post('preis');
        if (!is_array($eingaben)) {
            $id = $r->ganzzahl('angebot_id', 1, 4294967295);
            $eingaben = $id !== null && is_string($eingaben) ? [$id => $eingaben] : [];
        }
        $fehler = [];
        $roh = [];
        foreach ($eingaben as $id => $eingabe) {
            $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || !is_string($eingabe) || !$korb->enthaelt($id)) {
                continue;
            }
            $wert = ReparaturValidator::betragWert($eingabe);
            if ($wert === null) {
                $fehler[] = $id;
                $roh[$id] = mb_substr($eingabe, 0, 20);
                continue;
            }
            $korb->setzePreis($id, $wert);
        }
        return [$fehler, $roh];
    }

    /**
     * @param list<string> $meldungen
     * @param list<int> $fehlerIds
     * @param array<int,string> $preisEingaben
     */
    private function fehlerSeite(array $b, array $meldungen, array $fehlerIds, string $nummer, array $preisEingaben = [], string $zahlart = ''): Response
    {
        // Post/Redirect/Get: Meldungen, rote Karten und Eingaben über die Sitzung auf die Kassenseite – so fragt
        // „Neu laden“ am Handy nicht, ob das Formular (der Abschluss) noch einmal gesendet werden soll.
        foreach ($meldungen as $m) {
            $this->session->meldung('fehler', $m);
        }
        $this->session->setze(self::merkSchluessel((int) $b['id']), [
            'ids' => array_values($fehlerIds),
            'nummer' => mb_substr($nummer, 0, 30),
            'preise' => $preisEingaben,
            'zahlart' => $zahlart,
        ]);
        return Response::redirect(url('/boersen/' . (int) $b['id'] . '/kasse'));
    }

    /**
     * @param list<int> $fehlerIds
     * @param array<int,string> $preisEingaben
     */
    private function kassenSeite(array $b, string $q, array $fehlerIds = [], string $nummer = '', array $preisEingaben = [], string $zahlart = ''): Response
    {
        $id = (int) $b['id'];
        $korb = new Warenkorb($this->session, $id);
        $karten = [];
        foreach ($korb->positionen() as $pos) {
            $a = $this->angebote->finde($pos['angebot_id']);
            $karten[] = [
                'angebot_id' => $pos['angebot_id'],
                'angebot' => $a,
                'preis' => $preisEingaben[$pos['angebot_id']] ?? ReparaturValidator::betragEingabe($pos['preis']),
                'fehler' => in_array($pos['angebot_id'], $fehlerIds, true) || $a === null || !in_array($a['status'], AngebotRepository::OFFEN, true),
            ];
        }
        $treffer = [];
        $q = trim($q);
        if ($q !== '') {
            $treffer = [
                ...$this->angebote->suche(new AngebotFilter(boerseId: $id, text: $q)),
                ...$this->angebote->suche(new AngebotFilter(dauerhaft: true, text: $q)),
            ];
            $treffer = array_slice($treffer, 0, self::TREFFER_MAX);
        }
        return $this->seite('boerse/kasse', [
            'titel' => 'Kasse ' . $b['name'],
            'boerse' => $b,
            'karten' => $karten,
            'summe' => Geld::dezimal($korb->summeCent()),
            'q' => $q,
            'treffer' => $treffer,
            'trefferMax' => self::TREFFER_MAX,
            'imKorb' => array_map(fn(array $k): int => $k['angebot_id'], $karten),
            'nummer' => $nummer,
            'zahlart' => isset(KasseDienst::ZAHLARTEN[$zahlart]) ? $zahlart : '',
            'nullpreis' => in_array('0.00', array_column($korb->positionen(), 'preis'), true),
        ]);
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
        return ($id > 0 ? $this->boersen->finde($id) : null) ?? throw new HttpFehler(404);
    }
}
