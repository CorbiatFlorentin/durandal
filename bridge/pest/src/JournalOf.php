<?php

declare(strict_types=1);

namespace Durandal\Bridge\Pest;

use Durandal\Adapter\Psr18\DurandalClient;
use Durandal\Adapter\Symfony\DurandalHttpClient;
use Durandal\Journal\Journal;
use InvalidArgumentException;

/**
 * Les attentes Pest acceptent un journal ou directement un client Durandal.
 *
 * @internal
 */
final class JournalOf
{
    public static function value(mixed $value): Journal
    {
        return match (true) {
            $value instanceof Journal => $value,
            $value instanceof DurandalClient, $value instanceof DurandalHttpClient => $value->journal(),
            default => throw new InvalidArgumentException(\sprintf(
                'Durandal : un journal ou un client Durandal est attendu, %s reçu.',
                get_debug_type($value),
            )),
        };
    }
}
