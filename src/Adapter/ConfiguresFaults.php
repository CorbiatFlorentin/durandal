<?php

declare(strict_types=1);

namespace Durandal\Adapter;

use Durandal\Clock\Sleeper;
use Durandal\Fault\HttpError;
use Durandal\Fault\Latency;
use Durandal\Journal\Journal;
use Durandal\Rule\Probability;
use Durandal\Rule\RequestMatcher;
use Durandal\Rule\Rule;
use Durandal\Scenario\Scenario;
use Psr\Clock\ClockInterface;

/**
 * Configuration commune aux adaptateurs. Chaque with…() renvoie un nouveau client, dont la séquence aléatoire,
 * les scénarios et le journal repartent de zéro ; une même instance, elle, avance à chaque requête.
 *
 * La classe qui utilise ce trait déclare $settings et $journal, et sait se recréer avec d'autres réglages.
 */
trait ConfiguresFaults
{
    abstract protected function withSettings(Settings $settings): self;

    public function seed(): int
    {
        return $this->settings->seed;
    }

    /**
     * @return list<Rule>
     */
    public function rules(): array
    {
        return $this->settings->rules;
    }

    /**
     * @return list<Scenario>
     */
    public function scenarios(): array
    {
        return $this->settings->scenarios;
    }

    /**
     * Les requêtes passées par ce client et ce qui leur est arrivé.
     */
    public function journal(): Journal
    {
        return $this->journal;
    }

    public function withRule(Rule $rule): self
    {
        return $this->withSettings($this->settings->withRule($rule));
    }

    /**
     * Joue ce scénario avant les règles, sur les requêtes qu'il vise.
     */
    public function withScenario(Scenario $scenario): self
    {
        return $this->withSettings($this->settings->withScenario($scenario));
    }

    public function withSeed(int $seed): self
    {
        return $this->withSettings($this->settings->withSeed($seed));
    }

    public function withSleeper(Sleeper $sleeper): self
    {
        return $this->withSettings($this->settings->withSleeper($sleeper));
    }

    /**
     * Horloge qui date le journal et mesure les étapes de durée des scénarios.
     */
    public function withClock(ClockInterface $clock): self
    {
        return $this->withSettings($this->settings->withClock($clock));
    }

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
