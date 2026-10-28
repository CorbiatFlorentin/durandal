<?php

declare(strict_types=1);

namespace Durandal\Adapter\Symfony;

final class SimulatedConnectionRefused extends SimulatedTransportException
{
    protected static function label(): string
    {
        return 'refus de connexion';
    }
}
