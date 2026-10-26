<?php

declare(strict_types=1);

namespace Durandal\Clock;

/**
 * N'attend pas : la latence est simulée sans ralentir les tests.
 */
final class NullSleeper implements Sleeper
{
    public function sleep(int $milliseconds): void {}
}
