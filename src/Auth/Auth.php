<?php

declare(strict_types=1);

namespace Emc\Auth;

use Emc\Core\Session;

final class Auth
{
    public const MAX_FEHLVERSUCHE = 5;
    public const SPERRE_MINUTEN = 15;
    private const SESSION_KEY = 'benutzer_id';

    private static ?string $attrappenHash = null;
    private ?Benutzer $geladen = null;
    private ?int $geladenId = null;

    public function __construct(private \PDO $pdo, private Session $session)
    {
    }

    public function anmelden(string $email, string $passwort, string $ip): AnmeldeErgebnis
    {
        $email = mb_strtolower(trim($email));

        if ($this->fehlversuche($email) >= self::MAX_FEHLVERSUCHE) {
            return AnmeldeErgebnis::Gesperrt;
        }

        $st = $this->pdo->prepare('SELECT id, passwort_hash, aktiv FROM benutzer WHERE email = ?');
        $st->execute([$email]);
        $zeile = $st->fetch(\PDO::FETCH_ASSOC);

        // Immer genau eine Passwortprüfung, damit Antwortzeit und Meldung nichts über die E-Mail verraten.
        $hash = $zeile !== false ? (string) $zeile['passwort_hash'] : $this->attrappenHash();
        $richtig = password_verify($passwort, $hash) && $zeile !== false;

        if (!$richtig) {
            $this->protokolliere($email, $ip, false);
            return AnmeldeErgebnis::Falsch;
        }
        if ((int) $zeile['aktiv'] !== 1) {
            $this->protokolliere($email, $ip, false);
            return AnmeldeErgebnis::Inaktiv;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->pdo->prepare('UPDATE benutzer SET passwort_hash = ? WHERE id = ?')
                ->execute([password_hash($passwort, PASSWORD_DEFAULT), $zeile['id']]);
        }
        $this->protokolliere($email, $ip, true);
        $this->pdo->prepare('UPDATE benutzer SET letzter_login = NOW() WHERE id = ?')->execute([$zeile['id']]);

        $this->session->erneuere();
        $this->session->setze(self::SESSION_KEY, (int) $zeile['id']);
        $this->geladen = null;
        $this->geladenId = null;
        return AnmeldeErgebnis::Erfolg;
    }

    public function abmelden(): void
    {
        $this->session->entferne(self::SESSION_KEY);
        $this->session->erneuere();
        $this->geladen = null;
        $this->geladenId = null;
    }

    public function benutzer(): ?Benutzer
    {
        $id = $this->session->hole(self::SESSION_KEY);
        if (!is_int($id)) {
            return null;
        }
        if ($this->geladenId === $id) {
            return $this->geladen;
        }
        $st = $this->pdo->prepare(
            'SELECT b.id, b.person_id, b.email, b.rolle, b.muss_passwort_aendern, p.vorname, p.nachname '
            . 'FROM benutzer b JOIN person p ON p.id = b.person_id WHERE b.id = ? AND b.aktiv = 1'
        );
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        $this->geladenId = $id;
        $this->geladen = $z === false ? null : new Benutzer(
            (int) $z['id'],
            (int) $z['person_id'],
            (string) $z['email'],
            trim($z['vorname'] . ' ' . $z['nachname']),
            Rolle::from((string) $z['rolle']),
            (int) $z['muss_passwort_aendern'] === 1,
        );
        return $this->geladen;
    }

    private function fehlversuche(string $email): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_versuch WHERE email = ? AND erfolgreich = 0 '
            . 'AND zeitpunkt > (NOW() - INTERVAL ' . self::SPERRE_MINUTEN . ' MINUTE)'
        );
        $st->execute([$email]);
        return (int) $st->fetchColumn();
    }

    private function protokolliere(string $email, string $ip, bool $erfolgreich): void
    {
        $this->pdo->prepare('INSERT INTO login_versuch (email, ip, zeitpunkt, erfolgreich) VALUES (?, ?, NOW(), ?)')
            ->execute([mb_substr($email, 0, 190), mb_substr($ip, 0, 45), $erfolgreich ? 1 : 0]);
    }

    private function attrappenHash(): string
    {
        return self::$attrappenHash ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    }
}
