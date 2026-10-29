<?php

declare(strict_types=1);

namespace Durandal\Tests\Contract;

use Durandal\Adapter\Settings;
use Durandal\Adapter\Symfony\DurandalHttpClient;
use Durandal\Adapter\Symfony\FailingResponse;
use Durandal\Adapter\Symfony\FaultTransport;
use Durandal\Adapter\Symfony\SimulatedConnectionRefused;
use Durandal\Adapter\Symfony\SimulatedTimeout;
use Durandal\Clock\Sleeper;
use Durandal\Durandal;
use Durandal\Journal\Journal;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Suite de contrat exécutée sur l'adaptateur Symfony HttpClient, autour d'un MockHttpClient qui joue le faux serveur.
 */
#[CoversClass(DurandalHttpClient::class)]
#[CoversClass(Settings::class)]
#[CoversClass(FaultTransport::class)]
#[CoversClass(FailingResponse::class)]
final class SymfonyHttpClientContractTest extends AdapterContractTestCase
{
    private int $calls = 0;

    /** @var array{int, string, array<string, string>} */
    private array $backendResponse = [200, '', []];

    protected function backendResponds(int $status, string $body, array $headers): void
    {
        $this->backendResponse = [$status, $body, $headers];
    }

    protected function backendCalls(): int
    {
        return $this->calls;
    }

    protected function durandal(array $rules, int $seed, Sleeper $sleeper, array $scenarios, ClockInterface $clock): ContractClient
    {
        $backend = new MockHttpClient(function (): MockResponse {
            ++$this->calls;
            [$status, $body, $headers] = $this->backendResponse;

            return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
        });

        $client = Durandal::wrapSymfony($backend)->withSeed($seed)->withSleeper($sleeper)->withClock($clock);
        foreach ($rules as $rule) {
            $client = $client->withRule($rule);
        }
        foreach ($scenarios as $scenario) {
            $client = $client->withScenario($scenario);
        }

        return new class ($client) implements ContractClient {
            public function __construct(private readonly DurandalHttpClient $client) {}

            public function journal(): Journal
            {
                return $this->client->journal();
            }

            public function send(string $method, string $url, array $headers = []): ContractResult
            {
                $response = $this->client->request($method, $url, ['headers' => $headers]);

                try {
                    $status = $response->getStatusCode();
                    $headers = array_map(static fn(array $values): string => implode(', ', $values), $response->getHeaders(false));

                    return ContractResult::response($status, $headers, $response->getContent(false));
                } catch (TransportExceptionInterface $e) {
                    return ContractResult::error(self::kind($e));
                }
            }

            private static function kind(Throwable $e): string
            {
                for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                    if ($cause instanceof SimulatedTimeout) {
                        return ContractResult::TIMEOUT;
                    }
                    if ($cause instanceof SimulatedConnectionRefused) {
                        return ContractResult::CONNECTION_REFUSED;
                    }
                }

                throw $e;
            }
        };
    }
}
