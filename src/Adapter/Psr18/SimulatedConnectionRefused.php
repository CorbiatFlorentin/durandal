<?php

declare(strict_types=1);

namespace Durandal\Adapter\Psr18;

final class SimulatedConnectionRefused extends SimulatedNetworkException
{
    protected static function label(): string
    {
        return 'refus de connexion';
    }
}
