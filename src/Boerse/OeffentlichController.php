<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Core\DomainFehler;
use Emc\Core\HttpFehler;
use Emc\Core\Instanz;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;
use Emc\Dateien\DokumentRepository;
use Emc\Magazin\MagazinRepository;
use Emc\Stammdaten\SpurweiteRepository;

/**
 * Öffentlicher Bereich /boerse ohne Anmeldung: Angebotsliste, Angebotsseite mit Kaufanfrage, „Sammlung anbieten“,
 * Dankeseite und Fotos (nur Anzeige-/Vorschau-Variante freigegebener Angebote).
 *
 * Datenschutz: Die Seiten bekommen nur die Felder aus AngebotRepository::oeffentliche/oeffentlichNachNummer (keine
 * Person, Herkunft, Notiz, Magazindaten, Lagerorte) und das eigene Layout ohne Menü, Hinweise, Meldungen und
 * Benutzerdaten. Nicht sichtbare und unbekannte Angebote antworten gleich (404).
 * Schutz: CSRF (global in App), Fangfeld und Mindestzeit (Bot → dieselbe Weiterleitung wie bei Erfolg, nichts
 * gespeichert, nicht gezählt), Begrenzung je IP (429).
 */
final class OeffentlichController
{
    public const BEGRENZT = 'Bitte später erneut versuchen.';
    /** Sitzungsschlüssel: Zahl der abgelehnten Dateien für die nächste Dankeseite. */
    private const ABGELEHNT = 'boerse_fotos_abgelehnt';

    /** @param array<string,mixed> $config */
    public function __construct(
        private View $view,
        private array $config,
        private AngebotRepository $angebote,
        private BoerseRepository $boersen,
        private MagazinRepository $magazin,
        private SpurweiteRepository $spurweiten,
        private AnfrageDienst $anfragen,
        private Spamschutz $spam,
        private Session $session,
        private ?DokumentRepository $dokumente = null,
    ) {
    }

    public function liste(Request $r): Response
    {
        $this->verlangeAktiv();
        $this->raeumeAuf();
        $kategorieId = $r->ganzzahl('kategorie', 1, 4294967295);
        $spurweiteId = $r->ganzzahl('spurweite', 1, 4294967295);
        $q = mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $r->text('q'))), 0, 100);
        return $this->seite('liste', [
            'titel' => 'Angebote',
            'angebote' => $this->angebote->oeffentliche($kategorieId, $spurweiteId, $q === '' ? null : $q),
            'kategorien' => $this->magazin->kategorien(),
            'spurweiten' => $this->spurweiten->alle(),
            'kategorieId' => $kategorieId,
            'spurweiteId' => $spurweiteId,
            'q' => $q,
            'naechste' => $this->boersen->naechste(new \DateTimeImmutable('today')),
        ]);
    }

    /** @param array<string,string> $p */
    public function angebot(Request $r, array $p): Response
    {
        $this->verlangeAktiv();
        $this->raeumeAuf();
        $a = $this->sichtbares($p);
        if ($a === null) {
            return $this->nichtGefunden();
        }
        return $this->angebotSeite($a, [], [], $this->spam->zeitmarke(new \DateTimeImmutable()), null, 200);
    }

    /** POST /boerse/angebot/{nummer}/anfrage */
    public function anfrage(Request $r, array $p): Response
    {
        $this->verlangeAktiv();
        $a = $this->sichtbares($p);
        if ($a === null) {
            return $this->nichtGefunden();
        }
        return $this->absenden($r, 'kaufanfrage', $a);
    }

    public function sammlungFormular(Request $r): Response
    {
        $this->verlangeAktiv();
        $this->raeumeAuf();
        return $this->sammlungSeite([], [], $this->spam->zeitmarke(new \DateTimeImmutable()), null, 200);
    }

    /** POST /boerse/sammlung-anbieten */
    public function sammlung(Request $r): Response
    {
        $this->verlangeAktiv();
        return $this->absenden($r, 'sammlung', null);
    }

    public function danke(Request $r): Response
    {
        $this->verlangeAktiv();
        $this->raeumeAuf();
        $abgelehnt = (int) $this->session->hole(self::ABGELEHNT, 0);
        $this->session->entferne(self::ABGELEHNT);
        return $this->seite('danke', ['titel' => 'Danke', 'abgelehnt' => max(0, $abgelehnt)], 200, true);
    }

    /**
     * GET /boerse/foto/{nummer}/{dokumentId}/{variante}: nur die GD-erzeugte Anzeige- bzw. Vorschau-Variante eines
     * Fotos, das einem öffentlich sichtbaren Angebot zugeordnet ist – nie das Original, nie der Originalname.
     *
     * @param array<string,string> $p
     */
    public function foto(Request $r, array $p): Response
    {
        $this->verlangeAktiv();
        $this->raeumeAuf();
        $variante = (string) ($p['variante'] ?? '');
        $dokId = preg_match('/^[1-9]\d{0,9}$/', (string) ($p['dokumentId'] ?? '')) === 1 ? (int) $p['dokumentId'] : 0;
        $a = $this->sichtbares($p);
        if (!in_array($variante, ['anzeige', 'vorschau'], true) || $dokId === 0 || $a === null || !in_array($dokId, $a['foto_ids'], true) || $this->dokumente === null) {
            throw new HttpFehler(404);
        }
        $dok = $this->dokumente->finde($dokId);
        $basis = $dok === null ? null : $this->dokumente->absoluterPfad((string) $dok['pfad']);
        $datei = $basis === null ? null : $basis . '.' . $variante . '.jpg';
        if ($datei === null || !is_file($datei)) {
            throw new HttpFehler(404);
        }
        return Response::datei($datei, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto.jpg"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * Seite im öffentlichen Layout (auch für App: Wartung, zu große Anfrage). Benutzer, Hinweise und Meldungen werden
     * ausdrücklich leer übergeben, damit das Layout sie weder zeigt noch (Meldungen) verbraucht.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $daten
     * @param array<string,string> $header
     */
    public static function oeffentlicheSeite(View $view, array $config, string $template, array $daten, int $status = 200, bool $noindex = false, array $header = []): Response
    {
        $html = $view->render('boerse/oeffentlich/' . $template, $daten + [
            'benutzer' => null,
            'betriebshinweise' => [],
            'meldungen' => [],
            'datenschutz_url' => OeffentlicherBereich::datenschutzUrl($config),
            'noindex' => $noindex || Instanz::istTestumgebung($config),
        ], 'boerse/oeffentlich/_layout');
        return new Response($html, $status, $header + [
            'Content-Type' => 'text/html; charset=utf-8',
            // Seiten mit CSRF-Token und Eingaben nie zwischenspeichern; keine Pfade an fremde Seiten weitergeben.
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'same-origin',
        ]);
    }

    private function absenden(Request $r, string $art, ?array $angebot): Response
    {
        $jetzt = new \DateTimeImmutable();
        $schutz = [Spamschutz::FANGFELD => $r->post(Spamschutz::FANGFELD), Spamschutz::ZEITFELD => $r->post(Spamschutz::ZEITFELD)];
        if (!$this->spam->istMensch($schutz, $jetzt)) {
            // Stilles Verwerfen: dieselbe Antwort wie bei Erfolg, nichts gespeichert, nicht gezählt.
            return Response::redirect(url('/boerse/danke'));
        }
        $zeitmarke = (string) $schutz[Spamschutz::ZEITFELD]; // bleibt gültig: sofortiges Korrigieren gilt nicht als Bot
        $eingabe = [];
        foreach (['name', 'email', 'telefon', 'nachricht', 'wunsch', 'einwilligung'] as $feld) {
            $wert = $r->post($feld);
            $eingabe[$feld] = is_string($wert) ? mb_substr($wert, 0, $feld === 'nachricht' ? 3100 : 200) : '';
        }
        $fotos = $art === 'sammlung' ? AnfrageDienst::fotos($r->file('fotos')) : [];
        [$daten, $fehler] = AnfrageValidator::pruefe($eingabe, $art, count($fotos));
        if ($fehler !== []) {
            return $this->formular($art, $angebot, $eingabe, $fehler, $zeitmarke, null, 422);
        }
        try {
            $ergebnis = $this->anfragen->nimmEntgegen($art, $angebot['id'] ?? null, $daten, $fotos, (string) $r->server('REMOTE_ADDR', ''), $jetzt);
        } catch (DomainFehler) {
            // Sperrkonflikt: nichts gespeichert.
            return $this->formular($art, $angebot, $eingabe, [], $zeitmarke, self::BEGRENZT, 503);
        }
        if ($ergebnis === 'begrenzt') {
            return $this->formular($art, $angebot, $eingabe, [], $zeitmarke, self::BEGRENZT, 429);
        }
        if ($this->anfragen->abgelehnteFotos() > 0) {
            $this->session->setze(self::ABGELEHNT, $this->anfragen->abgelehnteFotos());
        }
        return Response::redirect(url('/boerse/danke'));
    }

    /**
     * @param array<string,string> $eingabe
     * @param array<string,string> $fehler
     */
    private function formular(string $art, ?array $angebot, array $eingabe, array $fehler, string $zeitmarke, ?string $hinweis, int $status): Response
    {
        return $art === 'sammlung' || $angebot === null
            ? $this->sammlungSeite($eingabe, $fehler, $zeitmarke, $hinweis, $status)
            : $this->angebotSeite($angebot, $eingabe, $fehler, $zeitmarke, $hinweis, $status);
    }

    private function angebotSeite(array $a, array $eingabe, array $fehler, string $zeitmarke, ?string $hinweis, int $status): Response
    {
        return $this->seite('angebot', [
            'titel' => (string) $a['bezeichnung'],
            'a' => $a,
            'eingabe' => $eingabe,
            'fehler' => $fehler,
            'zeitmarke' => $zeitmarke,
            'hinweis' => $hinweis,
        ], $status);
    }

    private function sammlungSeite(array $eingabe, array $fehler, string $zeitmarke, ?string $hinweis, int $status): Response
    {
        return $this->seite('sammlung', [
            'titel' => 'Sammlung anbieten',
            'eingabe' => $eingabe,
            'fehler' => $fehler,
            'zeitmarke' => $zeitmarke,
            'hinweis' => $hinweis,
        ], $status);
    }

    private function nichtGefunden(): Response
    {
        return $this->seite('nicht_gefunden', ['titel' => 'Nicht gefunden'], 404, true);
    }

    /** @param array<string,mixed> $daten */
    private function seite(string $template, array $daten, int $status = 200, bool $noindex = false): Response
    {
        return self::oeffentlicheSeite($this->view, $this->config, $template, $daten, $status, $noindex);
    }

    /**
     * @param array<string,string> $p
     * @return ?array<string,mixed> öffentlich sichtbares Angebot zur Nummer aus der Adresse
     */
    private function sichtbares(array $p): ?array
    {
        $nr = Angebotsnummer::zerlege((string) ($p['nummer'] ?? ''));
        return $nr === null ? null : $this->angebote->oeffentlichNachNummer($nr['jahr'], $nr['lauf']);
    }

    /** Zweite Sicherung neben der App: ohne Schalter und Datenschutzerklärung gibt es den Bereich nicht. */
    private function verlangeAktiv(): void
    {
        if (!OeffentlicherBereich::aktiv($this->config)) {
            throw new HttpFehler(404);
        }
    }

    /** Löschung nach 12 Monaten bei Seitenaufrufen (höchstens stündlich); Fehler verhindern die Seite nicht. */
    private function raeumeAuf(): void
    {
        try {
            $this->anfragen->raeumeAufHoechstensStuendlich(new \DateTimeImmutable());
        } catch (\Throwable $t) {
            error_log('Börsenanfragen aufräumen fehlgeschlagen: ' . $t::class);
        }
    }
}
