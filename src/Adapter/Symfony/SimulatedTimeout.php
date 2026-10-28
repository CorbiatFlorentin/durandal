<?php

declare(strict_types=1);

namespace Durandal\Adapter\Symfony;

use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

final class SimulatedTimeout extends SimulatedTransportException implements TimeoutExceptionInterface
{
    protected static function label(): string
    {
        return 'délai dépassé';
    }
}
