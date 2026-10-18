<?php

declare(strict_types=1);

namespace Emc\Auth;

enum AnmeldeErgebnis
{
    case Erfolg;
    case Falsch;
    case Gesperrt;
    case Inaktiv;
}
