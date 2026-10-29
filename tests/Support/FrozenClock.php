<?php

declare(strict_types=1);

namespace Durandal\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Horloge arrêtée, qu'un test fait avancer à la main.
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-01-01 10:00:00.000')
    {
        $this->now = new DateTimeImmutable($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $milliseconds): void
    {
        $this->now = $this->now->modify(\sprintf('+%d milliseconds', $milliseconds));
    }
}
