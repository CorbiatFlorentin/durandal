<?php

declare(strict_types=1);

namespace Durandal\Adapter\Psr18;

final class SimulatedTimeout extends SimulatedNetworkException
{
    protected static function label(): string
    {
        return 'délai dépassé';
    }
}
