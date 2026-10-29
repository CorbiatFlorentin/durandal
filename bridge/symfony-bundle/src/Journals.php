<?php

declare(strict_types=1);

namespace Durandal\Bridge\Symfony;

use Durandal\Adapter\Symfony\DurandalHttpClient;
use Durandal\Exception\InvalidConfiguration;
use Durandal\Journal\Journal;
use Psr\Container\ContainerInterface;

/**
 * Journaux des clients décorés par le bundle, par identifiant de service :
 *
 *     static::getContainer()->get(Journals::class)->of('packagist.client')
 */
final class Journals
{
    /**
     * @param list<string> $clients identifiants des clients décorés
     */
    public function __construct(
        private readonly ContainerInterface $locator,
        private readonly array $clients,
    ) {}

    public function of(string $client): Journal
    {
        $durandal = $this->locator->has($client) ? $this->locator->get($client) : null;
        if (!$durandal instanceof DurandalHttpClient) {
            throw new InvalidConfiguration(\sprintf(
                'Le client HTTP « %s » n\'est pas décoré par Durandal (clients configurés : %s).',
                $client,
                $this->clients === [] ? 'aucun' : implode(', ', $this->clients),
            ));
        }

        return $durandal->journal();
    }
}
