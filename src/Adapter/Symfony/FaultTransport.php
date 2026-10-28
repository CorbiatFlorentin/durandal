<?php

declare(strict_types=1);

namespace Durandal\Adapter\Symfony;

use Generator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use WeakMap;

/**
 * Client sur lequel reposent toutes les réponses d'un DurandalHttpClient : les réponses simulées sont servies
 * par un MockHttpClient, sans atteindre le serveur, et les autres par le vrai client.
 *
 * AsyncResponse ne sait streamer ensemble que des réponses issues d'un même client, d'où cet aiguillage.
 *
 * @internal
 */
final class FaultTransport implements HttpClientInterface
{
    private ?ResponseInterface $next = null;

    /** @var WeakMap<ResponseInterface, true> */
    private WeakMap $simulated;

    private readonly MockHttpClient $simulator;

    public function __construct(private readonly HttpClientInterface $inner)
    {
        $this->simulated = new WeakMap();
        $this->simulator = new MockHttpClient();
    }

    /**
     * La prochaine requête sera servie par cette réponse au lieu du vrai client.
     */
    public function simulateNext(ResponseInterface $response): void
    {
        $this->next = $response;
    }

    /**
     * Sert immédiatement une requête par cette réponse.
     *
     * @param array<mixed> $options
     */
    public function simulate(ResponseInterface $response, string $method, string $url, array $options = []): ResponseInterface
    {
        $simulated = (new MockHttpClient($response))->request($method, $url, $options);
        $this->simulated[$simulated] = true;

        return $simulated;
    }

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $next = $this->next;
        $this->next = null;

        return $next === null ? $this->inner->request($method, $url, $options) : $this->simulate($next, $method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $simulated = [];
        $real = [];
        foreach ($responses instanceof ResponseInterface ? [$responses] : $responses as $response) {
            if (isset($this->simulated[$response])) {
                $simulated[] = $response;
            } else {
                $real[] = $response;
            }
        }

        return new ResponseStream($this->streamAll($simulated, $real, $timeout));
    }

    /**
     * @param array<mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options));
    }

    /**
     * Les réponses simulées sont déjà complètes : elles passent avant les vraies.
     *
     * @param list<ResponseInterface> $simulated
     * @param list<ResponseInterface> $real
     *
     * @return Generator<ResponseInterface, ChunkInterface>
     */
    private function streamAll(array $simulated, array $real, ?float $timeout): Generator
    {
        if ($simulated !== []) {
            yield from $this->simulator->stream($simulated, $timeout);
        }

        if ($real !== []) {
            yield from $this->inner->stream($real, $timeout);
        }
    }
}
