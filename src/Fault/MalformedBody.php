<?php

declare(strict_types=1);

namespace Durandal\Fault;

/**
 * La vraie réponse arrive, mais son corps est remplacé par un contenu invalide (JSON cassé par défaut).
 */
final readonly class MalformedBody implements Fault
{
    public const DEFAULT_BODY = '{"durandal": "malformed';

    public function __construct(public string $body = self::DEFAULT_BODY) {}
}
