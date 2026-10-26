<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit\Engine;

use Durandal\Engine\Decision;
use Durandal\Engine\FaultEngine;
use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Fault\Timeout;
use Durandal\Http\RequestInfo;
use Durandal\Random\SeededRandomizer;
use Durandal\Rule\RequestMatcher;
use Durandal\Rule\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FaultEngine::class)]
#[CoversClass(Decision::class)]
final class FaultEngineTest extends TestCase
{
    public function testWithoutRulesNothingHappens(): void
    {
        $decision = self::engine([])->decide(self::request());

        self::assertSame(0, $decision->latencyMilliseconds);
        self::assertNull($decision->fault);
    }

    public function testLatenciesAddUp(): void
    {
        $decision = self::engine([new Rule(new Latency(100)), new Rule(new Latency(250))])->decide(self::request());

        self::assertSame(350, $decision->latencyMilliseconds);
        self::assertNull($decision->fault);
    }

    public function testFirstNonLatencyFaultWinsAndLatenciesStillApply(): void
    {
        $timeout = new Timeout();
        $decision = self::engine([
            new Rule(new Latency(100)),
            new Rule($timeout),
            new Rule(new ConnectionRefused()),
            new Rule(new Latency(50)),
        ])->decide(self::request());

        self::assertSame(150, $decision->latencyMilliseconds);
        self::assertSame($timeout, $decision->fault);
    }

    public function testRulesThatDoNotMatchAreIgnored(): void
    {
        $decision = self::engine([
            new Rule(new Latency(100), RequestMatcher::any()->host('elsewhere.com')),
            new Rule(new HttpError(), RequestMatcher::any()->method('POST')),
        ])->decide(self::request());

        self::assertSame(0, $decision->latencyMilliseconds);
        self::assertNull($decision->fault);
    }

    public function testRulesThatDoNotFireAreIgnored(): void
    {
        $fault = new HttpError(500);
        $decision = self::engine([new Rule(new Timeout(), probability: 0.0), new Rule($fault)])->decide(self::request());

        self::assertSame($fault, $decision->fault);
    }

    public function testProbabilisticRulesFollowTheSeed(): void
    {
        $outcomes = static function (int $seed): string {
            $engine = self::engine([new Rule(new HttpError(), probability: 0.5)], $seed);
            $sequence = '';
            for ($i = 0; $i < 32; ++$i) {
                $sequence .= $engine->decide(self::request())->fault === null ? '0' : '1';
            }

            return $sequence;
        };

        self::assertSame('10111000101001100101100001001001', $outcomes(42));
    }

    public function testRulesThatDoNotMatchConsumeNoDraw(): void
    {
        $probabilistic = new Rule(new HttpError(), probability: 0.5);
        $elsewhere = new Rule(new Timeout(), RequestMatcher::any()->host('elsewhere.com'), probability: 0.5);

        self::assertSame(
            self::sequence([$probabilistic]),
            self::sequence([$elsewhere, $probabilistic]),
        );
    }

    public function testRulesAfterAWinningFaultStillConsumeTheirDraw(): void
    {
        $latency = new Rule(new Latency(10), probability: 0.5);
        $alone = self::latencies([$latency]);
        $afterWinner = self::latencies([new Rule(new Timeout()), $latency]);
        $afterWinnerThenProbabilistic = self::latencies([new Rule(new Timeout(), probability: 0.5), $latency]);

        self::assertSame($alone, $afterWinner);
        self::assertNotSame($alone, $afterWinnerThenProbabilistic);
    }

    /**
     * Issues successives de 32 requêtes : « 1 » si une panne autre que la latence s'est déclenchée.
     *
     * @param list<Rule> $rules
     */
    private static function sequence(array $rules): string
    {
        $engine = self::engine($rules);
        $sequence = '';
        for ($i = 0; $i < 32; ++$i) {
            $sequence .= $engine->decide(self::request())->fault === null ? '0' : '1';
        }

        return $sequence;
    }

    /**
     * Latences successives de 32 requêtes.
     *
     * @param list<Rule> $rules
     *
     * @return list<int>
     */
    private static function latencies(array $rules): array
    {
        $engine = self::engine($rules);
        $latencies = [];
        for ($i = 0; $i < 32; ++$i) {
            $latencies[] = $engine->decide(self::request())->latencyMilliseconds;
        }

        return $latencies;
    }

    /**
     * @param list<Rule> $rules
     */
    private static function engine(array $rules, int $seed = 42): FaultEngine
    {
        return new FaultEngine($rules, new SeededRandomizer($seed));
    }

    private static function request(): RequestInfo
    {
        return new RequestInfo('GET', 'api.example.com', '/orders');
    }
}
