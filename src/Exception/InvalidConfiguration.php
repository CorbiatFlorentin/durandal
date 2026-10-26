<?php

declare(strict_types=1);

namespace Durandal\Exception;

use InvalidArgumentException;

/**
 * Configuration de panne ou de règle invalide (valeur hors bornes, panne non prise en charge…).
 */
final class InvalidConfiguration extends InvalidArgumentException implements DurandalException {}
