# Durandal

[![CI](https://github.com/CorbiatFlorentin/durandal/actions/workflows/ci.yml/badge.svg)](https://github.com/CorbiatFlorentin/durandal/actions/workflows/ci.yml)
[![Couverture](https://codecov.io/gh/CorbiatFlorentin/durandal/graph/badge.svg)](https://codecov.io/gh/CorbiatFlorentin/durandal)
[![Licence MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

Bibliothèque PHP de **tests de résilience** : elle simule des pannes réseau (latence, erreurs HTTP, timeouts, coupures) dans tes tests pour vérifier que ton code réessaie, abandonne proprement et ne fait jamais deux fois une action importante.

- Cœur indépendant du framework (PSR-18, PSR-20), intégration Symfony, PHPUnit et Pest
- Tests déterministes (graine fixe) et rapides (horloge virtuelle, aucune vraie attente)

> 🚧 En développement (phase 1 : pannes, règles et décorateur PSR-18) — voir le [plan](docs/PLAN.md).

## Utilisation (PSR-18)

```php
use Durandal\Durandal;
use Durandal\Fault\{HttpError, Timeout};
use Durandal\Rule\{RequestMatcher, Rule};

$client = Durandal::wrap($psr18Client)          // Guzzle ou tout client PSR-18
    ->withSeed(42)                              // même graine → même séquence de pannes
    ->latency(800)                              // + 800 ms simulées, sans attente réelle
    ->failRate(0.3, status: 503)                // 30 % des requêtes en 503
    ->withRule(new Rule(
        new HttpError(429, retryAfterSeconds: 30),
        RequestMatcher::any()->host('repo.packagist.org')->method('GET'),
    ))
    ->withRule(new Rule(new Timeout(), RequestMatcher::any()->path('/v1/payments*'), probability: 0.1));
```

Pannes disponibles : `Latency`, `HttpError` (avec `Retry-After`), `Timeout`, `ConnectionRefused`, `TruncatedBody`, `MalformedBody`.
Les latences des règles déclenchées s'additionnent ; ensuite, la première autre panne déclenchée l'emporte.
Chaque règle probabiliste qui vise une requête consomme un tirage (même après une panne gagnante) ; une règle qui ne la vise pas n'en consomme aucun. Chaque `with…()` renvoie un nouveau client dont la séquence repart de la graine.
Les délais dépassés et refus de connexion sont levés comme des `Psr\Http\Client\NetworkExceptionInterface`.

## Développement

```bash
composer install
composer qa          # PHP-CS-Fixer + PHPStan (niveau max) + PHPUnit
composer infection   # tests de mutation (nécessite pcov ou Xdebug)
```

Projet frère : **Pavois**, le service d'analyse de dépôts qui utilise Durandal pour ses propres tests.
