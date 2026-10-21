<?php

declare(strict_types=1);

namespace Emc\Auth;

use Emc\Core\DomainFehler;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;

final class KennwortController
{
    /**
     * @param float $mindestSekunden Mindestdauer der Antwort auf „Kennwort vergessen“, solange echter SMTP-Versand
     *                               eingerichtet ist: der Versand an bekannte Adressen soll sich nicht an der
     *                               Antwortzeit erkennen lassen.
     */
    public function __construct(
        private View $view,
        private KennwortVergessen $dienst,
        private Auth $auth,
        private Session $session,
        private float $mindestSekunden = 0.0,
    ) {
    }

    public function vergessenForm(Request $r): Response
    {
        if ($this->auth->benutzer() !== null) {
            return Response::redirect(url('/'));
        }
        return $this->seite('auth/kennwort_vergessen', ['titel' => 'Kennwort vergessen', 'gesendet' => false, 'testlink' => null]);
    }

    public function vergessen(Request $r): Response
    {
        if ($this->auth->benutzer() !== null) {
            return Response::redirect(url('/'));
        }
        $start = hrtime(true);
        $email = $r->post('email', '');
        $link = $this->dienst->beantrage(is_string($email) ? $email : '', (string) $r->server('REMOTE_ADDR', ''));
        $rest = $this->mindestSekunden - (hrtime(true) - $start) / 1e9;
        if ($rest > 0) {
            usleep((int) ($rest * 1e6));
        }
        // Immer dieselbe Seite: keine Adresse, kein Hinweis auf Konto, Begrenzung oder Versandergebnis.
        return $this->seite('auth/kennwort_vergessen', ['titel' => 'Kennwort vergessen', 'gesendet' => true, 'testlink' => $link]);
    }

    public function neuForm(Request $r): Response
    {
        $token = self::token($r->query('t'));
        if ($this->dienst->pruefeToken($token) === null) {
            return $this->ungueltig(200);
        }
        return $this->formular($token, []);
    }

    public function neu(Request $r): Response
    {
        $token = self::token($r->post('t'));
        $kennwort = $r->post('neues_passwort', '');
        $wiederholung = $r->post('wiederholung', '');
        $kennwort = is_string($kennwort) ? $kennwort : '';
        if ($this->dienst->pruefeToken($token) === null) {
            return $this->ungueltig(422);
        }
        if (!is_string($wiederholung) || !hash_equals($kennwort, $wiederholung)) {
            return $this->formular($token, ['wiederholung' => 'Die beiden Kennwörter stimmen nicht überein.'], 422);
        }
        try {
            $id = $this->dienst->setzeNeu($token, $kennwort);
        } catch (DomainFehler $e) {
            if ($e->getMessage() === KennwortVergessen::UNGUELTIG) {
                return $this->ungueltig(422);
            }
            return $this->formular($token, ['neues_passwort' => $e->getMessage()], 422);
        }
        $this->auth->anmeldenAls($id);
        $this->session->meldung('erfolg', 'Dein Kennwort wurde geändert. Du bist jetzt angemeldet.');
        return Response::redirect(url('/'));
    }

    private static function token(mixed $t): string
    {
        return is_string($t) && preg_match('/^[0-9a-f]{64}\z/', $t) === 1 ? $t : '';
    }

    private function ungueltig(int $status): Response
    {
        return $this->seite('auth/kennwort_neu', ['titel' => 'Neues Kennwort', 'token' => null, 'fehler' => []], $status);
    }

    /** @param array<string,string> $fehler */
    private function formular(string $token, array $fehler, int $status = 200): Response
    {
        return $this->seite('auth/kennwort_neu', ['titel' => 'Neues Kennwort', 'token' => $token, 'fehler' => $fehler], $status);
    }

    /** @param array<string,mixed> $daten */
    private function seite(string $template, array $daten, int $status = 200): Response
    {
        $html = $this->view->render($template, $daten + ['login_seite' => true]);
        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Der Token steht in der Adresse: nie als Referer weitergeben und nicht zwischenspeichern.
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ]);
    }
}
