<?php

declare(strict_types=1);

namespace Durandal\Adapter\Symfony;

use Durandal\Exception\DurandalException;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Erreur de transport simulée par Durandal.
 *
 * Symfony HttpClient enveloppe toute erreur de transport : le code testé reçoit une TransportExceptionInterface
 * dont la cause (getPrevious()) est cette exception.
 */
abstract class SimulatedTransportException extends RuntimeException implements TransportExceptionInterface, DurandalException
{
    final public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function for(string $method, string $url): static
    {
        return new static(\sprintf('Durandal : %s simulé(e) pour %s %s', static::label(), $method, $url));
    }

    abstract protected static function label(): string;
}
