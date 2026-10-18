<?php

declare(strict_types=1);

namespace Emc\Digital;

use Emc\Auth\Auth;
use Emc\Core\HttpFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\Protokoll\Aenderungsprotokoll;

final class DecoderController
{
    public function __construct(
        private View $view,
        private DecoderRepository $decoder,
        private FahrzeugRepository $fahrzeuge,
        private Adresspruefung $pruefung,
        private Aenderungsprotokoll $protokoll,
        private Auth $auth,
        private Session $session,
    ) {
    }

    public function anlegen(Request $r, array $p): Response
    {
        $fz = $this->ladeFahrzeug($p);
        [$daten, $fehler] = $this->lies($r);
        if ($fehler !== []) {
            return $this->formular($this->anzeigeWerte($r), $fehler, $fz, null, 422);
        }
        $id = $this->decoder->anlegen((int) $fz['id'], $daten);
        $this->protokoll->schreibe(
            $this->auth->benutzer()?->id,
            'decoder',
            $id,
            'angelegt',
            Aenderungsprotokoll::diff([], ['fahrzeug_id' => (int) $fz['id']] + $this->protokollWerte($daten))
        );
        $this->session->meldung('erfolg', 'Der Decoder wurde gespeichert.');
        $this->warneBeiKonflikt($fz, $daten);
        return Response::redirect(url('/fahrzeuge/' . $fz['id']) . '#digital');
    }

    public function bearbeiten(Request $r, array $p): Response
    {
        $dec = $this->ladeDecoder($p);
        $fz = $this->fahrzeuge->finde((int) $dec['fahrzeug_id']) ?? throw new HttpFehler(404);
        return $this->formular($dec, [], $fz, $dec);
    }

    public function aendern(Request $r, array $p): Response
    {
        $dec = $this->ladeDecoder($p);
        $fz = $this->fahrzeuge->finde((int) $dec['fahrzeug_id']) ?? throw new HttpFehler(404);
        [$daten, $fehler] = $this->lies($r);
        if ($fehler !== []) {
            return $this->formular($this->anzeigeWerte($r), $fehler, $fz, $dec, 422);
        }
        $this->decoder->aktualisieren((int) $dec['id'], $daten);
        $diff = Aenderungsprotokoll::diff($this->protokollWerte($dec), $this->protokollWerte($daten));
        if ($diff !== []) {
            $fid = (int) $fz['id'];
            $this->protokoll->schreibe(
                $this->auth->benutzer()?->id,
                'decoder',
                (int) $dec['id'],
                'geaendert',
                ['fahrzeug_id' => ['alt' => $fid, 'neu' => $fid]] + $diff
            );
        }
        $this->session->meldung('erfolg', 'Der Decoder wurde gespeichert.');
        $this->warneBeiKonflikt($fz, $daten);
        return Response::redirect(url('/fahrzeuge/' . $fz['id']) . '#digital');
    }

    public function loeschen(Request $r, array $p): Response
    {
        $dec = $this->ladeDecoder($p);
        $this->decoder->loeschen((int) $dec['id']);
        $alt = ['fahrzeug_id' => (int) $dec['fahrzeug_id']] + $this->protokollWerte($dec);
        $this->protokoll->schreibe(
            $this->auth->benutzer()?->id,
            'decoder',
            (int) $dec['id'],
            'geloescht',
            Aenderungsprotokoll::diff($alt, array_fill_keys(array_keys($alt), null))
        );
        $this->session->meldung('erfolg', 'Der Decoder wurde gelöscht.');
        return Response::redirect(url('/fahrzeuge/' . $dec['fahrzeug_id']) . '#digital');
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function lies(Request $r): array
    {
        $fehler = [];
        $text = function (string $feld, int $max, string $label) use ($r, &$fehler): ?string {
            $w = trim($r->text($feld));
            if (mb_strlen($w) > $max) {
                $fehler[$feld] = "$label darf höchstens $max Zeichen lang sein.";
            }
            return $w === '' ? null : $w;
        };
        $bezeichnung = $text('bezeichnung', 100, 'Die Bezeichnung');
        if ($bezeichnung === null) {
            $fehler['bezeichnung'] = 'Bitte eine Bezeichnung eingeben, z. B. „Motor“.';
        }
        $daten = [
            'bezeichnung' => $bezeichnung,
            'hersteller' => $text('hersteller', 100, 'Der Hersteller'),
            'typ' => $text('typ', 100, 'Der Typ'),
            'firmware' => $text('firmware', 50, 'Die Firmware'),
            'sound' => $r->text('sound') === '1',
            'protokoll' => Protokoll::tryFrom($r->text('protokoll')),
            'adresse' => null,
        ];
        if ($daten['protokoll'] === null) {
            $fehler['protokoll'] = 'Bitte ein Protokoll wählen.';
        } else {
            [$daten['adresse'], $meldung] = AdressValidator::lies($r, $daten['protokoll']);
            if ($meldung !== null) {
                $fehler['adresse'] = $meldung;
            }
        }
        return [$daten, $fehler];
    }

    /** Eingaben so zurück ins Formular, wie sie getippt wurden. */
    private function anzeigeWerte(Request $r): array
    {
        $w = [];
        foreach (['bezeichnung', 'hersteller', 'typ', 'firmware', 'protokoll', 'adresse'] as $f) {
            $w[$f] = $r->text($f);
        }
        $w['sound'] = $r->text('sound') === '1' ? 1 : 0;
        return $w;
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function protokollWerte(array $d): array
    {
        $p = $d['protokoll'] ?? null;
        return [
            'bezeichnung' => $d['bezeichnung'] ?? null,
            'hersteller' => $d['hersteller'] ?? null,
            'typ' => $d['typ'] ?? null,
            'firmware' => $d['firmware'] ?? null,
            'sound' => !empty($d['sound']) ? 1 : 0,
            'protokoll' => $p instanceof Protokoll ? $p->value : $p,
            'adresse' => $d['adresse'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $fz
     * @param array<string,mixed> $daten
     */
    private function warneBeiKonflikt(array $fz, array $daten): void
    {
        if ($daten['adresse'] === null) {
            return;
        }
        $konflikte = $this->pruefung->konflikte((int) $fz['spurweite_id'], $daten['protokoll'], $daten['adresse'], (int) $fz['id']);
        $text = Adresspruefung::warntext($daten['protokoll'], $daten['adresse'], (string) $fz['spurweite_name'], $konflikte);
        if ($text !== null) {
            $this->session->meldung('warnung', $text);
        }
    }

    /** @return array<string,mixed> */
    private function ladeFahrzeug(array $p): array
    {
        $id = $this->id($p);
        return ($id > 0 ? $this->fahrzeuge->finde($id) : null) ?? throw new HttpFehler(404);
    }

    /** @return array<string,mixed> */
    private function ladeDecoder(array $p): array
    {
        $id = $this->id($p);
        return ($id > 0 ? $this->decoder->finde($id) : null) ?? throw new HttpFehler(404);
    }

    private function id(array $p): int
    {
        $roh = (string) ($p['id'] ?? '');
        return preg_match('/^\d{1,10}$/', $roh) === 1 && (int) $roh <= 4294967295 ? (int) $roh : 0;
    }

    /**
     * @param array<string,mixed> $werte
     * @param array<string,string> $fehler
     * @param array<string,mixed> $fz
     * @param ?array<string,mixed> $dec
     */
    private function formular(array $werte, array $fehler, array $fz, ?array $dec, int $status = 200): Response
    {
        $html = $this->view->render('digital/decoder_formular', [
            'titel' => $dec === null ? 'Decoder hinzufügen' : 'Decoder bearbeiten',
            'werte' => $werte,
            'fehler' => $fehler,
            'fahrzeug' => $fz,
            'decoder' => $dec,
        ]);
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
