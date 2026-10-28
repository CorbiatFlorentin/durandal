<?php

declare(strict_types=1);

namespace Durandal\Adapter\Psr18;

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
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Décorateur PSR-18 qui injecte des pannes dans un vrai client.
 *
 * La configuration est immuable : chaque with…() renvoie un nouveau client, dont la séquence aléatoire
 * repart de la graine. Une même instance, elle, avance dans sa séquence à chaque requête.
 */
final class DurandalClient implements ClientInterface
{
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

    /**
     * @internal utilisez Durandal::wrap() puis les with…() : la liste des paramètres pourra évoluer
     *
     * @param list<Rule> $rules
     */
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly int $seed,
        private readonly array $rules = [],
        private readonly Sleeper $sleeper = new NullSleeper(),
    ) {
        foreach ($rules as $rule) {
            if (!\in_array($rule->fault::class, self::SUPPORTED_FAULTS, true)) {
                throw new InvalidConfiguration(\sprintf("La panne %s n'est pas prise en charge par l'adaptateur PSR-18.", $rule->fault::class));
            }
        }

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
        return new self($this->inner, $this->responseFactory, $this->streamFactory, $this->seed, [...$this->rules, $rule], $this->sleeper);
    }

    public function withSeed(int $seed): self
    {
        return new self($this->inner, $this->responseFactory, $this->streamFactory, $seed, $this->rules, $this->sleeper);
    }

    public function withSleeper(Sleeper $sleeper): self
    {
        return new self($this->inner, $this->responseFactory, $this->streamFactory, $this->seed, $this->rules, $sleeper);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $decision = $this->engine->decide(self::describe($request));

        if ($decision->latencyMilliseconds > 0) {
            $this->sleeper->sleep($decision->latencyMilliseconds);
        }

        $fault = $decision->fault;

        return match (true) {
            $fault === null => $this->inner->sendRequest($request),
            $fault instanceof HttpError => $this->errorResponse($fault),
            $fault instanceof Timeout => throw SimulatedTimeout::for($request),
            $fault instanceof ConnectionRefused => throw SimulatedConnectionRefused::for($request),
            $fault instanceof TruncatedBody => $this->replaceBody(
                $this->inner->sendRequest($request),
                static fn(string $body): string => $fault->apply($body),
            ),
            // Le corps de remplacement est en clair : on retire aussi Content-Encoding pour que le code testé
            // échoue sur le JSON invalide, et non sur une décompression impossible.
            $fault instanceof MalformedBody => $this->replaceBody(
                $this->inner->sendRequest($request),
                static fn(): string => $fault->body,
            )->withoutHeader('Content-Length')->withoutHeader('Content-Encoding'),
            // Inatteignable : le constructeur refuse les pannes inconnues.
            default => throw new LogicException(\sprintf('Panne non prise en charge : %s.', $fault::class)),
        };
    }

    private static function describe(RequestInterface $request): RequestInfo
    {
        $host = $request->getUri()->getHost();
        if ($host === '') {
            // URI relative : l'hôte n'est connu que par l'en-tête Host, éventuellement suivi du port.
            $host = (string) preg_replace('/:\d+$/', '', $request->getHeaderLine('Host'));
        }

        return new RequestInfo($request->getMethod(), $host, $request->getUri()->getPath(), $request->getHeaders());
    }

    private function errorResponse(HttpError $fault): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($fault->status);

        return $fault->retryAfterSeconds === null
            ? $response
            : $response->withHeader('Retry-After', (string) $fault->retryAfterSeconds);
    }

    /**
     * @param callable(string): string $transform
     */
    private function replaceBody(ResponseInterface $response, callable $transform): ResponseInterface
    {
        return $response->withBody($this->streamFactory->createStream($transform((string) $response->getBody())));
    }
}
