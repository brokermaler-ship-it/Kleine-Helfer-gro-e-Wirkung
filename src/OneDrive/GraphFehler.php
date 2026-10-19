<?php

declare(strict_types=1);

namespace Emc\OneDrive;

use Emc\Core\DomainFehler;

final class GraphFehler extends DomainFehler
{
    public function __construct(
        string $meldung,
        public readonly int $httpStatus = 0,
        private readonly bool $abgelaufen = false,
        private readonly ?int $warte = null,
    ) {
        parent::__construct($meldung);
    }

    /** Refresh-Token ungültig/widerrufen: der Verein muss neu verbinden. */
    public function istAbgelaufen(): bool
    {
        return $this->abgelaufen;
    }

    /** Vorübergehend (Netz, 429, 5xx): später erneut versuchen. */
    public function wiederholbar(): bool
    {
        return !$this->abgelaufen && ($this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500);
    }

    public function warteSekunden(): ?int
    {
        return $this->warte;
    }

    public static function ausAntwort(HttpAntwort $a, string $kontext): self
    {
        $warte = isset($a->kopf['retry-after']) && ctype_digit(trim($a->kopf['retry-after'])) ? (int) trim($a->kopf['retry-after']) : null;
        return new self($kontext . ' (HTTP ' . $a->status . ').', $a->status, false, $warte);
    }
}
