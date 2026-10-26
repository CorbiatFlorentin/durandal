<?php

declare(strict_types=1);

namespace Durandal\Fault;

/**
 * La requête expire : le client lève une erreur réseau, sans réponse.
 */
final readonly class Timeout implements Fault {}
