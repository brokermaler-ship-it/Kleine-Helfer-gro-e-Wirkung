<?php

declare(strict_types=1);

namespace Emc\Mail;

/** SMTP-Verbindung über stream_socket_client; Zertifikat und Hostname werden immer geprüft, mindestens TLS 1.2. */
final class StreamVerbindung implements SmtpVerbindung
{
    /** @var resource|null */
    private $strom = null;

    private static function methoden(): int
    {
        return STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }

    public function oeffne(string $host, int $port, bool $ssl, int $zeitlimit): void
    {
        $kontext = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'crypto_method' => self::methoden(),
        ]]);
        $fehlerNr = 0;
        $fehlerText = '';
        $strom = @stream_socket_client(
            ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port,
            $fehlerNr,
            $fehlerText,
            $zeitlimit,
            STREAM_CLIENT_CONNECT,
            $kontext
        );
        if ($strom === false) {
            throw new MailFehler('SMTP: keine Verbindung zum Server (' . $fehlerNr . ')');
        }
        stream_set_timeout($strom, $zeitlimit);
        $this->strom = $strom;
    }

    public function schreibe(string $zeile): void
    {
        if (preg_match('/[\r\n\0]/', $zeile) === 1) {
            throw new MailFehler('SMTP: ungültige Zeile');
        }
        $daten = $zeile . "\r\n";
        while ($daten !== '') {
            $n = @fwrite($this->strom(), $daten);
            if ($n === false || $n === 0) {
                throw new MailFehler('SMTP: Schreiben fehlgeschlagen');
            }
            $daten = substr($daten, $n);
        }
    }

    public function liesAntwort(): array
    {
        $text = [];
        $code = 0;
        // Höchstens 100 Zeilen, damit ein fehlerhafter Server nicht endlos liefert.
        for ($i = 0; $i < 100; $i++) {
            $zeile = fgets($this->strom(), 2048);
            if ($zeile === false) {
                $meta = stream_get_meta_data($this->strom());
                throw new MailFehler(!empty($meta['timed_out']) ? 'SMTP: Zeitüberschreitung' : 'SMTP: Verbindung unterbrochen');
            }
            $zeile = rtrim($zeile, "\r\n");
            if (preg_match('/^(\d{3})([ -])(.*)$/s', $zeile, $m) !== 1 && preg_match('/^(\d{3})()$/', $zeile, $m) !== 1) {
                throw new MailFehler('SMTP: unverständliche Antwort');
            }
            $code = (int) $m[1];
            $text[] = $m[3] ?? '';
            if (($m[2] ?? '') !== '-') {
                return ['code' => $code, 'text' => implode("\n", $text)];
            }
        }
        throw new MailFehler('SMTP: Antwort zu lang');
    }

    public function starteTls(): void
    {
        $ok = @stream_socket_enable_crypto($this->strom(), true, self::methoden());
        if ($ok !== true) {
            throw new MailFehler('SMTP: TLS konnte nicht gestartet werden');
        }
    }

    public function schliesse(): void
    {
        if ($this->strom !== null) {
            @fclose($this->strom);
            $this->strom = null;
        }
    }

    /** @return resource */
    private function strom()
    {
        if ($this->strom === null) {
            throw new MailFehler('SMTP: keine Verbindung');
        }
        return $this->strom;
    }
}
