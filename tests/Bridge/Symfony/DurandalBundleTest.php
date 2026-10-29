<?php

declare(strict_types=1);

namespace Durandal\Tests\Bridge\Symfony;

use Durandal\Adapter\Symfony\SimulatedTimeout;
use Durandal\Bridge\PHPUnit\DurandalAssert;
use Durandal\Bridge\Symfony\ClientFactory;
use Durandal\Bridge\Symfony\DependencyInjection\InjectFaultsPass;
use Durandal\Bridge\Symfony\DurandalBundle;
use Durandal\Bridge\Symfony\Journals;
use Durandal\Exception\InvalidConfiguration;
use Durandal\Tests\Bridge\Symfony\Fixtures\FakeBackend;
use Durandal\Tests\Bridge\Symfony\Fixtures\TestKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(DurandalBundle::class)]
#[CoversClass(InjectFaultsPass::class)]
#[CoversClass(ClientFactory::class)]
#[CoversClass(Journals::class)]
final class DurandalBundleTest extends TestCase
{
    private ?TestKernel $kernel = null;

    private mixed $exceptionHandler = null;

    protected function tearDown(): void
    {
        if ($this->kernel !== null) {
            $this->kernel->shutdown();
            (new Filesystem())->remove($this->kernel->buildDir());
        }

        // Les premières versions de FrameworkBundle 6.4 installent un gestionnaire d'exceptions sans le retirer.
        for ($i = 0; $i < 5 && self::exceptionHandler() !== $this->exceptionHandler; ++$i) {
            restore_exception_handler();
        }
    }

    public function testAPlainProjectEnablesDurandalByConfigurationOnly(): void
    {
        $this->boot('project.yaml', 'durandal.yaml');

        $response = $this->client('packagist.client')->request('GET', '/p2/symfony/http-client.json');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['30'], $response->getHeaders(false)['retry-after'] ?? null);
        self::assertSame([], $this->backend()->requests);
    }

    public function testRulesOnlyTargetTheirRequests(): void
    {
        $this->boot('project.yaml', 'durandal.yaml');

        $response = $this->client('packagist.client')->request('GET', '/packages/list.json');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['GET https://repo.packagist.org/packages/list.json'], $this->backend()->requests);
    }

    public function testClientsWithoutRulesAreLeftUntouched(): void
    {
        $this->boot('project.yaml', 'durandal.yaml');

        $response = $this->client('http_client')->request('GET', 'https://repo.packagist.org/p2/symfony/http-client.json');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->backend()->requests);
    }

    public function testSymfonyRetriesApplyToSimulatedFaults(): void
    {
        $this->boot('project.yaml', 'durandal.yaml');

        // Graine 42, probabilité 0,5 : la première tentative échoue, la deuxième passe.
        $response = $this->client('github.client')->request('GET', '/repos/symfony/symfony');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $response->getInfo('retry_count'));
        self::assertSame(['GET https://api.github.com/repos/symfony/symfony'], $this->backend()->requests);
    }

    public function testTheJournalOfEachClientIsAvailable(): void
    {
        $this->boot('project.yaml', 'durandal.yaml');
        $this->client('github.client')->request('GET', '/repos/symfony/symfony')->getContent();

        $journal = $this->journals()->of('github.client');

        DurandalAssert::retriedExactly(1, $journal);
        self::assertSame(42, $journal->seed);
        self::assertSame('GET https://api.github.com/repos/symfony/symfony', $journal->entries()[0]->target());
        self::assertCount(0, $this->journals()->of('packagist.client'));
    }

    public function testAskingForTheJournalOfAnUndecoratedClientIsAnError(): void
    {
        $this->boot('project.yaml', 'durandal.yaml');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Le client HTTP « http_client » n\'est pas décoré par Durandal (clients configurés : packagist.client, github.client, app.custom_client).');

        $this->journals()->of('http_client');
    }

    public function testWithoutConfiguredClientsNoJournalIsAvailable(): void
    {
        $this->boot('project.yaml');

        $this->expectExceptionMessage('(clients configurés : aucun)');

        $this->journals()->of('packagist.client');
    }

    public function testCustomClientServicesAreDecorated(): void
    {
        $this->boot('project.yaml', 'durandal.yaml');
        $client = $this->client('app.custom_client');

        self::assertSame(200, $client->request('POST', '/orders')->getStatusCode());

        try {
            $client->request('POST', '/orders', ['headers' => ['Idempotency-Key' => 'k-1']])->getStatusCode();
            self::fail('Le délai dépassé simulé aurait dû être levé.');
        } catch (TransportExceptionInterface $e) {
            self::assertInstanceOf(SimulatedTimeout::class, $e->getPrevious());
        }
    }

    public function testWithoutConfiguredClientsNothingIsDecorated(): void
    {
        $this->boot('project.yaml');

        self::assertSame(200, $this->client('packagist.client')->request('GET', '/p2/symfony/http-client.json')->getStatusCode());
    }

    public function testDurandalRefusesToRunInProduction(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('jamais être activé en production');

        $this->bootIn('prod', ['project.yaml', 'durandal.yaml']);
    }

    public function testUnknownClientsAreReported(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Le client HTTP « missing.client » configuré dans durandal.clients n\'existe pas.');

        $this->boot('project.yaml', 'invalid/unknown_client.yaml');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'latence sans durée' => ['latency_without_ms.yaml', 'durandal.clients.packagist.client : Règle n° 2 (latency) : le paramètre « ms » est obligatoire.'];
        yield 'paramètre étranger à la panne' => ['foreign_parameter.yaml', 'durandal.clients.packagist.client : Règle n° 1 (timeout) : le paramètre « status » ne s\'applique pas à cette panne.'];
        yield 'statut hors des erreurs HTTP' => ['bad_status.yaml', 'durandal.clients.packagist.client : Règle n° 1 (http_error) : Le statut doit être une erreur HTTP (400 à 599), 200 reçu.'];
    }

    #[DataProvider('invalidRules')]
    public function testInvalidRulesAreReportedWithTheirLocation(string $file, string $message): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($message);

        $this->boot('project.yaml', 'invalid/' . $file);
    }

    public function testEveryFaultCanBeConfigured(): void
    {
        $this->boot('project.yaml', 'all_faults.yaml');
        $client = $this->client('http_client');

        self::assertSame('{"ok', $client->request('GET', 'https://api.example.com/truncated')->getContent());
        self::assertSame('<html>', $client->request('GET', 'https://api.example.com/malformed')->getContent());
        self::assertSame(418, $client->request('GET', 'https://api.example.com/teapot')->getStatusCode());

        try {
            $client->request('GET', 'https://api.example.com/refused')->getStatusCode();
            self::fail('Le refus de connexion simulé aurait dû être levé.');
        } catch (TransportExceptionInterface $e) {
            self::assertStringContainsString('refus de connexion', $e->getMessage());
        }
    }

    private function boot(string ...$configFiles): void
    {
        $this->bootIn('test', array_values($configFiles));
    }

    /**
     * @param list<string> $configFiles
     */
    private function bootIn(string $environment, array $configFiles): void
    {
        $this->exceptionHandler = self::exceptionHandler();
        $this->kernel = new TestKernel($environment, $configFiles);
        $this->kernel->boot();
    }

    private static function exceptionHandler(): mixed
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    private function client(string $id): HttpClientInterface
    {
        $client = $this->container()->get($id);
        self::assertInstanceOf(HttpClientInterface::class, $client);

        return $client;
    }

    private function journals(): Journals
    {
        $journals = $this->container()->get(Journals::class);
        self::assertInstanceOf(Journals::class, $journals);

        return $journals;
    }

    private function backend(): FakeBackend
    {
        $backend = $this->container()->get(FakeBackend::class);
        self::assertInstanceOf(FakeBackend::class, $backend);

        return $backend;
    }

    private function container(): ContainerInterface
    {
        self::assertNotNull($this->kernel);

        return $this->kernel->getContainer();
    }
}
