<?php

declare(strict_types=1);

namespace Durandal\Tests\Unit\Random;

use Durandal\Random\SeededRandomizer;
use Durandal\Rule\Probability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeededRandomizer::class)]
final class SeededRandomizerTest extends TestCase
{
    /**
     * Séquences de référence : elles ne doivent jamais changer d'une version de PHP ou de Durandal à l'autre,
     * sinon une graine affichée lors d'un échec ne permettrait plus de le rejouer.
     */
    public function testSequencesAreStableAcrossVersions(): void
    {
        self::assertSame('10111000101001100101100001001001', self::draws(new SeededRandomizer(42), 0.5, 32));
        self::assertSame('01001010111010111000100001111110', self::draws(new SeededRandomizer(7), 0.5, 32));
    }

    public function testSameSeedGivesSameSequenceOverAThousandDraws(): void
    {
        self::assertSame(
            self::draws(new SeededRandomizer(1234), 0.3, 1000),
            self::draws(new SeededRandomizer(1234), 0.3, 1000),
        );
    }

    public function testDifferentSeedsGiveDifferentSequences(): void
    {
        self::assertNotSame(
            self::draws(new SeededRandomizer(1234), 0.3, 1000),
            self::draws(new SeededRandomizer(1235), 0.3, 1000),
        );
    }

    public function testFrequencyFollowsTheProbability(): void
    {
        $hits = substr_count(self::draws(new SeededRandomizer(99), 0.2, 10_000), '1');

        self::assertGreaterThan(1_800, $hits);
        self::assertLessThan(2_200, $hits);
    }

    public function testCertainAndImpossibleOutcomesDoNotConsumeDraws(): void
    {
        $withExtraRules = new SeededRandomizer(42);
        $sequence = '';
        for ($i = 0; $i < 32; ++$i) {
            self::assertFalse($withExtraRules->chance(new Probability(0.0)));
            self::assertTrue($withExtraRules->chance(Probability::always()));
            $sequence .= $withExtraRules->chance(new Probability(0.5)) ? '1' : '0';
        }

        self::assertSame(self::draws(new SeededRandomizer(42), 0.5, 32), $sequence);
    }

    public function testExposesItsSeed(): void
    {
        self::assertSame(42, (new SeededRandomizer(42))->seed);
    }

    private static function draws(SeededRandomizer $random, float $probability, int $count): string
    {
        $chance = new Probability($probability);
        $sequence = '';
        for ($i = 0; $i < $count; ++$i) {
            $sequence .= $random->chance($chance) ? '1' : '0';
        }

        return $sequence;
    }
}
