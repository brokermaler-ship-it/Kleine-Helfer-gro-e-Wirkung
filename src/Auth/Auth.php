<?php

declare(strict_types=1);

namespace Emc\Auth;

use Emc\Core\Session;

final class Auth
{
    public const MAX_FEHLVERSUCHE = 5;
    public const SPERRE_MINUTEN = 15;
    private const SESSION_KEY = 'benutzer_id';
    /** Fingerabdruck der Instanz, an der die Anmeldung erfolgt ist (siehe Instanz::fingerabdruck). */
    public const INSTANZ_KEY = 'instanz';
    /** Kennwort-Stand des Kontos bei der Anmeldung; weicht er vom Stand in der Datenbank ab, endet die Sitzung. */
    public const STAND_KEY = 'kennwort_stand';

    /** Vorberechneter bcrypt-Hash (Kosten 10) für unbekannte E-Mails: gleiche Rechenzeit, kein password_hash je Anfrage. */
    private const ATTRAPPE = '$2y$10$N3RKsKsuGQBFYUWpIQyp..zwSKRjz27CPnRIFDoLb/jx9nVvPagpG';
    private ?Benutzer $geladen = null;
    private ?int $geladenId = null;

    public function __construct(private \PDO $pdo, private Session $session, private string $instanz)
    {
    }

    public function anmelden(string $email, string $passwort, string $ip): AnmeldeErgebnis
    {
        $email = mb_strtolower(trim($email));

        if ($this->fehlversuche($email) >= self::MAX_FEHLVERSUCHE) {
            return AnmeldeErgebnis::Gesperrt;
        }

        // SELECT *: vor Migration 010 gibt es kennwort_stand noch nicht (Anmeldung zum Datenbank-Update).
        $st = $this->pdo->prepare('SELECT * FROM benutzer WHERE email = ?');
        $st->execute([$email]);
        $zeile = $st->fetch(\PDO::FETCH_ASSOC);

        // Immer genau eine Passwortprüfung, damit Antwortzeit und Meldung nichts über die E-Mail verraten.
        $hash = $zeile !== false ? (string) $zeile['passwort_hash'] : self::ATTRAPPE;
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

        $this->setzeSitzung((int) $zeile['id'], (int) ($zeile['kennwort_stand'] ?? 0));
        return AnmeldeErgebnis::Erfolg;
    }

    /** Meldet ein aktives Konto ohne Kennwortprüfung an (nach „Kennwort vergessen“, der Token ist der Nachweis). */
    public function anmeldenAls(int $benutzerId): void
    {
        $st = $this->pdo->prepare('SELECT * FROM benutzer WHERE id = ? AND aktiv = 1');
        $st->execute([$benutzerId]);
        $zeile = $st->fetch(\PDO::FETCH_ASSOC);
        if ($zeile === false) {
            throw new \RuntimeException('Konto nicht gefunden oder deaktiviert.');
        }
        $this->pdo->prepare('UPDATE benutzer SET letzter_login = NOW() WHERE id = ?')->execute([$benutzerId]);
        $this->setzeSitzung($benutzerId, (int) ($zeile['kennwort_stand'] ?? 0));
    }

    /**
     * Nach einer Kennwortänderung in dieser Sitzung: den neuen Kennwort-Stand übernehmen, damit nur die anderen
     * Sitzungen des Kontos enden. Wirkt nur, wenn diese Sitzung genau dieses Konto angemeldet hat.
     */
    public function uebernimmStand(int $benutzerId): void
    {
        if ($this->session->hole(self::SESSION_KEY) !== $benutzerId) {
            return;
        }
        $st = $this->pdo->prepare('SELECT * FROM benutzer WHERE id = ?');
        $st->execute([$benutzerId]);
        $zeile = $st->fetch(\PDO::FETCH_ASSOC);
        if ($zeile !== false) {
            $this->session->setze(self::STAND_KEY, (int) ($zeile['kennwort_stand'] ?? 0));
            $this->geladen = null;
            $this->geladenId = null;
        }
    }

    private function setzeSitzung(int $benutzerId, int $kennwortStand): void
    {
        $this->session->erneuere();
        $this->session->setze(self::SESSION_KEY, $benutzerId);
        $this->session->setze(self::INSTANZ_KEY, $this->instanz);
        $this->session->setze(self::STAND_KEY, $kennwortStand);
        $this->geladen = null;
        $this->geladenId = null;
    }

    /** Verwirft die ganze Sitzung; danach gesetzte Meldungen bleiben für die nächste Seite erhalten. */
    public function abmelden(): void
    {
        $this->session->leere();
        $this->geladen = null;
        $this->geladenId = null;
    }

    public function benutzer(): ?Benutzer
    {
        $id = $this->session->hole(self::SESSION_KEY);
        if (!is_int($id)) {
            return null;
        }
        $instanz = $this->session->hole(self::INSTANZ_KEY);
        if (!is_string($instanz) || !hash_equals($this->instanz, $instanz)) {
            // Sitzung einer anderen Instanz (z. B. Test statt Echtbetrieb im selben Hosting-Paket): nie übernehmen.
            $this->abmelden();
            return null;
        }
        if ($this->geladenId === $id) {
            return $this->geladen;
        }
        // b.*: vor Migration 010 fehlt kennwort_stand (gilt dann als 0), vor 016 ist_boersenwart (gilt dann als 0).
        $st = $this->pdo->prepare(
            'SELECT b.*, p.vorname, p.nachname FROM benutzer b JOIN person p ON p.id = b.person_id WHERE b.id = ? AND b.aktiv = 1'
        );
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        $stand = $this->session->hole(self::STAND_KEY, 0);
        if ($z !== false && (!is_int($stand) || $stand !== (int) ($z['kennwort_stand'] ?? 0))) {
            // Kennwort inzwischen über „Kennwort vergessen“ neu gesetzt: diese Sitzung ist beendet.
            $this->abmelden();
            return null;
        }
        $this->geladenId = $id;
        $this->geladen = $z === false ? null : new Benutzer(
            (int) $z['id'],
            (int) $z['person_id'],
            (string) $z['email'],
            trim($z['vorname'] . ' ' . $z['nachname']),
            Rolle::from((string) $z['rolle']),
            (int) $z['muss_passwort_aendern'] === 1,
            trim((string) $z['vorname']),
            (int) ($z['ist_boersenwart'] ?? 0) === 1,
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
}
