<?php

declare(strict_types=1);

namespace Durandal\Adapter\Psr18;

use Durandal\Exception\DurandalException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Erreur réseau simulée par Durandal, conforme à PSR-18 : le code testé la reçoit comme une vraie panne.
 */
abstract class SimulatedNetworkException extends RuntimeException implements NetworkExceptionInterface, DurandalException
{
    final public function __construct(private readonly RequestInterface $request, string $message)
    {
        parent::__construct($message);
    }

    public static function for(RequestInterface $request): static
    {
        return new static($request, \sprintf(
            'Durandal : %s simulé(e) pour %s %s',
            static::label(),
            $request->getMethod(),
            (string) $request->getUri(),
        ));
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    abstract protected static function label(): string;
}
