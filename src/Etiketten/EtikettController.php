<?php

declare(strict_types=1);

namespace Emc\Etiketten;

use Emc\Anlagen\AnlagenCode;
use Emc\Codes\Code;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Stammdaten\SpurweiteRepository;

/**
 * Etiketten zum Drucken aus dem Browser: Auswahl (GET /etiketten), Druckseite mit festen Bogenmaßen
 * (GET /etiketten/druck, eigenes Layout ohne Menü) und „Als gedruckt vermerken“ (POST /etiketten/gedruckt).
 * Die Routen verlangen Berechtigung::ETIKETTEN_DRUCKEN (ab Fahrzeugwart). Nur der POST setzt etikett_gedruckt_am.
 */
final class EtikettController
{
    public const ARTEN = ['klebchen' => 'Fahrzeug-Klebchen', 'verpackung' => 'Verpackung', 'anlage' => 'Anlagen-Etikett'];
    public const URL_WARNUNG = 'Bitte oeffentliche_url in der config.php eintragen, damit die QR-Codes auch später stimmen.';

    /** @param array<string,mixed> $config für oeffentliche_url und basis_url */
    public function __construct(
        private View $view,
        private EtikettAuswahl $auswahl,
        private SpurweiteRepository $spurweiten,
        private Session $session,
        private array $config,
    ) {
    }

    public function formular(Request $r): Response
    {
        return $this->vorUpdate(function () use ($r): Response {
            $art = self::art($r);
            $ids = self::ids($r);
            $auswahl = $r->text('auswahl');
            $spurweiten = $this->spurweiten->alle();
            $html = $this->view->render('etiketten/auswahl', [
                'titel' => 'Etiketten',
                'art' => $art,
                'auswahlFahrzeug' => $art !== 'anlage' && in_array($auswahl, EtikettAuswahl::FAHRZEUG_AUSWAHL, true) ? $auswahl : 'ungedruckt',
                'auswahlAnlage' => $art === 'anlage' && in_array($auswahl, EtikettAuswahl::ANLAGE_AUSWAHL, true) ? $auswahl : 'ungedruckt',
                'einzelFahrzeuge' => $art !== 'anlage' ? $this->auswahl->fahrzeuge('einzeln', $ids, null) : [],
                'einzelAnlagen' => $art === 'anlage' ? $this->auswahl->anlagen('einzeln', $ids) : [],
                'spurweiten' => $spurweiten,
                'spurweiteId' => $r->ganzzahl('spurweite_id', 1, 4294967295),
                'anzahlJeSpurweite' => $this->auswahl->anzahlJeSpurweite(),
                'anzahlLoks' => count($this->auswahl->fahrzeuge('loks', [], null)),
                'anzahlFahrzeugeUngedruckt' => count($this->auswahl->fahrzeuge('ungedruckt', [], null)),
                'anzahlAnlagen' => count($this->auswahl->anlagen('alle', [])),
                'anzahlAnlagenUngedruckt' => count($this->auswahl->anlagen('ungedruckt', [])),
                'urlFehlt' => $this->oeffentlicheUrl() === '',
                'klebchen' => Bogen::klebchen(),
                'anlageBogen' => Bogen::anlage(),
            ]);
            return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        });
    }

    public function druck(Request $r): Response
    {
        return $this->vorUpdate(function () use ($r): Response {
            $art = self::art($r);
            $auswahl = $r->text('auswahl');
            $ids = self::ids($r);
            $bogen = $art === 'anlage' ? Bogen::anlage() : Bogen::klebchen();
            $start = $r->ganzzahl('start', 1, $bogen->proBogen()) ?? 1;
            $etiketten = [];
            $ohneCode = [];
            $urlFehlt = false;
            if ($art === 'anlage') {
                [$basis, $urlFehlt] = $this->basis($r);
                foreach ($this->auswahl->anlagen($auswahl, $ids) as $a) {
                    $code = AnlagenCode::formatiere((int) $a['nummer']);
                    $link = $basis . '/c/' . $code;
                    $etiketten[] = ['id' => (int) $a['id'], 'code' => $code, 'name' => (string) $a['name'], 'link' => $link, 'svg' => QrCode::svg($link)];
                }
            } else {
                foreach ($this->auswahl->fahrzeuge($auswahl, $ids, $r->ganzzahl('spurweite_id', 1, 4294967295)) as $f) {
                    $code = Code::fahrzeug((string) $f['inventarnummer']);
                    if ($code === null) {
                        $ohneCode[] = (string) $f['inventarnummer'];
                        continue;
                    }
                    $etiketten[] = ['id' => (int) $f['id'], 'code' => $code, 'bezeichnung' => (string) $f['bezeichnung']];
                }
            }
            $zurueck = ['art' => $art, 'auswahl' => $auswahl];
            if ($ids !== [] && $auswahl === 'einzeln') {
                $zurueck['ids'] = $ids;
            }
            $html = $this->view->render('etiketten/druck', [
                'titel' => 'Etiketten drucken',
                'art' => $art,
                'bogen' => $bogen,
                'boegen' => $bogen->verteile($etiketten, $start),
                'ids' => array_column($etiketten, 'id'),
                'typ' => $art === 'anlage' ? 'anlage' : 'fahrzeug',
                'ohneCode' => $ohneCode,
                'urlFehlt' => $urlFehlt,
                'start' => $start,
                'zurueck' => $zurueck,
            ], null);
            return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        });
    }

    public function gedruckt(Request $r): Response
    {
        return $this->vorUpdate(function () use ($r): Response {
            $typ = $r->text('typ');
            if (!in_array($typ, ['fahrzeug', 'anlage'], true)) {
                throw new HttpFehler(404);
            }
            $ids = $r->post('ids');
            $n = $this->auswahl->vermerkeGedruckt($typ, is_array($ids) ? EtikettAuswahl::bereinige($ids) : []);
            $this->session->meldung('erfolg', $n === 1 ? '1 Etikett als gedruckt vermerkt.' : $n . ' Etiketten als gedruckt vermerkt.');
            return Response::redirect(url('/etiketten'));
        });
    }

    private static function art(Request $r): string
    {
        $art = $r->text('art');
        return isset(self::ARTEN[$art]) ? $art : 'klebchen';
    }

    /** @return list<int> */
    private static function ids(Request $r): array
    {
        $ids = $r->query('ids');
        return is_array($ids) ? EtikettAuswahl::bereinige($ids) : [];
    }

    private function oeffentlicheUrl(): string
    {
        return rtrim(trim((string) ($this->config['oeffentliche_url'] ?? '')), '/');
    }

    /**
     * Basis der Kurzlinks im QR-Code: oeffentliche_url + basis_url (wie bei „Kennwort vergessen“). Fehlt
     * oeffentliche_url, Schema und Host dieser Anfrage (nur ein gültiger Hostname, sonst localhost) – dann warnt die
     * Seite, denn ein später geänderter Zugang machte die gedruckten Codes ungültig.
     *
     * @return array{0:string, 1:bool} Basis ohne Schrägstrich am Ende, true = oeffentliche_url fehlt
     */
    private function basis(Request $r): array
    {
        $pfad = rtrim((string) ($this->config['basis_url'] ?? ''), '/');
        $oeffentlich = $this->oeffentlicheUrl();
        if ($oeffentlich !== '') {
            return [$oeffentlich . $pfad, false];
        }
        $host = $r->server('HTTP_HOST');
        if (!is_string($host) || preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*(?::[0-9]{1,5})?$/', $host) !== 1) {
            $host = 'localhost';
        }
        $https = $r->server('HTTPS');
        $schema = (is_string($https) && $https !== '' && strtolower($https) !== 'off') || (string) $r->server('SERVER_PORT') === '443'
            ? 'https' : 'http';
        return [$schema . '://' . strtolower($host) . $pfad, true];
    }

    /**
     * Fehlen die Spalten aus Migration 015 noch (Programmdateien neu, Update nicht ausgeführt), führt die Seite zum
     * Update statt zu einem Fehler 500. Im Normalfall fängt das schon App (3c) ab.
     *
     * @param callable():Response $aktion
     */
    private function vorUpdate(callable $aktion): Response
    {
        try {
            return $aktion();
        } catch (\PDOException $e) {
            if (!in_array((string) $e->getCode(), ['42S02', '42S22'], true)) {
                throw $e;
            }
            $this->session->meldung('warnung', 'Bitte zuerst das Datenbank-Update ausführen.');
            return Response::redirect(url('/admin/update'));
        }
    }
}
