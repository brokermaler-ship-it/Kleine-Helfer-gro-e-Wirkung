<?php

declare(strict_types=1);

namespace Emc\Mail;

/**
 * Kleiner SMTP-Versand mit Anmeldung (AUTH LOGIN), immer verschlüsselt: `ssl` (implizit, meist Port 465) oder
 * `tls` (STARTTLS, meist Port 587). Adressen werden vor dem Verbindungsaufbau geprüft, Betreff und Absendername
 * ohne Steuerzeichen als kodierte Wörter (RFC 2047) geschrieben, der Text base64-kodiert – so gelangt keine
 * Benutzereingabe ungefiltert in Kopfzeilen oder den SMTP-Dialog.
 */
final class SmtpMailer implements Mailer
{
    public const STANDARD_PORT = 465;
    public const STANDARD_PORT_TLS = 587;
    public const STANDARD_NAME = 'EMC 78 Lokschuppen';

    public function __construct(
        private SmtpVerbindung $v,
        private string $host,
        private int $port,
        private string $verschluesselung,
        private string $benutzer,
        #[\SensitiveParameter] private string $passwort,
        private string $absender,
        private string $absenderName,
        private int $zeitlimit = 15,
    ) {
    }

    /** E-Mail-Versand gilt als eingerichtet, wenn Server, Absender und öffentliche Adresse (für Links) gesetzt sind. */
    public static function konfiguriert(array $config): bool
    {
        foreach (['mail_smtp_host', 'mail_absender', 'oeffentliche_url'] as $k) {
            if (!is_string($config[$k] ?? null) || trim($config[$k]) === '') {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $config */
    public static function ausConfig(array $config, ?SmtpVerbindung $v = null): ?self
    {
        if (!self::konfiguriert($config)) {
            return null;
        }
        // Nie unverschlüsselt: alles außer „tls“ gilt als „ssl“.
        $verschluesselung = strtolower(trim((string) ($config['mail_smtp_verschluesselung'] ?? 'ssl'))) === 'tls' ? 'tls' : 'ssl';
        // Ohne Port der passende Standard: 587 für STARTTLS, 465 für SSL.
        $port = (int) ($config['mail_smtp_port'] ?? 0);
        $name = trim((string) ($config['mail_absender_name'] ?? ''));
        return new self(
            $v ?? new StreamVerbindung(),
            trim((string) $config['mail_smtp_host']),
            $port > 0 ? $port : ($verschluesselung === 'tls' ? self::STANDARD_PORT_TLS : self::STANDARD_PORT),
            $verschluesselung,
            (string) ($config['mail_smtp_benutzer'] ?? ''),
            (string) ($config['mail_smtp_passwort'] ?? ''),
            trim((string) $config['mail_absender']),
            $name !== '' ? $name : self::STANDARD_NAME
        );
    }

    public function sende(Nachricht $n): void
    {
        $an = self::pruefeAdresse($n->an, 'Empfänger');
        $absender = self::pruefeAdresse($this->absender, 'Absender');
        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $this->host) !== 1 || $this->port < 1 || $this->port > 65535) {
            throw new MailFehler('SMTP: ungültiger Server');
        }
        if (!in_array($this->verschluesselung, ['ssl', 'tls'], true)) {
            throw new MailFehler('SMTP: Verschlüsselung muss ssl oder tls sein');
        }
        $domain = substr($absender, strrpos($absender, '@') + 1);
        $zeilen = $this->nachrichtZeilen($an, $absender, $domain, $n);

        $this->v->oeffne($this->host, $this->port, $this->verschluesselung === 'ssl', $this->zeitlimit);
        $fertig = false;
        try {
            $this->erwarte([220], 'Verbindung');
            $this->befehl('EHLO ' . $domain, [250], 'EHLO');
            if ($this->verschluesselung === 'tls') {
                $this->befehl('STARTTLS', [220], 'STARTTLS');
                $this->v->starteTls();
                $this->befehl('EHLO ' . $domain, [250], 'EHLO');
            }
            if ($this->benutzer !== '') {
                // Alle drei Schritte heißen in Fehlermeldungen nur „AUTH“ – nie Benutzer oder Kennwort.
                $this->befehl('AUTH LOGIN', [334], 'AUTH');
                $this->befehl(base64_encode($this->benutzer), [334], 'AUTH');
                $this->befehl(base64_encode($this->passwort), [235], 'AUTH');
            }
            $this->befehl('MAIL FROM:<' . $absender . '>', [250], 'MAIL FROM');
            $this->befehl('RCPT TO:<' . $an . '>', [250, 251], 'RCPT TO');
            $this->befehl('DATA', [354], 'DATA');
            foreach ($zeilen as $zeile) {
                // Punkt am Zeilenanfang verdoppeln (RFC 5321, 4.5.2); kommt bei base64 und kodierten Wörtern nicht vor.
                $this->v->schreibe(str_starts_with($zeile, '.') ? '.' . $zeile : $zeile);
            }
            $this->befehl('.', [250], 'Nachrichtenende');
            $fertig = true;
            $this->v->schreibe('QUIT');
            try {
                $this->v->liesAntwort();
            } catch (MailFehler) {
                // Die Nachricht ist angenommen; ein stummes QUIT ist kein Fehler.
            }
        } catch (MailFehler $e) {
            if (!$fertig) {
                try {
                    $this->v->schreibe('QUIT');
                } catch (MailFehler) {
                }
            }
            throw $e;
        } finally {
            $this->v->schliesse();
        }
    }

    /** @param list<int> $codes */
    private function befehl(#[\SensitiveParameter] string $zeile, array $codes, string $name): void
    {
        $this->v->schreibe($zeile);
        $this->erwarte($codes, $name);
    }

    /** @param list<int> $codes */
    private function erwarte(array $codes, string $name): void
    {
        $antwort = $this->v->liesAntwort();
        if (!in_array($antwort['code'], $codes, true)) {
            // Antworttext bewusst nicht übernehmen: er kann Adressen oder (bei AUTH) Zugangsdaten enthalten.
            throw new MailFehler('SMTP: unerwartete Antwort ' . $antwort['code'] . ' auf ' . $name);
        }
    }

    private static function pruefeAdresse(string $adresse, string $rolle): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $adresse) === 1 || filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailFehler('SMTP: ungültige Adresse (' . $rolle . ')');
        }
        return $adresse;
    }

    /** @return list<string> Kopfzeilen, Leerzeile, base64-Körper (je Zeile ohne CRLF) */
    private function nachrichtZeilen(string $an, string $absender, string $domain, Nachricht $n): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $n->text);
        $text = str_replace("\n", "\r\n", $text);
        $kopf = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . implode("\r\n ", self::kodiereWoerter(self::ohneSteuerzeichen($this->absenderName), 6)) . ' <' . $absender . '>',
            'To: <' . $an . '>',
            'Subject: ' . implode("\r\n ", self::kodiereWoerter(self::ohneSteuerzeichen($n->betreff), 9)),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $zeilen = explode("\r\n", implode("\r\n", $kopf));
        $zeilen[] = '';
        foreach (explode("\r\n", rtrim(chunk_split(base64_encode($text), 76, "\r\n"), "\r\n")) as $z) {
            $zeilen[] = $z;
        }
        return $zeilen;
    }

    private static function ohneSteuerzeichen(string $s): string
    {
        // Byteweise: Steuerbytes kommen in UTF-8-Mehrbytezeichen nicht vor.
        $s = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $s);
        return trim(mb_check_encoding($s, 'UTF-8') ? $s : mb_scrub($s, 'UTF-8'));
    }

    /**
     * Teilt den Text in kodierte Wörter `=?UTF-8?B?…?=` von höchstens 75 Zeichen, ohne ein UTF-8-Zeichen zu zerreißen.
     * Das erste Wort ist kürzer, damit die erste Zeile samt Kopfname unter 78 Zeichen bleibt.
     *
     * @return list<string>
     */
    private static function kodiereWoerter(string $text, int $kopfLaenge): array
    {
        if ($text === '') {
            return ['=?UTF-8?B??='];
        }
        $woerter = [];
        $stueck = '';
        $max = intdiv(78 - $kopfLaenge - 12, 4) * 3; // Bytes, deren base64 in die erste Zeile passt
        foreach (mb_str_split($text, 1, 'UTF-8') as $zeichen) {
            if ($stueck !== '' && strlen($stueck . $zeichen) > $max) {
                $woerter[] = '=?UTF-8?B?' . base64_encode($stueck) . '?=';
                $stueck = '';
                $max = 45; // Folgezeilen: Leerzeichen + 75 Zeichen
            }
            $stueck .= $zeichen;
        }
        $woerter[] = '=?UTF-8?B?' . base64_encode($stueck) . '?=';
        return $woerter;
    }
}
