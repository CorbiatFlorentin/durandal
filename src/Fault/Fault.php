<?php

declare(strict_types=1);

namespace Durandal\Fault;

/**
 * Une panne réseau à simuler. Les pannes sont des valeurs immuables et déclaratives :
 * chaque adaptateur (PSR-18, Symfony HttpClient…) les traduit dans le comportement de son client.
 *
 * Interface fermée : n'implémentez pas vos propres pannes, les adaptateurs ne connaissent que celles
 * de ce dossier et refusent les autres dès la configuration.
 */
interface Fault {}
