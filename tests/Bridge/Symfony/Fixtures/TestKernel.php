<?php

declare(strict_types=1);

namespace Durandal\Tests\Bridge\Symfony\Fixtures;

use Composer\InstalledVersions;
use Durandal\Bridge\Symfony\DurandalBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Projet Symfony minimal : FrameworkBundle, DurandalBundle et un fichier de configuration.
 */
final class TestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /** Services rendus publics pour que les tests puissent les récupérer. */
    public const CLIENTS = ['http_client', 'packagist.client', 'github.client', 'app.custom_client'];

    private readonly string $buildDir;

    /**
     * @param list<string> $configFiles
     */
    public function __construct(string $environment, private readonly array $configFiles)
    {
        parent::__construct($environment, false);
        $this->buildDir = sys_get_temp_dir() . '/durandal-tests/' . bin2hex(random_bytes(6));
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DurandalBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->buildDir . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->buildDir . '/log';
    }

    public function buildDir(): string
    {
        return $this->buildDir;
    }

    public function process(ContainerBuilder $container): void
    {
        foreach (self::CLIENTS as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            }
        }
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $framework = ['secret' => 'durandal', 'test' => true];
        if (version_compare(InstalledVersions::getVersion('symfony/framework-bundle') ?? '0', '7.0', '<')) {
            // Valeurs par défaut de Symfony 7, pour éviter les avis de dépréciation de Symfony 6.4.
            $framework += ['http_method_override' => false, 'handle_all_throwables' => true, 'php_errors' => ['log' => true]];
        }
        $container->extension('framework', $framework);

        foreach ($this->configFiles as $file) {
            $container->import(__DIR__ . '/config/' . $file);
        }
    }
}
