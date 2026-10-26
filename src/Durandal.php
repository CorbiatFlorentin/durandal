<?php

declare(strict_types=1);

namespace Durandal;

use Durandal\Adapter\Psr18\DurandalClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Point d'entrée de la bibliothèque.
 */
final class Durandal
{
    public const VERSION = '0.1.0-dev';

    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * Enveloppe un client PSR-18. Sans graine explicite (withSeed()), une graine aléatoire est tirée
     * et reste lisible via seed() pour rejouer un échec.
     *
     * Les fabriques PSR-17 sont découvertes automatiquement si elles ne sont pas fournies.
     */
    public static function wrap(
        ClientInterface $client,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): DurandalClient {
        return new DurandalClient(
            $client,
            $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory(),
            $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
            random_int(0, \PHP_INT_MAX),
        );
    }
}
