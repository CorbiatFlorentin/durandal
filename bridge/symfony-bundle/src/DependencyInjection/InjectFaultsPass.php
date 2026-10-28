<?php

declare(strict_types=1);

namespace Durandal\Bridge\Symfony\DependencyInjection;

use Durandal\Adapter\Symfony\DurandalHttpClient;
use Durandal\Bridge\Symfony\ClientFactory;
use Durandal\Exception\InvalidConfiguration;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Insère Durandal dans chaque client HTTP configuré.
 *
 * Pour un client déclaré par FrameworkBundle (http_client ou scoped_clients), Durandal remplace le transport
 * sous le client : il voit les URL absolues et les en-têtes par défaut, et les réessais configurés par
 * retry_failed portent sur les pannes simulées. Pour tout autre service, Durandal le décore simplement.
 *
 * @internal
 */
final class InjectFaultsPass implements CompilerPassInterface
{
    public const CLIENTS_PARAMETER = '.durandal.clients';
    public const SEED_PARAMETER = '.durandal.seed';

    private const TRANSPORT = 'http_client.transport';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::CLIENTS_PARAMETER)) {
            return;
        }

        $clients = $container->getParameter(self::CLIENTS_PARAMETER);
        $seed = $container->getParameter(self::SEED_PARAMETER);

        foreach (\is_array($clients) ? $clients : [] as $id => $rules) {
            $id = (string) $id;
            if (!$container->has($id)) {
                throw new InvalidConfiguration(\sprintf('Le client HTTP « %s » configuré dans durandal.clients n\'existe pas.', $id));
            }

            $durandalId = 'durandal.client.' . $id;
            $durandal = $container->register($durandalId, DurandalHttpClient::class)
                ->setFactory([ClientFactory::class, 'create'])
                ->setArguments([new Reference(self::TRANSPORT), $rules, $seed]);

            $client = $container->findDefinition($id);
            $replaced = false;
            $client->setArguments(self::replaceTransport($client->getArguments(), new Reference($durandalId), $replaced));

            if (!$replaced) {
                $durandal->setDecoratedService($id)->replaceArgument(0, new Reference('.inner'));
            }
        }
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array<mixed>
     */
    private static function replaceTransport(array $arguments, Reference $durandal, bool &$replaced): array
    {
        foreach ($arguments as $key => $argument) {
            if ($argument instanceof Reference && (string) $argument === self::TRANSPORT) {
                $arguments[$key] = $durandal;
                $replaced = true;
            } elseif (\is_array($argument)) {
                $arguments[$key] = self::replaceTransport($argument, $durandal, $replaced);
            } elseif ($argument instanceof Definition) {
                $argument->setArguments(self::replaceTransport($argument->getArguments(), $durandal, $replaced));
            }
        }

        return $arguments;
    }
}
