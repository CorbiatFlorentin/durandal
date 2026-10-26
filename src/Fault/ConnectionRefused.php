<?php

declare(strict_types=1);

namespace Durandal\Fault;

/**
 * La connexion est refusée : le client lève une erreur réseau, sans réponse.
 */
final readonly class ConnectionRefused implements Fault {}
