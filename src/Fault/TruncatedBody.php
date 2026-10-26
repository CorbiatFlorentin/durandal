<?php

declare(strict_types=1);

namespace Durandal\Fault;

use Durandal\Exception\InvalidConfiguration;

/**
 * La vraie réponse arrive, mais son corps est coupé en plein milieu.
 * Les en-têtes (dont Content-Length) sont conservés, comme lors d'une vraie coupure.
 */
final readonly class TruncatedBody implements Fault
{
    /**
     * @param float $keepRatio part du corps conservée, de 0 (inclus) à 1 (exclu)
     */
    public function __construct(public float $keepRatio = 0.5)
    {
        if (!($keepRatio >= 0.0 && $keepRatio < 1.0)) {
            throw new InvalidConfiguration(\sprintf('La part conservée doit être dans [0, 1[, %F reçu.', $keepRatio));
        }
    }

    public function apply(string $body): string
    {
        return substr($body, 0, (int) floor(\strlen($body) * $this->keepRatio));
    }
}
