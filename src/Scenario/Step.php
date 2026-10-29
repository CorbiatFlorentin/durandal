<?php

declare(strict_types=1);

namespace Durandal\Scenario;

use Durandal\Exception\InvalidConfiguration;
use Durandal\Fault\Fault;

/**
 * Une étape de scénario : une panne (ou aucune) appliquée à un nombre de requêtes ou pendant une durée.
 */
final readonly class Step
{
    private function __construct(
        public ?Fault $fault,
        public ?int $requests,
        public ?int $milliseconds,
    ) {}

    public static function forRequests(int $requests, ?Fault $fault): self
    {
        if ($requests < 1) {
            throw new InvalidConfiguration(\sprintf('Une étape doit viser au moins une requête, %d reçu.', $requests));
        }

        return new self($fault, $requests, null);
    }

    public static function forDuration(int $milliseconds, ?Fault $fault): self
    {
        if ($milliseconds < 1) {
            throw new InvalidConfiguration(\sprintf('Une étape doit durer au moins 1 ms, %d reçu.', $milliseconds));
        }

        return new self($fault, null, $milliseconds);
    }
}
