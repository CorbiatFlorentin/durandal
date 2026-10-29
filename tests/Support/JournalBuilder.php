<?php

declare(strict_types=1);

namespace Durandal\Tests\Support;

use Durandal\Engine\Decision;
use Durandal\Fault\Fault;
use Durandal\Http\RequestInfo;
use Durandal\Journal\Journal;

/**
 * Remplit un journal à la main, requête par requête, pour tester les attentes sans client HTTP.
 */
final class JournalBuilder
{
    private readonly FrozenClock $clock;

    public readonly Journal $journal;

    public function __construct(int $seed = 42)
    {
        $this->clock = new FrozenClock();
        $this->journal = new Journal($seed);
    }

    /**
     * @param array<string, string> $headers
     */
    public function request(
        string $method,
        string $path,
        ?Fault $fault = null,
        array $headers = [],
        ?string $body = null,
        int $after = 0,
        int $latency = 0,
    ): self {
        $this->clock->advance($after);
        $this->journal->record(
            $this->clock->now(),
            'https://api.example.com' . $path,
            new RequestInfo($method, 'api.example.com', $path, array_map(static fn(string $value): array => [$value], $headers)),
            $body,
            new Decision($latency, $fault),
        );

        return $this;
    }
}
