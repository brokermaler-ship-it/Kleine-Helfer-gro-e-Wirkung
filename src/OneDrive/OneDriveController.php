<?php

declare(strict_types=1);

namespace Emc\OneDrive;

use Emc\Auth\Auth;
use Emc\Betrieb\Einstellungen;
use Emc\Core\Container;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Verwaltungsseite „OneDrive“: Verbinden (OAuth), Zielordner, Übertragung, Umzug, Trennen.
 * Alle Routen verlangen BETRIEB (in routes.php). Fehler von Microsoft Graph werden nie als 500, sondern als deutsche Meldung gezeigt.
 */
final class OneDriveController
{
    private const STATE_KEY = 'onedrive_state';
    /** OneDrive-IDs: Buchstaben, Ziffern und „!“, „_“, „-“, „.“, „=“ (persönliche Konten, z. B. „ABC123!456“). */
    private const ID_MUSTER = '/^(?!\.+$)[A-Za-z0-9!_.=-]{1,200}$/';
    /** Kleine Stapelgröße in Web-Anfragen: ein Upload kann bis zu 120 s dauern (max_execution_time/Proxy). */
    private const STAPEL_WEB = 3;

    /** @param array<string,mixed> $config */
    public function __construct(
        private View $view,
        private Session $session,
        private Einstellungen $einstellungen,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Container $container,
        private array $config,
    ) {
    }

    public function zeigen(Request $r): Response
    {
        $daten = [
            'titel' => 'OneDrive',
            'rueckrufUrl' => $this->rueckrufUrl(),
            'konfiguriert' => $this->bereitgestellt(),
            'schluesselVorschlag' => base64_encode(random_bytes(32)),
            'urlFehlt' => Uebertragung::rueckrufUrl($this->config) === null,
            'clientIdFehlt' => trim((string) ($this->config['onedrive_client_id'] ?? '')) === '',
            'secretFehlt' => trim((string) ($this->config['onedrive_client_secret'] ?? '')) === '',
            'schluesselStatus' => $this->schluesselStatus(),
            'status' => TokenSpeicher::STATUS_GETRENNT,
            'konto' => '',
            'ziel' => null,
            'zaehler' => ['wartet' => 0, 'fehler' => 0, 'erledigt' => 0],
            'letzterFehler' => '',
            'letzteFehler' => [],
        ];
        if ($daten['konfiguriert']) {
            $daten['status'] = $this->tokens()->status();
            $daten['konto'] = (string) ($this->einstellungen->hole('onedrive_konto') ?? '');
            $daten['ziel'] = $this->uebertragung()?->ziel();
            $daten['letzterFehler'] = (string) ($this->einstellungen->hole('onedrive_letzter_fehler') ?? '');
            $auftraege = $this->container->get(Auftraege::class);
            $daten['zaehler'] = $auftraege->zaehle();
            $daten['letzteFehler'] = $this->letzteAuftragsfehler();
        }
        return $this->html('admin/onedrive', $daten);
    }

    public function verbinden(Request $r): Response
    {
        if (!$this->bereitgestellt()) {
            return $this->zurueck('fehler', 'OneDrive ist noch nicht eingerichtet. Bitte zuerst die Einträge in der config.php ergänzen.');
        }
        $state = bin2hex(random_bytes(32));
        $this->session->setze(self::STATE_KEY, $state);
        return Response::redirect($this->container->get(OAuth::class)->anmeldeUrl($state));
    }

    public function rueckruf(Request $r): Response
    {
        // Der state ist einmalig: sofort aus der Sitzung nehmen, egal wie die Prüfung ausgeht.
        $erwartet = $this->session->hole(self::STATE_KEY);
        $this->session->entferne(self::STATE_KEY);
        $state = $r->query('state');
        if (!is_string($erwartet) || $erwartet === '' || !is_string($state) || !hash_equals($erwartet, $state)) {
            return $this->zurueck('fehler', 'Die Anmeldung bei Microsoft war ungültig oder ist abgelaufen. Bitte erneut verbinden.');
        }
        if (!$this->bereitgestellt()) {
            return $this->zurueck('fehler', 'OneDrive ist noch nicht eingerichtet.');
        }
        $fehler = $r->query('error');
        if ($fehler !== null) {
            $text = $fehler === 'access_denied'
                ? 'Die Anmeldung bei Microsoft wurde abgebrochen oder nicht erlaubt.'
                : 'Microsoft hat die Anmeldung abgelehnt' . (is_string($fehler) && preg_match('/^[a-z_]{1,60}$/', $fehler) === 1 ? ' (' . $fehler . ')' : '') . '.';
            return $this->zurueck('fehler', $text);
        }
        $code = $r->query('code');
        if (!is_string($code) || $code === '' || strlen($code) > 4096) {
            return $this->zurueck('fehler', 'Microsoft hat keinen Anmeldecode geliefert. Bitte erneut verbinden.');
        }

        try {
            $token = $this->container->get(OAuth::class)->tauscheCode($code);
        } catch (GraphFehler $e) {
            return $this->zurueck('fehler', $e->istAbgelaufen()
                ? 'Die Anmeldung bei Microsoft ist abgelaufen oder wurde schon verwendet – bitte erneut verbinden.'
                : 'Die Verbindung zu OneDrive ist fehlgeschlagen: ' . $e->getMessage());
        } catch (\Throwable $t) {
            error_log('OneDrive-Rückruf: ' . $t::class . ': ' . $t->getMessage());
            return $this->zurueck('fehler', 'Die Verbindung zu OneDrive ist fehlgeschlagen. Bitte später erneut versuchen.');
        }

        $vorher = $this->tokens()->status();
        $altesKonto = (string) ($this->einstellungen->hole('onedrive_konto') ?? '');
        $this->tokens()->speichere($token);
        $this->einstellungen->setze('onedrive_letzter_fehler', '');
        $konto = '';
        $kontoId = '';
        try {
            $ich = $this->container->get(Graph::class)->ich();
            $kontoId = isset($ich['id']) && is_string($ich['id']) ? mb_substr($ich['id'], 0, 200) : '';
            foreach (['mail', 'userPrincipalName', 'displayName'] as $feld) {
                if (isset($ich[$feld]) && is_string($ich[$feld]) && trim($ich[$feld]) !== '') {
                    $konto = mb_substr(trim($ich[$feld]), 0, 200);
                    break;
                }
            }
        } catch (\Throwable $t) {
            error_log('OneDrive-Konto nicht lesbar: ' . $t::class . ': ' . $t->getMessage());
        }
        $this->einstellungen->setze('onedrive_konto', $konto);
        $alteId = (string) ($this->einstellungen->hole('onedrive_konto_id') ?? '');
        $kontoGewechselt = $kontoId !== '' && $alteId !== '' && !hash_equals($alteId, $kontoId);
        if ($kontoId !== '') {
            $this->einstellungen->setze('onedrive_konto_id', $kontoId);
        }
        $this->protokoll->schreibe($this->auth->benutzer()?->id, 'onedrive', 1, 'geaendert', ['verbindung' => ['alt' => $vorher, 'neu' => 'verbunden'], 'konto' => ['alt' => $altesKonto !== '' ? $altesKonto : null, 'neu' => $konto]]);
        $this->session->meldung('erfolg', 'OneDrive ist verbunden' . ($konto !== '' ? ' (' . $konto . ')' : '') . '.');

        $u = $this->uebertragung();
        if ($kontoGewechselt) {
            // Anderes Microsoft-Konto: der alte Zielordner gehört dem alten Konto und darf nicht weiterverwendet werden.
            $alterZiel = $u?->ziel();
            $this->einstellungen->setze('onedrive_ziel', '');
            $this->protokoll->schreibe($this->auth->benutzer()?->id, 'onedrive', 1, 'geaendert', ['zielordner' => ['alt' => $alterZiel['name'] ?? null, 'neu' => null]]);
            $this->session->meldung('warnung', 'Es wurde ein anderes Microsoft-Konto verbunden. Der bisherige Zielordner wurde zurückgesetzt – bitte einen neuen Zielordner wählen.');
            return Response::redirect(url('/admin/onedrive/ordner'));
        }
        if ($u === null || !$u->bereit()) {
            return Response::redirect(url('/admin/onedrive/ordner'));
        }
        try {
            $u->verarbeite(self::STAPEL_WEB);
        } catch (\Throwable $t) {
            error_log('OneDrive-Nachübertragung: ' . $t::class . ': ' . $t->getMessage());
        }
        return Response::redirect(url('/admin/onedrive'));
    }

    public function ordnerZeigen(Request $r): Response
    {
        if (!$this->bereitgestellt() || $this->tokens()->status() !== TokenSpeicher::STATUS_VERBUNDEN) {
            return $this->zurueck('fehler', 'Bitte zuerst mit OneDrive verbinden.');
        }
        [$drive, $item] = $this->ordnerParameter($r, 'drive', 'item');
        if ($drive === false) {
            return $this->zurueck('fehler', 'Ungültiger Ordner.');
        }
        $ordner = [];
        $fehler = null;
        try {
            $ordner = $this->container->get(Graph::class)->ordnerListe($drive, $item);
        } catch (\Throwable $t) {
            $fehler = $this->graphMeldung($t, 'Die Ordnerliste konnte nicht geladen werden');
        }
        return $this->html('admin/onedrive_ordner', [
            'titel' => 'OneDrive-Ordner wählen',
            'ordner' => $ordner,
            'fehler' => $fehler,
            'eltern' => $drive === null ? null : ['drive_id' => $drive, 'item_id' => $item],
        ]);
    }

    /**
     * Speichert den gewählten Zielordner. Sichere Variante: Die gewählte ID wird nur akzeptiert, wenn sie in der
     * frisch von Graph geladenen Liste des Elternordners vorkommt; auch der Name stammt aus dieser Liste, nicht aus dem Formular.
     */
    public function ordnerSpeichern(Request $r): Response
    {
        $u = $this->uebertragung();
        if ($u === null || $this->tokens()->status() !== TokenSpeicher::STATUS_VERBUNDEN) {
            return $this->zurueck('fehler', 'Bitte zuerst mit OneDrive verbinden.', '/admin/onedrive');
        }
        $wahlDrive = $r->text('drive_id');
        $wahlItem = $r->text('item_id');
        [$elternDrive, $elternItem] = $this->ordnerParameter($r, 'eltern_drive', 'eltern_item');
        if ($elternDrive === false || preg_match(self::ID_MUSTER, $wahlDrive) !== 1 || preg_match(self::ID_MUSTER, $wahlItem) !== 1) {
            return $this->zurueck('fehler', 'Ungültige Ordnerauswahl.', '/admin/onedrive/ordner');
        }
        try {
            $liste = $this->container->get(Graph::class)->ordnerListe($elternDrive, $elternItem);
        } catch (\Throwable $t) {
            return $this->zurueck('fehler', $this->graphMeldung($t, 'Der Ordner konnte nicht geprüft werden') . ' Es wurde nichts gespeichert.', '/admin/onedrive/ordner');
        }
        $gewaehlt = null;
        foreach ($liste as $o) {
            if ($o['drive_id'] === $wahlDrive && $o['item_id'] === $wahlItem) {
                $gewaehlt = $o;
                break;
            }
        }
        if ($gewaehlt === null) {
            return $this->zurueck('fehler', 'Der gewählte Ordner wurde in OneDrive nicht gefunden. Bitte erneut auswählen.', '/admin/onedrive/ordner');
        }
        $alt = $u->ziel();
        $this->einstellungen->setze('onedrive_ziel', json_encode(
            ['drive_id' => $gewaehlt['drive_id'], 'item_id' => $gewaehlt['item_id'], 'name' => $gewaehlt['name']],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        $this->protokoll->schreibe($this->auth->benutzer()?->id, 'onedrive', 1, 'geaendert', [
            'zielordner' => ['alt' => $alt['name'] ?? null, 'neu' => $gewaehlt['name']],
        ]);
        $this->session->meldung('erfolg', 'Zielordner „' . $gewaehlt['name'] . '“ gespeichert.');
        try {
            $u->verarbeite(self::STAPEL_WEB);
        } catch (\Throwable $t) {
            error_log('OneDrive-Nachübertragung: ' . $t::class . ': ' . $t->getMessage());
        }
        return Response::redirect(url('/admin/onedrive'));
    }

    public function uebertragen(Request $r): Response
    {
        $u = $this->uebertragung();
        if ($u === null || !$u->aktiv()) {
            return $this->zurueck('fehler', 'OneDrive ist nicht verbunden oder es ist kein Zielordner gewählt.');
        }
        try {
            $e = $u->verarbeite(self::STAPEL_WEB);
        } catch (\Throwable $t) {
            return $this->zurueck('fehler', $this->graphMeldung($t, 'Die Übertragung ist fehlgeschlagen'));
        }
        $this->session->meldung($e['fehler'] > 0 ? 'warnung' : 'erfolg', sprintf(
            '%d %s übertragen%s.',
            $e['erledigt'],
            $e['erledigt'] === 1 ? 'Auftrag' : 'Aufträge',
            $e['fehler'] > 0 ? ', ' . $e['fehler'] . ' fehlgeschlagen (später erneut versucht)' : ''
        ));
        return Response::redirect(url('/admin/onedrive'));
    }

    public function umzug(Request $r): Response
    {
        $u = $this->uebertragung();
        if ($u === null || !$u->aktiv()) {
            return $this->zurueck('fehler', 'OneDrive ist nicht verbunden oder es ist kein Zielordner gewählt.');
        }
        try {
            $n = $u->umzug();
            $this->session->meldung('erfolg', $n === 1 ? '1 Datei zum Umzug vorgemerkt.' : $n . ' Dateien zum Umzug vorgemerkt.');
            $u->verarbeite(self::STAPEL_WEB);
        } catch (\Throwable $t) {
            $this->session->meldung('fehler', $this->graphMeldung($t, 'Die Übertragung ist fehlgeschlagen'));
        }
        return Response::redirect(url('/admin/onedrive'));
    }

    public function trennen(Request $r): Response
    {
        if (!$this->bereitgestellt()) {
            return $this->zurueck('fehler', 'OneDrive ist nicht eingerichtet.');
        }
        $konto = (string) ($this->einstellungen->hole('onedrive_konto') ?? '');
        $vorher = $this->tokens()->status();
        $this->tokens()->loesche();
        $this->einstellungen->setze('onedrive_konto', '');
        $this->protokoll->schreibe($this->auth->benutzer()?->id, 'onedrive', 1, 'geaendert', ['verbindung' => ['alt' => $vorher, 'neu' => 'getrennt'], 'konto' => ['alt' => $konto, 'neu' => null]]);
        $this->session->meldung('erfolg', 'OneDrive wurde getrennt. Bereits übertragene Dateien bleiben im OneDrive; lokal vorhandene Dateien bleiben erhalten.');
        return Response::redirect(url('/admin/onedrive'));
    }

    /** Konfiguration vollständig und Schlüssel brauchbar (dann liefert der Container eine Übertragung). */
    private function bereitgestellt(): bool
    {
        return Uebertragung::konfiguriert($this->config) && $this->uebertragung() !== null;
    }

    private function uebertragung(): ?Uebertragung
    {
        return Uebertragung::konfiguriert($this->config) ? $this->container->get(Uebertragung::class) : null;
    }

    private function tokens(): TokenSpeicher
    {
        return $this->container->get(TokenSpeicher::class);
    }

    /**
     * @return array{0:string|null|false,1:string|null}  [drive, item]; false bei ungültigen Werten, null/null ohne Angabe
     */
    private function ordnerParameter(Request $r, string $driveKey, string $itemKey): array
    {
        $d = $r->input($driveKey);
        $i = $r->input($itemKey);
        if ($d === null && $i === null || $d === '' && $i === '') {
            return [null, null];
        }
        if (!is_string($d) || !is_string($i) || preg_match(self::ID_MUSTER, $d) !== 1 || preg_match(self::ID_MUSTER, $i) !== 1) {
            return [false, null];
        }
        return [$d, $i];
    }

    private function graphMeldung(\Throwable $t, string $kontext): string
    {
        if ($t instanceof GraphFehler) {
            if ($t->istAbgelaufen()) {
                return 'Die OneDrive-Verbindung ist abgelaufen – bitte neu verbinden.';
            }
            return $kontext . ': ' . $t->getMessage();
        }
        error_log('OneDrive-Verwaltung: ' . $t::class . ': ' . $t->getMessage());
        return $kontext . ' (technischer Fehler). Bitte später erneut versuchen.';
    }

    /** @return 'fehlt'|'ungueltig'|'gesetzt' */
    private function schluesselStatus(): string
    {
        $k = trim((string) ($this->config['app_schluessel'] ?? ''));
        if ($k === '') {
            return 'fehlt';
        }
        $roh = base64_decode($k, true);
        return $roh !== false && strlen($roh) === 32 ? 'gesetzt' : 'ungueltig';
    }

    /** @return list<array{dokument_id:?int,art:string,versuche:int,letzter_fehler:string}> */
    private function letzteAuftragsfehler(): array
    {
        try {
            $st = $this->container->get('pdo')->query(
                "SELECT dokument_id, art, versuche, letzter_fehler FROM onedrive_auftrag WHERE letzter_fehler IS NOT NULL AND status IN ('wartet','fehler') ORDER BY geaendert_am DESC, id DESC LIMIT 5"
            );
            return array_map(fn(array $z): array => [
                'dokument_id' => $z['dokument_id'] === null ? null : (int) $z['dokument_id'],
                'art' => (string) $z['art'],
                'versuche' => (int) $z['versuche'],
                'letzter_fehler' => (string) $z['letzter_fehler'],
            ], $st->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Throwable) {
            return [];
        }
    }

    private function rueckrufUrl(): ?string
    {
        return Uebertragung::rueckrufUrl($this->config);
    }

    private function zurueck(string $typ, string $text, string $pfad = '/admin/onedrive'): Response
    {
        $this->session->meldung($typ, $text);
        return Response::redirect(url($pfad));
    }

    /** @param array<string,mixed> $daten */
    private function html(string $template, array $daten): Response
    {
        return new Response($this->view->render($template, $daten), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
