<?php

declare(strict_types=1);

namespace Emc\Dateien;

use Emc\Auth\Auth;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Protokoll\Aenderungsprotokoll;

final class DateiController
{
    private const MAX_DATEIEN = 20;

    public function __construct(
        private View $view,
        private DokumentRepository $dokumente,
        private UploadService $upload,
        private FahrzeugRepository $fahrzeuge,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
    ) {
    }

    public function hochladen(Request $r, array $p): Response
    {
        $fz = $this->ladeFahrzeug($p);
        $fzId = (int) $fz['id'];
        $dateien = self::normalisiere($r->file('datei'));
        if ($dateien === []) {
            $this->session->meldung('fehler', 'Es wurde keine Datei ausgewählt.');
            return $this->zurueck($fzId);
        }
        $titel = count($dateien) === 1 ? mb_substr(trim($r->text('titel')), 0, 200) : '';
        $kategorie = $r->text('kategorie', 'sonstiges');
        $benutzerId = $this->auth->benutzer()?->id;
        $erfolge = 0;
        foreach (array_slice($dateien, 0, self::MAX_DATEIEN) as $datei) {
            $name = is_string($datei['name'] ?? null) ? basename(str_replace('\\', '/', $datei['name'])) : '';
            try {
                $id = $this->upload->speichere($datei, $titel, $kategorie, $benutzerId);
            } catch (UploadFehler $f) {
                $this->session->meldung('fehler', ($name !== '' ? $name . ': ' : '') . $f->getMessage());
                continue;
            }
            $this->dokumente->zuordnen($id, 'fahrzeug', $fzId);
            $dok = $this->dokumente->finde($id);
            $erfolge++;
            if ($dok !== null && $dok['kategorie'] === 'foto' && (int) $dok['hat_anzeige'] === 1) {
                $aktuell = $this->fahrzeuge->finde($fzId);
                if ($aktuell !== null && $aktuell['titelbild_dokument_id'] === null) {
                    $this->dokumente->setzeTitelbild($fzId, $id);
                }
            }
            $this->protokoll->schreibe($benutzerId, 'fahrzeug', $fzId, 'geaendert', ['datei' => ['alt' => null, 'neu' => $dok['titel'] ?? $name]]);
        }
        if (count($dateien) > self::MAX_DATEIEN) {
            $this->session->meldung('warnung', 'Es werden höchstens ' . self::MAX_DATEIEN . ' Dateien auf einmal verarbeitet.');
        }
        if ($erfolge > 0) {
            $this->session->meldung('erfolg', $erfolge === 1 ? 'Die Datei wurde hochgeladen.' : $erfolge . ' Dateien wurden hochgeladen.');
        }
        return $this->zurueck($fzId);
    }

    public function titelbild(Request $r, array $p): Response
    {
        $fz = $this->ladeFahrzeug($p);
        $fzId = (int) $fz['id'];
        $dokId = $r->ganzzahl('dokument_id', 1, 2147483647);
        $dok = $dokId === null ? null : $this->dokumente->finde($dokId);
        $typ = $dok === null ? null : DateiTyp::fuerEndung((string) $dok['endung']);
        if ($dok === null || $typ === null || !$typ['bild'] || !$this->dokumente->istZugeordnet((int) $dok['id'], 'fahrzeug', $fzId)) {
            $this->session->meldung('fehler', 'Bitte ein Foto dieses Fahrzeugs als Titelbild wählen.');
            return $this->zurueck($fzId);
        }
        $this->dokumente->setzeTitelbild($fzId, (int) $dok['id']);
        $this->protokoll->schreibe($this->auth->benutzer()?->id, 'fahrzeug', $fzId, 'geaendert', ['titelbild' => ['alt' => $fz['titelbild_dokument_id'], 'neu' => (int) $dok['id']]]);
        $this->session->meldung('erfolg', 'Das Titelbild wurde geändert.');
        return $this->zurueck($fzId);
    }

    public function loeschen(Request $r, array $p): Response
    {
        $dok = $this->ladeDokument($p);
        $fahrzeugIds = $this->dokumente->objekte((int) $dok['id'], 'fahrzeug');
        $this->dokumente->loeschen((int) $dok['id']);
        foreach ($fahrzeugIds as $fzId) {
            $this->protokoll->schreibe($this->auth->benutzer()?->id, 'fahrzeug', $fzId, 'geaendert', ['datei' => ['alt' => $dok['titel'], 'neu' => null]]);
        }
        $this->session->meldung('erfolg', 'Die Datei wurde gelöscht.');
        return isset($fahrzeugIds[0]) ? $this->zurueck($fahrzeugIds[0]) : Response::redirect(url('/fahrzeuge'));
    }

    public function ausliefern(Request $r, array $p): Response
    {
        $dok = $this->ladeDokument($p);
        $variante = $p['variante'] ?? null;
        if ($variante !== null && $variante !== 'anzeige' && $variante !== 'vorschau') {
            throw new HttpFehler(404);
        }
        $basis = $this->dokumente->absoluterPfad((string) $dok['pfad']);
        if ($basis === null || !is_file($basis)) {
            throw new HttpFehler(404);
        }
        $typ = DateiTyp::fuerEndung((string) $dok['endung']);
        $datei = $basis;
        $mime = (string) $dok['mime'];
        $inline = $typ !== null && $typ['anzeige'] === 'inline';
        if ($variante !== null && $typ !== null && $typ['bild'] && is_file($basis . '.' . $variante . '.jpg')) {
            $datei = $basis . '.' . $variante . '.jpg';
            $mime = 'image/jpeg';
            $inline = true;
        }
        $name = (string) $dok['original_name'];
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name), '_') ?: 'datei';
        $header = [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . "\"; filename*=UTF-8''" . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];
        if ($mime !== 'application/pdf') {
            $header['Content-Security-Policy'] = "default-src 'none'; sandbox";
        }
        return Response::datei($datei, $header);
    }

    /**
     * Vereinheitlicht $_FILES-Einträge (einzeln oder `datei[]`) zu einer Liste; leere Auswahl entfällt.
     *
     * @return list<array<string,mixed>>
     */
    private static function normalisiere(mixed $f): array
    {
        if (!is_array($f) || !isset($f['error'])) {
            return [];
        }
        if (!is_array($f['error'])) {
            return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $liste = [];
        foreach ($f['error'] as $i => $fehler) {
            if (is_array($fehler) || $fehler === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $liste[] = [
                'name' => $f['name'][$i] ?? '',
                'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error' => $fehler,
                'size' => $f['size'][$i] ?? 0,
            ];
        }
        return $liste;
    }

    private function zurueck(int $fahrzeugId): Response
    {
        return Response::redirect(url('/fahrzeuge/' . $fahrzeugId) . '#dateien');
    }

    private static function id(array $p): int
    {
        $id = preg_match('/^\d{1,10}$/', (string) ($p['id'] ?? '')) === 1 ? (int) $p['id'] : 0;
        return $id > 0 && $id <= 4294967295 ? $id : 0;
    }

    /** @return array<string,mixed> */
    private function ladeFahrzeug(array $p): array
    {
        $id = self::id($p);
        $fz = $id > 0 ? $this->fahrzeuge->finde($id) : null;
        if ($fz === null) {
            throw new HttpFehler(404);
        }
        return $fz;
    }

    /** @return array<string,mixed> */
    private function ladeDokument(array $p): array
    {
        $id = self::id($p);
        $dok = $id > 0 ? $this->dokumente->finde($id) : null;
        if ($dok === null) {
            throw new HttpFehler(404);
        }
        return $dok;
    }
}
