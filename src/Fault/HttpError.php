<?php

declare(strict_types=1);

namespace Durandal\Fault;

use Durandal\Exception\InvalidConfiguration;

/**
 * Répond avec un statut d'erreur HTTP sans que la requête n'atteigne le serveur
 * (comme un proxy ou un répartiteur de charge qui la rejette).
 */
final readonly class HttpError implements Fault
{
    public function __construct(
        public int $status = 503,
        public ?int $retryAfterSeconds = null,
    ) {
        if ($status < 400 || $status > 599) {
            throw new InvalidConfiguration(\sprintf('Le statut doit être une erreur HTTP (400 à 599), %d reçu.', $status));
        }

        if ($retryAfterSeconds !== null && $retryAfterSeconds < 0) {
            throw new InvalidConfiguration(\sprintf('Retry-After doit être positif ou nul, %d reçu.', $retryAfterSeconds));
        }
    }
}
