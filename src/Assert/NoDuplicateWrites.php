<?php

declare(strict_types=1);

namespace Durandal\Assert;

use Durandal\Journal\Entry;
use Durandal\Journal\Journal;

/**
 * Aucune écriture n'a pu être exécutée deux fois : chaque écriture non idempotente (POST, PATCH) envoyée
 * plusieurs fois porte, à chaque tentative, la même clé d'idempotence.
 *
 * Les tentatives d'une même écriture sont reconnues à leur méthode, leur URL et leur corps.
 */
final readonly class NoDuplicateWrites implements Expectation
{
    private const WRITES = ['POST', 'PATCH'];

    public function __construct(private string $idempotencyHeader = 'Idempotency-Key') {}

    public function describe(): string
    {
        return \sprintf('aucune écriture en double (en-tête %s)', $this->idempotencyHeader);
    }

    public function failure(Journal $journal): ?string
    {
        $writes = [];
        foreach ($journal as $entry) {
            if (\in_array($entry->request->method, self::WRITES, true)) {
                $writes[$entry->target() . "\0" . ($entry->body ?? '')][] = $entry;
            }
        }

        $problems = [];
        foreach ($writes as $attempts) {
            if (\count($attempts) < 2) {
                continue;
            }

            $keys = array_map(fn(Entry $entry): ?string => $entry->request->header($this->idempotencyHeader)[0] ?? null, $attempts);
            $numbers = implode(', ', array_map(static fn(Entry $entry): string => '#' . $entry->number, $attempts));

            if (\in_array(null, $keys, true)) {
                $problems[] = \sprintf('%s envoyée %d fois (%s) sans %s à chaque tentative', $attempts[0]->target(), \count($attempts), $numbers, $this->idempotencyHeader);
            } elseif (\count(array_unique($keys)) > 1) {
                $problems[] = \sprintf('%s envoyée %d fois (%s) avec des %s différentes', $attempts[0]->target(), \count($attempts), $numbers, $this->idempotencyHeader);
            }
        }

        return $problems === [] ? null : 'écriture(s) en double possible(s) : ' . implode(' ; ', $problems) . '.';
    }
}
