<?php

declare(strict_types=1);

namespace Emc\Auth;

use Emc\Core\Csrf;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Core\View;

final class AuthController
{
    public function __construct(
        private View $view,
        private Auth $auth,
        private Session $session,
        private Csrf $csrf,
    ) {
    }

    public function loginForm(Request $r): Response
    {
        $ziel = self::sicheresZiel($r->query('ziel'));
        if ($this->auth->benutzer() !== null) {
            return Response::redirect(url($ziel));
        }
        return $this->seite($ziel, '', null);
    }

    public function login(Request $r): Response
    {
        $ziel = self::sicheresZiel($r->input('ziel'));
        $email = (string) $r->input('email', '');
        $passwort = (string) $r->input('passwort', '');
        $ip = (string) $r->server('REMOTE_ADDR', '');

        $ergebnis = $this->auth->anmelden($email, $passwort, $ip);
        if ($ergebnis === AnmeldeErgebnis::Erfolg) {
            $name = $this->auth->benutzer()?->rufname() ?? '';
            $this->session->meldung('erfolg', 'Willkommen in der Modellbahnfamilie' . ($name !== '' ? ', ' . $name : '') . '!');
            return Response::redirect(url($ziel));
        }
        $text = match ($ergebnis) {
            AnmeldeErgebnis::Gesperrt => 'Zu viele Fehlversuche. Bitte warte 15 Minuten.',
            AnmeldeErgebnis::Inaktiv => 'Dein Zugang ist deaktiviert. Bitte wende dich an den Vorstand.',
            default => 'E-Mail oder Passwort ist falsch.',
        };
        return $this->seite($ziel, $email, $text);
    }

    public function logout(Request $r): Response
    {
        $this->auth->abmelden();
        $this->session->meldung('erfolg', 'Du wurdest abgemeldet.');
        return Response::redirect(url('/login'));
    }

    /** Nur interne, absolute Pfade zulassen (kein Open Redirect). */
    public static function sicheresZiel(mixed $ziel): string
    {
        if (!is_string($ziel) || $ziel === '' || $ziel[0] !== '/') {
            return '/';
        }
        if (str_starts_with($ziel, '//') || str_contains($ziel, '\\') || preg_match('/[\x00-\x1f\x7f]/', $ziel)) {
            return '/';
        }
        return $ziel;
    }

    private function seite(string $ziel, string $email, ?string $fehler): Response
    {
        $html = $this->view->render('auth/login', [
            'titel' => 'Anmelden',
            'login_seite' => true,
            'ziel' => $ziel,
            'email' => $email,
            'fehler' => $fehler,
        ]);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
