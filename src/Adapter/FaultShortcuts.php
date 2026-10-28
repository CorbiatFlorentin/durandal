<?php

declare(strict_types=1);

namespace Durandal\Adapter;

use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Rule\Probability;
use Durandal\Rule\RequestMatcher;
use Durandal\Rule\Rule;

/**
 * Raccourcis communs aux adaptateurs, construits sur leur withRule().
 */
trait FaultShortcuts
{
    abstract public function withRule(Rule $rule): self;

    /**
     * Raccourci : ajoute une latence fixe aux requêtes visées.
     */
    public function latency(int $milliseconds, ?RequestMatcher $match = null): self
    {
        return $this->withRule(new Rule(new Latency($milliseconds), $match));
    }

    /**
     * Raccourci : fait échouer une part des requêtes visées avec un statut HTTP.
     */
    public function failRate(Probability|float $probability, int $status = 503, ?RequestMatcher $match = null): self
    {
        return $this->withRule(new Rule(new HttpError($status), $match, $probability));
    }
}
