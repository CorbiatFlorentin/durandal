<?php

declare(strict_types=1);

namespace Durandal\Bridge\Symfony;

use Durandal\Bridge\Symfony\DependencyInjection\InjectFaultsPass;
use Durandal\Exception\InvalidConfiguration;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Active Durandal par configuration. À n'enregistrer que pour l'environnement de test :
 *
 *     Durandal\Bridge\Symfony\DurandalBundle::class => ['test' => true],
 */
final class DurandalBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->integerNode('seed')
                    ->info('Graine des tirages aléatoires ; tirée au hasard si absente.')
                    ->defaultNull()
                ->end()
                ->arrayNode('clients')
                    ->info('Clients HTTP à décorer, par identifiant de service (http_client, un client de scoped_clients…).')
                    ->useAttributeAsKey('id')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('rules')
                                ->arrayPrototype()
                                    ->children()
                                        ->enumNode('fault')
                                            ->values(array_keys(ClientFactory::FAULTS))
                                            ->isRequired()
                                        ->end()
                                        ->arrayNode('match')
                                            ->addDefaultsIfNotSet()
                                            ->children()
                                                ->arrayNode('method')
                                                    ->beforeNormalization()->castToArray()->end()
                                                    ->scalarPrototype()->end()
                                                ->end()
                                                ->scalarNode('host')->defaultNull()->end()
                                                ->scalarNode('path')->defaultNull()->end()
                                                ->arrayNode('headers')
                                                    ->info('Nom => valeur attendue, ou ~ pour exiger la seule présence de l\'en-tête.')
                                                    ->useAttributeAsKey('name')
                                                    ->normalizeKeys(false)
                                                    ->scalarPrototype()->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                        ->floatNode('probability')->defaultValue(1.0)->end()
                                        ->integerNode('ms')->defaultNull()->end()
                                        ->integerNode('status')->defaultNull()->end()
                                        ->integerNode('retry_after')->defaultNull()->end()
                                        ->floatNode('keep_ratio')->defaultNull()->end()
                                        ->scalarNode('body')->defaultNull()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->getParameter('kernel.environment') === 'prod') {
            throw new InvalidConfiguration('Durandal injecte des pannes : il ne doit jamais être activé en production.');
        }

        $rulesByClient = [];
        foreach (\is_array($config['clients'] ?? null) ? $config['clients'] : [] as $id => $client) {
            $rules = \is_array($client) ? $client['rules'] ?? [] : [];
            try {
                // Valide les règles dès la compilation du conteneur plutôt qu'à la première requête.
                ClientFactory::rules($rules);
            } catch (InvalidConfiguration $e) {
                throw new InvalidConfiguration(\sprintf('durandal.clients.%s : %s', $id, $e->getMessage()), previous: $e);
            }
            $rulesByClient[(string) $id] = $rules;
        }

        $builder->setParameter(InjectFaultsPass::CLIENTS_PARAMETER, $rulesByClient);
        $builder->setParameter(InjectFaultsPass::SEED_PARAMETER, \is_int($config['seed'] ?? null) ? $config['seed'] : null);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new InjectFaultsPass());
    }
}
