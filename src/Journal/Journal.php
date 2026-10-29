<?php

declare(strict_types=1);

namespace Durandal\Journal;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use Durandal\Engine\Decision;
use Durandal\Http\RequestInfo;
use Durandal\Rule\RequestMatcher;
use IteratorAggregate;
use Traversable;

/**
 * Journal des requêtes d'un client Durandal, dans l'ordre où elles ont été envoyées.
 *
 * @implements IteratorAggregate<int, Entry>
 */
final class Journal implements Countable, IteratorAggregate
{
    /** @var list<Entry> */
    private array $entries = [];

    public function __construct(public readonly int $seed)
    {
        ActiveJournals::track($this);
    }

    /**
     * @internal appelé par les adaptateurs
     */
    public function record(DateTimeImmutable $at, string $url, RequestInfo $request, ?string $body, Decision $decision): void
    {
        $this->entries[] = new Entry(\count($this->entries) + 1, $at, $url, $request, $body, $decision->latencyMilliseconds, $decision->fault);
    }

    /**
     * @return list<Entry>
     */
    public function entries(?RequestMatcher $match = null): array
    {
        if ($match === null) {
            return $this->entries;
        }

        return array_values(array_filter($this->entries, static fn(Entry $entry): bool => $match->matches($entry->request)));
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * @return Traversable<int, Entry>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entries);
    }

    /**
     * Graine et requêtes, une par ligne : de quoi comprendre et rejouer un échec.
     */
    public function describe(): string
    {
        $lines = [\sprintf('Graine %d (rejouer : DURANDAL_SEED=%d)', $this->seed, $this->seed)];
        foreach ($this->entries as $entry) {
            $lines[] = $entry->describe();
        }
        if ($this->entries === []) {
            $lines[] = '(aucune requête)';
        }

        return implode("\n", $lines);
    }
}
