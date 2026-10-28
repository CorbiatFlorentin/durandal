<?php

declare(strict_types=1);

namespace Durandal\Adapter\Symfony;

use Closure;
use Durandal\Adapter\FaultShortcuts;
use Durandal\Clock\NullSleeper;
use Durandal\Clock\Sleeper;
use Durandal\Engine\FaultEngine;
use Durandal\Exception\InvalidConfiguration;
use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Fault\MalformedBody;
use Durandal\Fault\Timeout;
use Durandal\Fault\TruncatedBody;
use Durandal\Http\RequestInfo;
use Durandal\Random\SeededRandomizer;
use Durandal\Rule\Rule;
use Generator;
use LogicException;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Décorateur Symfony HttpClient qui injecte des pannes dans un vrai client, streaming asynchrone compris.
 *
 * La configuration est immuable : chaque with…() renvoie un nouveau client, dont la séquence aléatoire
 * repart de la graine. Une même instance, elle, avance dans sa séquence à chaque requête.
 *
 * Le décorateur doit voir des URL absolues pour cibler un hôte : placez-le sous les clients qui résolvent
 * base_uri (le bundle le fait pour vous), ou passez l'option base_uri à chaque requête.
 */
final class DurandalHttpClient implements HttpClientInterface, ResetInterface
{
    use AsyncDecoratorTrait;
    use FaultShortcuts;

    private const SUPPORTED_FAULTS = [
        Latency::class,
        HttpError::class,
        Timeout::class,
        ConnectionRefused::class,
        TruncatedBody::class,
        MalformedBody::class,
    ];

    private readonly FaultEngine $engine;

    private readonly FaultTransport $transport;

    /**
     * @internal utilisez Durandal::wrapSymfony() puis les with…() : la liste des paramètres pourra évoluer
     *
     * @param list<Rule> $rules
     */
    public function __construct(
        HttpClientInterface $client,
        private readonly int $seed,
        private readonly array $rules = [],
        private readonly Sleeper $sleeper = new NullSleeper(),
    ) {
        foreach ($rules as $rule) {
            if (!\in_array($rule->fault::class, self::SUPPORTED_FAULTS, true)) {
                throw new InvalidConfiguration(\sprintf("La panne %s n'est pas prise en charge par l'adaptateur Symfony HttpClient.", $rule->fault::class));
            }
        }

        $this->client = $client;
        $this->transport = new FaultTransport($client);
        $this->engine = new FaultEngine($rules, new SeededRandomizer($seed));
    }

    public function seed(): int
    {
        return $this->seed;
    }

    /**
     * @return list<Rule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function withRule(Rule $rule): self
    {
        return new self($this->client, $this->seed, [...$this->rules, $rule], $this->sleeper);
    }

    public function withSeed(int $seed): self
    {
        return new self($this->client, $seed, $this->rules, $this->sleeper);
    }

    public function withSleeper(Sleeper $sleeper): self
    {
        return new self($this->client, $this->seed, $this->rules, $sleeper);
    }

    /**
     * Comme les with…(), renvoie un nouveau client dont la séquence aléatoire repart de la graine.
     *
     * @param array<mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->client->withOptions($options), $this->seed, $this->rules, $this->sleeper);
    }

    /**
     * @param array<mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $decision = $this->engine->decide(self::describe($method, $url, $options));

        if ($decision->latencyMilliseconds > 0) {
            $this->sleeper->sleep($decision->latencyMilliseconds);
        }

        $fault = $decision->fault;

        $passthru = match (true) {
            $fault === null => null,
            $fault instanceof HttpError => $this->serve(self::errorResponse($fault)),
            $fault instanceof Timeout => $this->serve(new FailingResponse(SimulatedTimeout::for($method, $url))),
            $fault instanceof ConnectionRefused => $this->serve(new FailingResponse(SimulatedConnectionRefused::for($method, $url))),
            $fault instanceof TruncatedBody => self::truncate($fault),
            $fault instanceof MalformedBody => $this->malform($fault, $method, $url, $options),
            // Inatteignable : le constructeur refuse les pannes inconnues.
            default => throw new LogicException(\sprintf('Panne non prise en charge : %s.', $fault::class)),
        };

        return new AsyncResponse($this->transport, $method, $url, $options, $passthru);
    }

    /**
     * @param array<mixed> $options
     */
    private static function describe(string $method, string $url, array $options): RequestInfo
    {
        $parts = parse_url($url);
        if ($parts === false) {
            $parts = [];
        }
        $host = $parts['host'] ?? null;
        if ($host === null && \is_string($options['base_uri'] ?? null)) {
            // URL relative : l'hôte vient de base_uri.
            $host = parse_url($options['base_uri'], \PHP_URL_HOST);
        }

        return new RequestInfo($method, \is_string($host) ? $host : '', $parts['path'] ?? '', self::headers($options['headers'] ?? []));
    }

    /**
     * Accepte les deux formes de l'option headers : ['Nom' => valeur(s)] et ['Nom: valeur', …].
     *
     * @return array<string, list<string>>
     */
    private static function headers(mixed $headers): array
    {
        $normalized = [];
        foreach (is_iterable($headers) ? $headers : [] as $name => $values) {
            if (\is_int($name)) {
                if (!\is_string($values) || !str_contains($values, ':')) {
                    continue;
                }
                [$name, $values] = explode(':', $values, 2);
            }

            if (!\is_string($name)) {
                continue;
            }

            foreach (is_iterable($values) ? $values : [$values] as $value) {
                if (\is_scalar($value)) {
                    $normalized[strtolower(trim($name))][] = trim((string) $value);
                }
            }
        }

        return $normalized;
    }

    private static function errorResponse(HttpError $fault): MockResponse
    {
        return new MockResponse('', [
            'http_code' => $fault->status,
            'response_headers' => $fault->retryAfterSeconds === null ? [] : ['Retry-After' => (string) $fault->retryAfterSeconds],
        ]);
    }

    /**
     * La requête sera servie par cette réponse, sans atteindre le serveur.
     */
    private function serve(ResponseInterface $response): null
    {
        $this->transport->simulateNext($response);

        return null;
    }

    /**
     * Retient le corps de la vraie réponse, puis n'en livre que le début. Les en-têtes restent intacts.
     */
    private static function truncate(TruncatedBody $fault): Closure
    {
        $body = '';

        return static function (ChunkInterface $chunk, AsyncContext $context) use ($fault, &$body): Generator {
            if ($chunk->getError() !== null || $chunk->isFirst()) {
                yield $chunk;

                return;
            }

            $body .= $chunk->getContent();

            if ($chunk->isLast()) {
                yield $context->createChunk($fault->apply($body));
                yield $chunk;
            }
        };
    }

    /**
     * Dès les en-têtes de la vraie réponse, la remplace par une réponse au corps invalide.
     * Le corps de remplacement est en clair : Content-Length et Content-Encoding sont retirés.
     *
     * @param array<mixed> $options
     */
    private function malform(MalformedBody $fault, string $method, string $url, array $options): Closure
    {
        return function (ChunkInterface $chunk, AsyncContext $context) use ($fault, $method, $url, $options): Generator {
            if ($chunk->getError() !== null) {
                yield $chunk;

                return;
            }

            $headers = $context->getHeaders();
            unset($headers['content-length'], $headers['content-encoding']);
            $replacement = new MockResponse($fault->body, ['http_code' => $context->getStatusCode(), 'response_headers' => $headers]);

            $context->getResponse()->cancel();
            $context->replaceResponse($this->transport->simulate($replacement, $method, $url, ['buffer' => false] + $options));
            $context->passthru();
        };
    }
}
