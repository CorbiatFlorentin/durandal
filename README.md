# Durandal

[![CI](https://github.com/CorbiatFlorentin/durandal/actions/workflows/ci.yml/badge.svg)](https://github.com/CorbiatFlorentin/durandal/actions/workflows/ci.yml)
[![Couverture](https://codecov.io/gh/CorbiatFlorentin/durandal/graph/badge.svg)](https://codecov.io/gh/CorbiatFlorentin/durandal)
[![Licence MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

Bibliothèque PHP de **tests de résilience** : elle simule des pannes réseau (latence, erreurs HTTP, timeouts, coupures) dans tes tests pour vérifier que ton code réessaie, abandonne proprement et ne fait jamais deux fois une action importante.

- Cœur indépendant du framework (PSR-18, PSR-20), intégration Symfony, PHPUnit et Pest
- Tests déterministes (graine fixe) et rapides (horloge virtuelle, aucune vraie attente)

> 🚧 En développement (phase 3 : journal, scénarios et assertions) — voir le [plan](docs/PLAN.md).

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

## Utilisation (Symfony HttpClient)

```php
$client = Durandal::wrapSymfony($httpClient)    // tout HttpClientInterface, streaming asynchrone compris
    ->withSeed(42)
    ->failRate(0.3, status: 503);
```

Mêmes règles et mêmes pannes qu'en PSR-18. Les délais dépassés et refus de connexion sont levés comme des `TransportExceptionInterface`, dont la cause (`getPrevious()`) est un `Durandal\Adapter\Symfony\SimulatedTimeout` ou `SimulatedConnectionRefused`.

### Bundle : activer Durandal par configuration

```php
// config/bundles.php — uniquement en test : le bundle refuse de démarrer en prod
Durandal\Bridge\Symfony\DurandalBundle::class => ['test' => true],
```

```yaml
# config/packages/test/durandal.yaml
durandal:
    seed: 42                         # facultatif : tirée au hasard si absente
    clients:
        packagist.client:            # http_client, un client de scoped_clients ou tout service HttpClient
            rules:
                - { match: { host: 'repo.packagist.org' }, fault: http_error, status: 503, probability: 0.3 }
                - { match: { method: POST, headers: { Idempotency-Key: ~ } }, fault: timeout }
                - { fault: latency, ms: 800 }
```

Pannes : `latency` (`ms`), `http_error` (`status`, `retry_after`), `timeout`, `connection_refused`, `truncated_body` (`keep_ratio`), `malformed_body` (`body`). Filtres de `match` : `method`, `host`, `path` (joker `*`), `headers` (`~` : présence seule).
Pour les clients de FrameworkBundle, Durandal se place juste au-dessus du transport : il voit les URL absolues, et les réessais de `retry_failed` portent sur les pannes simulées. Une configuration invalide est signalée dès la compilation du conteneur, avec le client et la règle en cause.

Le journal d'un client décoré s'obtient par son identifiant : `static::getContainer()->get(Durandal\Bridge\Symfony\Journals::class)->of('packagist.client')`.

## Scénarios

Un scénario rejoue une séquence d'étapes, dans l'ordre, sur les requêtes qu'il vise ; une fois terminé, les requêtes passent. Les scénarios sont joués avant les règles.

```php
use Durandal\Scenario\Scenario;

$client = $client
    ->withScenario(Scenario::failTimes(2, status: 503)->thenSucceed())                      // 2 échecs puis OK
    ->withScenario(Scenario::succeedTimes(1)->then(Scenario::offlineFor(5)))                  // OK, puis hors ligne 5 s
    ->withScenario(Scenario::slowThenFast(3, 2000)->forRequests(RequestMatcher::any()->host('api.github.com')));
```

Étapes : `failTimes`, `timeoutTimes`, `faultTimes` (toute panne), `succeedTimes`, `offlineFor` (durée), `slowThenFast`, enchaînées par `then()`.
Les durées se mesurent avec l'horloge du client (`withClock()`, PSR-20, horloge système par défaut) : donnez-lui l'horloge qu'utilise le code testé, `MockClock` de `symfony/clock` par exemple.

## Journal et assertions

Chaque client tient le journal de ses requêtes : `$client->journal()`.

```php
use Durandal\Bridge\PHPUnit\DurandalAssert;

DurandalAssert::retriedExactly(2, $client->journal());                          // tentatives au-delà de la première, par méthode et URL
DurandalAssert::gaveUpAfter(3, $client->journal());                             // 3 tentatives, la dernière en échec
DurandalAssert::noDuplicateWrites($client->journal(), idempotencyHeader: 'Idempotency-Key');
DurandalAssert::respectedRetryAfter($client->journal());                        // délai mesuré avec l'horloge du client
```

Un échec affiche la raison, la graine et le journal :

```
Durandal : 2 réessai(s) attendu(s), 0 constaté(s) — GET https://api.example.com/orders : 1 tentative(s).
Graine 1234 (rejouer : DURANDAL_SEED=1234)
#1  10:00:00.000  GET https://api.example.com/orders  → 503 simulé
```

Pour rejouer un échec, `DURANDAL_SEED=1234 vendor/bin/phpunit --filter …` impose la graine aux clients créés sans `withSeed()`.

### Extension PHPUnit

```xml
<extensions>
    <bootstrap class="Durandal\Bridge\PHPUnit\Extension"/>
</extensions>
```

Quand un test échoue, pour n'importe quelle raison, l'extension affiche à la fin de l'exécution la graine et les requêtes de chaque client Durandal qu'il a utilisé.

### Pest

Les attentes sont chargées automatiquement quand Pest est installé ; elles acceptent un client Durandal ou son journal.

```php
expect($client)->toHaveRetriedExactly(2)->toHaveNoDuplicateWrites();
expect($client)->toHaveGivenUpAfter(3);
expect($client)->toHaveRespectedRetryAfter();
```

## Développement

```bash
composer install
composer qa          # PHP-CS-Fixer + PHPStan (niveau max) + PHPUnit
composer infection   # tests de mutation (nécessite pcov ou Xdebug)
```

Projet frère : **Pavois**, le service d'analyse de dépôts qui utilise Durandal pour ses propres tests.
