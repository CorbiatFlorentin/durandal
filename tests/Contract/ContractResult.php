<?php

declare(strict_types=1);

namespace Durandal\Tests\Contract;

/**
 * Résultat d'une requête, normalisé pour comparer les adaptateurs entre eux :
 * soit une réponse (statut, en-têtes en minuscules, corps), soit une erreur réseau typée.
 */
final readonly class ContractResult
{
    public const TIMEOUT = 'timeout';
    public const CONNECTION_REFUSED = 'connection_refused';

    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public ?int $status,
        public array $headers,
        public string $body,
        public ?string $error,
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public static function response(int $status, array $headers, string $body): self
    {
        return new self($status, array_change_key_case($headers), $body, null);
    }

    public static function error(string $kind): self
    {
        return new self(null, [], '', $kind);
    }
}
