<?php

declare(strict_types=1);

namespace Durandal\Bridge\PHPUnit;

use Durandal\Assert\Expectation;
use Durandal\Journal\Journal;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\ExpectationFailedException;
use SebastianBergmann\Comparator\ComparisonFailure;

/**
 * Contrainte PHPUnit : le journal satisfait une attente de résilience. En cas d'échec, le message
 * donne la raison, la graine et le journal complet.
 */
final class JournalSatisfies extends Constraint
{
    public function __construct(private readonly Expectation $expectation) {}

    public function toString(): string
    {
        return 'satisfait « ' . $this->expectation->describe() . ' »';
    }

    protected function matches(mixed $other): bool
    {
        return $other instanceof Journal && $this->expectation->failure($other) === null;
    }

    protected function fail(mixed $other, string $description, ?ComparisonFailure $comparisonFailure = null): never
    {
        $reason = $other instanceof Journal
            ? \sprintf("Durandal : %s\n%s", $this->expectation->failure($other) ?? '', $other->describe())
            : \sprintf('Durandal : un journal (Durandal\Journal\Journal) est attendu, %s reçu.', get_debug_type($other));

        throw new ExpectationFailedException(ltrim($description . "\n" . $reason));
    }
}
