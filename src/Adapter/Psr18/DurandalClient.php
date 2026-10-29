<?php

declare(strict_types=1);

namespace Durandal\Adapter\Psr18;

use Durandal\Adapter\ConfiguresFaults;
use Durandal\Adapter\Settings;
use Durandal\Engine\FaultEngine;
use Durandal\Fault\ConnectionRefused;
use Durandal\Fault\HttpError;
use Durandal\Fault\MalformedBody;
use Durandal\Fault\Timeout;
use Durandal\Fault\TruncatedBody;
use Durandal\Http\RequestInfo;
use Durandal\Journal\Journal;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Décorateur PSR-18 qui injecte des pannes dans un vrai client.
 *
 * La configuration est immuable : voir ConfiguresFaults.
 */
final class DurandalClient implements ClientInterface
{
    use ConfiguresFaults;

    private readonly FaultEngine $engine;

    private readonly Journal $journal;

    /**
     * @internal utilisez Durandal::wrap() puis les with…() : la liste des paramètres pourra évoluer
     */
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Settings $settings,
    ) {
        $this->engine = $settings->engine();
        $this->journal = new Journal($settings->seed);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $info = self::describe($request);
        $decision = $this->engine->decide($info);
        $this->journal->record($this->settings->clock->now(), (string) $request->getUri(), $info, self::body($request), $decision);

        if ($decision->latencyMilliseconds > 0) {
            $this->settings->sleeper->sleep($decision->latencyMilliseconds);
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
            // Inatteignable : Settings refuse les pannes inconnues.
            default => throw new LogicException(\sprintf('Panne non prise en charge : %s.', $fault::class)),
        };
    }

    protected function withSettings(Settings $settings): self
    {
        return new self($this->inner, $this->responseFactory, $this->streamFactory, $settings);
    }

    /**
     * Le corps n'est lu que s'il peut être rembobiné, pour ne pas priver le vrai client de son contenu.
     */
    private static function body(RequestInterface $request): ?string
    {
        $body = $request->getBody();
        if (!$body->isSeekable()) {
            return null;
        }

        $content = (string) $body;
        $body->rewind();

        return $content;
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
