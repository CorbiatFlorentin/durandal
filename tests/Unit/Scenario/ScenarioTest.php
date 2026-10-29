<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit\Scenario;

use DateTimeImmutable;
use Durandal\Clock\SystemClock;
use Durandal\Engine\FaultEngine;
use Durandal\Engine\ScenarioRun;
use Durandal\Exception\InvalidConfiguration;
use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\Fault;
use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Fault\Timeout;
use Durandal\Http\RequestInfo;
use Durandal\Random\SeededRandomizer;
use Durandal\Rule\RequestMatcher;
use Durandal\Rule\Rule;
use Durandal\Scenario\Scenario;
use Durandal\Scenario\Step;
use Durandal\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Scenario::class)]
#[CoversClass(Step::class)]
#[CoversClass(ScenarioRun::class)]
#[CoversClass(FaultEngine::class)]
#[CoversClass(SystemClock::class)]
final class ScenarioTest extends TestCase
{
    public function testFailTimesThenSucceed(): void
    {
        $outcomes = self::play(Scenario::failTimes(2, status: 502, retryAfterSeconds: 7)->thenSucceed(), 4);

        self::assertSame(['502+7', '502+7', 'ok', 'ok'], $outcomes);
    }

    public function testStepsChainInOrder(): void
    {
        $scenario = Scenario::succeedTimes(1)
            ->then(Scenario::timeoutTimes(2))
            ->then(Scenario::faultTimes(1, new ConnectionRefused()))
            ->then(Scenario::failTimes(1));

        self::assertSame(['ok', 'timeout', 'timeout', 'refused', '503', 'ok'], self::play($scenario, 6));
    }

    public function testSlowThenFastAddsLatencyToTheFirstRequestsOnly(): void
    {
        $engine = new FaultEngine([], new SeededRandomizer(1), [Scenario::slowThenFast(2, 800)]);

        $latencies = [];
        for ($i = 0; $i < 3; ++$i) {
            $latencies[] = $engine->decide(self::request())->latencyMilliseconds;
        }

        self::assertSame([800, 800, 0], $latencies);
    }

    public function testOfflineForLastsFromTheFirstTargetedRequest(): void
    {
        $clock = new FrozenClock();
        $engine = new FaultEngine([], new SeededRandomizer(1), [Scenario::succeedTimes(1)->then(Scenario::offlineFor(5))], $clock);

        $outcomes = [self::outcome($engine->decide(self::request())->fault)];
        $clock->advance(60_000);
        foreach ([0, 4_999, 1] as $step) {
            $clock->advance($step);
            $outcomes[] = self::outcome($engine->decide(self::request())->fault);
        }

        self::assertSame(['ok', 'refused', 'refused', 'ok'], $outcomes);
    }

    public function testOnlyTargetedRequestsAdvanceTheScenario(): void
    {
        $engine = new FaultEngine([], new SeededRandomizer(1), [Scenario::failTimes(1)->forRequests(RequestMatcher::any()->method('POST'))]);

        self::assertNull($engine->decide(self::request('GET'))->fault);
        self::assertInstanceOf(HttpError::class, $engine->decide(self::request('POST'))->fault);
        self::assertNull($engine->decide(self::request('POST'))->fault);
    }

    public function testThenKeepsTheFirstScenarioFilter(): void
    {
        $scenario = Scenario::failTimes(1)->forRequests(RequestMatcher::any()->method('POST'))
            ->then(Scenario::timeoutTimes(1)->forRequests(RequestMatcher::any()->method('GET')));

        self::assertTrue($scenario->matcher()->matches(self::request('POST')));
        self::assertFalse($scenario->matcher()->matches(self::request('GET')));
        self::assertCount(2, $scenario->steps());
    }

    public function testScenariosPlayBeforeRulesAndLatenciesStillAddUp(): void
    {
        $engine = new FaultEngine(
            [new Rule(new Latency(100)), new Rule(new HttpError(500))],
            new SeededRandomizer(1),
            [Scenario::slowThenFast(1, 50), Scenario::timeoutTimes(1)],
        );

        $first = $engine->decide(self::request());
        $second = $engine->decide(self::request());

        self::assertSame(150, $first->latencyMilliseconds);
        self::assertInstanceOf(Timeout::class, $first->fault);
        self::assertSame(100, $second->latencyMilliseconds);
        self::assertSame('500', self::outcome($second->fault));
    }

    public function testTheDefaultClockIsTheSystemClock(): void
    {
        $before = new DateTimeImmutable();
        $now = (new SystemClock())->now();

        self::assertGreaterThanOrEqual($before, $now);
    }

    public function testStepsNeedAtLeastOneRequestOrOneMillisecond(): void
    {
        foreach ([static fn() => Scenario::failTimes(0), static fn() => Step::forDuration(0, null)] as $invalid) {
            try {
                $invalid();
                self::fail('L\'étape vide aurait dû être refusée.');
            } catch (InvalidConfiguration $e) {
                self::assertStringContainsString('au moins', $e->getMessage());
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function play(Scenario $scenario, int $requests): array
    {
        $engine = new FaultEngine([], new SeededRandomizer(1), [$scenario]);

        $outcomes = [];
        for ($i = 0; $i < $requests; ++$i) {
            $outcomes[] = self::outcome($engine->decide(self::request())->fault);
        }

        return $outcomes;
    }

    private static function outcome(?Fault $fault): string
    {
        return match (true) {
            $fault === null => 'ok',
            $fault instanceof HttpError => $fault->status . ($fault->retryAfterSeconds === null ? '' : '+' . $fault->retryAfterSeconds),
            $fault instanceof Timeout => 'timeout',
            $fault instanceof ConnectionRefused => 'refused',
            default => $fault::class,
        };
    }

    private static function request(string $method = 'GET'): RequestInfo
    {
        return new RequestInfo($method, 'api.example.com', '/orders');
    }
}
