<?php

declare(strict_types=1);

namespace Emc\Mail;

interface Mailer
{
    /** @throws MailFehler wenn die Nachricht nicht zugestellt werden konnte (Meldung nie mit Kennwort oder Token) */
    public function sende(Nachricht $n): void;
}
