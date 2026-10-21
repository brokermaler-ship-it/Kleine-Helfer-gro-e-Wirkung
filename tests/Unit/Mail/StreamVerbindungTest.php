<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Mail;

use Emc\Mail\MailFehler;
use Emc\Mail\StreamVerbindung;
use PHPUnit\Framework\TestCase;

final class StreamVerbindungTest extends TestCase
{
    /** Ein Server, der die Verbindung annimmt, aber nie antwortet, hält den Versand höchstens die Gesamtfrist auf. */
    public function test_gesamtfrist_begrenzt_stummen_server(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $nr, $text);
        $this->assertNotFalse($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $v = new StreamVerbindung(gesamtSekunden: 1);
        $start = microtime(true);
        try {
            $v->oeffne('127.0.0.1', $port, false, 15);
            $v->liesAntwort();
            $this->fail('MailFehler erwartet');
        } catch (MailFehler $e) {
            $this->assertStringStartsWith('SMTP: ', $e->getMessage());
        } finally {
            $v->schliesse();
            fclose($server);
        }
        $dauer = microtime(true) - $start;
        $this->assertGreaterThan(0.8, $dauer);
        $this->assertLessThan(3.0, $dauer);
    }

    public function test_nach_ablauf_der_gesamtfrist_kein_weiterer_schritt(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $nr, $text);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $v = new StreamVerbindung(gesamtSekunden: 1);
        try {
            $v->oeffne('127.0.0.1', $port, false, 15);
            usleep(1_100_000);
            $this->expectException(MailFehler::class);
            $this->expectExceptionMessage('SMTP: Gesamtzeit überschritten');
            $v->schreibe('NOOP');
        } finally {
            $v->schliesse();
            fclose($server);
        }
    }
}
