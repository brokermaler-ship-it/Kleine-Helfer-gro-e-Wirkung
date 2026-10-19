<?php

declare(strict_types=1);

namespace Emc\Core;

/** Symmetrische Verschlüsselung (AES-256-GCM) für Geheimnisse in der Datenbank. */
final class Tresor
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE = 12;
    private const TAG = 16;

    private string $schluessel;

    public function __construct(string $schluesselBase64)
    {
        $roh = $schluesselBase64 === '' ? false : base64_decode($schluesselBase64, true);
        if ($roh === false || strlen($roh) !== 32) {
            throw new DomainFehler('app_schluessel fehlt oder ist ungültig (32 Byte, base64).');
        }
        $this->schluessel = $roh;
    }

    /** Liefert base64(nonce ‖ tag ‖ ciphertext). */
    public function verschluessele(string $klar): string
    {
        $nonce = random_bytes(self::NONCE);
        $tag = '';
        $cipher = openssl_encrypt($klar, self::CIPHER, $this->schluessel, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG);
        if ($cipher === false) {
            throw new DomainFehler('Verschlüsselung fehlgeschlagen.');
        }
        return base64_encode($nonce . $tag . $cipher);
    }

    public function entschluessele(string $geheim): string
    {
        $roh = base64_decode($geheim, true);
        if ($roh === false || strlen($roh) < self::NONCE + self::TAG) {
            throw new DomainFehler('Gespeicherte Daten sind ungültig oder wurden verändert.');
        }
        $klar = openssl_decrypt(
            substr($roh, self::NONCE + self::TAG),
            self::CIPHER,
            $this->schluessel,
            OPENSSL_RAW_DATA,
            substr($roh, 0, self::NONCE),
            substr($roh, self::NONCE, self::TAG)
        );
        if ($klar === false) {
            throw new DomainFehler('Gespeicherte Daten sind ungültig oder wurden verändert.');
        }
        return $klar;
    }
}
