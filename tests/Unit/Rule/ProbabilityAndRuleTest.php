<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit\Rule;

use Durandal\Exception\InvalidConfiguration;
use Durandal\Fault\Timeout;
use Durandal\Http\RequestInfo;
use Durandal\Rule\Probability;
use Durandal\Rule\RequestMatcher;
use Durandal\Rule\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Probability::class)]
#[CoversClass(Rule::class)]
final class ProbabilityAndRuleTest extends TestCase
{
    public function testBoundsAreAccepted(): void
    {
        self::assertTrue((new Probability(0.0))->isImpossible());
        self::assertFalse((new Probability(0.0))->isCertain());
        self::assertTrue((new Probability(1.0))->isCertain());
        self::assertFalse((new Probability(1.0))->isImpossible());
        self::assertTrue(Probability::always()->isCertain());
    }

    public function testIntermediateValueIsNeitherCertainNorImpossible(): void
    {
        $probability = new Probability(0.3);

        self::assertSame(0.3, $probability->value);
        self::assertFalse($probability->isCertain());
        self::assertFalse($probability->isImpossible());
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function outOfRange(): iterable
    {
        yield 'négative' => [-0.01];
        yield 'supérieure à 1' => [1.01];
        yield 'NaN' => [\NAN];
    }

    #[DataProvider('outOfRange')]
    public function testOutOfRangeValuesAreRejected(float $value): void
    {
        $this->expectException(InvalidConfiguration::class);

        new Probability($value);
    }

    public function testRuleDefaultsToEveryRequestAndCertainty(): void
    {
        $rule = new Rule(new Timeout());

        self::assertInstanceOf(Timeout::class, $rule->fault);
        self::assertTrue($rule->match->matches(new RequestInfo('PATCH', 'any.host', '/any')));
        self::assertTrue($rule->probability->isCertain());
    }

    public function testRuleAcceptsAFloatOrAProbability(): void
    {
        $matcher = RequestMatcher::any()->method('POST');

        self::assertSame(0.25, (new Rule(new Timeout(), $matcher, 0.25))->probability->value);
        self::assertSame(0.5, (new Rule(new Timeout(), probability: new Probability(0.5)))->probability->value);
        self::assertSame($matcher, (new Rule(new Timeout(), $matcher))->match);
    }
}
