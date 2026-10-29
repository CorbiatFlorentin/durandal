<?php

declare(strict_types=1);

namespace Durandal\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Horloge réelle, utilisée par défaut. Pour des tests déterministes, injectez une horloge figée
 * (MockClock de symfony/clock, par exemple) avec withClock().
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
