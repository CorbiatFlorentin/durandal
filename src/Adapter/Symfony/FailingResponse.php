<?php

declare(strict_types=1);

namespace Durandal\Adapter\Symfony;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Réponse factice qui échoue avant les en-têtes : servie par MockHttpClient, elle devient une erreur de transport.
 *
 * @internal
 */
final class FailingResponse implements ResponseInterface
{
    public function __construct(private readonly SimulatedTransportException $error) {}

    public function getStatusCode(): never
    {
        throw $this->error;
    }

    public function getHeaders(bool $throw = true): never
    {
        throw $this->error;
    }

    public function getContent(bool $throw = true): never
    {
        throw $this->error;
    }

    public function toArray(bool $throw = true): never
    {
        throw $this->error;
    }

    public function cancel(): void {}

    public function getInfo(?string $type = null): mixed
    {
        return $type === null ? [] : null;
    }
}
