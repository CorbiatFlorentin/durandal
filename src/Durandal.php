<?php

declare(strict_types=1);

namespace Durandal;

use Durandal\Adapter\Psr18\DurandalClient;
use Durandal\Adapter\Settings;
use Durandal\Adapter\Symfony\DurandalHttpClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
     * Enveloppe un client PSR-18. Sans graine explicite (withSeed()), la graine vient de la variable
     * d'environnement DURANDAL_SEED ou est tirée au hasard ; elle reste lisible via seed() pour rejouer un échec.
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
            new Settings(self::defaultSeed()),
        );
    }

    /**
     * Enveloppe un client Symfony HttpClient (nécessite symfony/http-client). La graine se choisit comme pour wrap().
     */
    public static function wrapSymfony(HttpClientInterface $client): DurandalHttpClient
    {
        return new DurandalHttpClient($client, new Settings(self::defaultSeed()));
    }

    /**
     * La graine imposée par DURANDAL_SEED pour rejouer un échec, sinon une graine aléatoire.
     *
     * @internal
     */
    public static function defaultSeed(): int
    {
        $forced = getenv('DURANDAL_SEED');
        if (\is_string($forced) && preg_match('/^\d+$/D', $forced) === 1) {
            return (int) $forced;
        }

        return random_int(0, \PHP_INT_MAX);
    }
}
