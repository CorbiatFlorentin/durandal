<?php

declare(strict_types=1);

namespace Durandal\Tests\Contract;

use Durandal\Adapter\Psr18\DurandalClient;
use Durandal\Adapter\Psr18\SimulatedConnectionRefused;
use Durandal\Adapter\Psr18\SimulatedTimeout;
use Durandal\Clock\Sleeper;
use Durandal\Durandal;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Suite de contrat exécutée sur l'adaptateur PSR-18, autour d'un vrai client Guzzle
 * dont seul le transport réseau est remplacé par un faux serveur.
 */
#[CoversClass(DurandalClient::class)]
final class Psr18GuzzleContractTest extends AdapterContractTestCase
{
    private int $calls = 0;

    private ?ResponseInterface $backendResponse = null;

    protected function backendResponds(int $status, string $body, array $headers): void
    {
        $this->backendResponse = new Response($status, $headers, $body);
    }

    protected function backendCalls(): int
    {
        return $this->calls;
    }

    protected function durandal(array $rules, int $seed, Sleeper $sleeper): ContractClient
    {
        /**
         * Faux transport réseau : remplace le gestionnaire cURL de Guzzle.
         *
         * @param array<mixed> $options
         *
         * @return FulfilledPromise<ResponseInterface>
         */
        $transport = function (RequestInterface $request, array $options): FulfilledPromise {
            ++$this->calls;

            return new FulfilledPromise($this->backendResponse ?? throw new LogicException('Aucune réponse configurée pour le faux serveur.'));
        };

        $factory = new HttpFactory();
        $client = Durandal::wrap(new Client(['handler' => HandlerStack::create($transport)]), $factory, $factory)
            ->withSeed($seed)
            ->withSleeper($sleeper);
        foreach ($rules as $rule) {
            $client = $client->withRule($rule);
        }

        return new class ($client) implements ContractClient {
            public function __construct(private readonly DurandalClient $client) {}

            public function send(string $method, string $url, array $headers = []): ContractResult
            {
                try {
                    $response = $this->client->sendRequest(new Request($method, $url, $headers));
                } catch (SimulatedTimeout) {
                    return ContractResult::error(ContractResult::TIMEOUT);
                } catch (SimulatedConnectionRefused) {
                    return ContractResult::error(ContractResult::CONNECTION_REFUSED);
                }

                $headers = [];
                foreach ($response->getHeaders() as $name => $values) {
                    $headers[(string) $name] = implode(', ', $values);
                }

                return ContractResult::response($response->getStatusCode(), $headers, (string) $response->getBody());
            }
        };
    }
}
