<?php

declare(strict_types=1);

namespace Emc\Mail;

/** Versand gescheitert. Die Meldung enthält nie Kennwort, Token, Adressen oder Antworttexte des Servers. */
final class MailFehler extends \RuntimeException
{
}
