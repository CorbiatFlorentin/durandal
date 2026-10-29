# Plan — Durandal : bibliothèque de tests de résilience pour PHP / Symfony

> Paquet Composer : `<vendor>/durandal` (dépendance de dev).

## 1. Vision

Permettre de **tester ce que fait le code quand le réseau va mal** (latence, erreurs, timeouts, coupures), de façon **déterministe, rapide et indépendante du framework**, avec une intégration Symfony de premier ordre.

**Promesse** : « Si ton code survit à `durandal` en test, il survivra à une panne de Packagist, de Stripe ou de ton propre backend en prod. »

### Objectifs
- Injecter des pannes réseau dans les clients HTTP sans modifier le code testé.
- Tests **reproductibles** : même graine aléatoire → même séquence de pannes.
- Tests **rapides** : la latence est simulée via une horloge virtuelle, pas de vrais `sleep()`.
- Assertions dédiées à la résilience (réessais, idempotence, dégradation).

### Hors périmètre (pour l'instant)
- Remplacer PHPUnit ou Pest : on s'y branche.
- Tests de charge / performance (k6, Gatling font ça).
- Chaos engineering en production.

## 2. Compatibilité ciblée

| Élément | Versions |
|---|---|
| PHP | 8.2 → 8.5 |
| Symfony | 6.4 LTS, 7.4 LTS, 8.x |
| Runners de tests | PHPUnit 10+, Pest 3+ |
| Clients HTTP | Symfony HttpClient (natif), tout client PSR-18 (Guzzle, etc.) |

## 3. Architecture

```
packages/durandal/
├── src/
│   ├── Fault/            # Les pannes (objets immuables)
│   │   ├── Latency.php
│   │   ├── HttpError.php          # 500, 502, 503, 429 (+ Retry-After)
│   │   ├── Timeout.php
│   │   ├── ConnectionRefused.php
│   │   ├── TruncatedBody.php      # réponse coupée en plein milieu
│   │   └── MalformedBody.php      # JSON invalide
│   ├── Rule/             # Quand appliquer une panne
│   │   ├── RequestMatcher.php     # méthode, hôte, chemin, en-têtes
│   │   ├── Probability.php        # 20 % des requêtes, graine fixe
│   │   └── Rule.php               # matcher + panne + probabilité
│   ├── Scenario/         # Séquences : "3 échecs puis OK", "hors ligne 5 s"
│   ├── Journal/          # Enregistre chaque requête et ce qui lui est arrivé
│   ├── Cassette/         # Enregistrement / rejeu des échanges HTTP
│   ├── Clock/            # Horloge virtuelle (PSR-20)
│   ├── Adapter/
│   │   ├── Psr18/DurandalClient.php          # décorateur PSR-18
│   │   └── Symfony/DurandalHttpClient.php    # décorateur HttpClientInterface (AsyncDecoratorTrait)
│   └── Assert/           # Assertions de résilience
├── bridge/
│   ├── symfony-bundle/   # Configuration YAML, services, profils when@test
│   ├── phpunit/          # Extension PHPUnit (système d'événements PHPUnit 10+)
│   └── pest/             # Plugin Pest (expectations personnalisées)
└── tests/
```

### Principes de conception
- **Immuabilité** : chaque appel du builder (`->latency()`, `->failRate()`) retourne une nouvelle instance.
- **Cœur indépendant du framework** : `src/` ne dépend que des PSR (7, 17, 18, 20). Symfony, PHPUnit et Pest vivent dans `bridge/`.
- **Déterminisme** : toute source d'aléatoire passe par un générateur à graine injectable ; la graine est affichée quand un test échoue.
- **Zéro attente réelle** : la latence avance l'horloge virtuelle et les timeouts se déclenchent quand le temps virtuel dépasse le seuil.

### Exemple d'utilisation visé

```yaml
# config/packages/test/durandal.yaml
durandal:
    seed: 42
    clients:
        packagist.client:            # service HttpClient à décorer
            rules:
                - { match: { host: 'repo.packagist.org' }, fault: http_error, status: 503, probability: 0.3 }
                - { fault: latency, ms: 800 }
```

```php
public function testSyncSurvivesFlakyApi(): void
{
    $client = Durandal::wrap($this->httpClient)
        ->withScenario(Scenario::failTimes(2, status: 503)->thenSucceed())
        ->withClock($this->clock);

    $result = (new OrderSync($client))->run();

    self::assertTrue($result->isComplete());
    DurandalAssert::retriedExactly(2, $client->journal());
    DurandalAssert::noDuplicateWrites($client->journal(), idempotencyHeader: 'Idempotency-Key');
}
```

## 4. Stratégie de tests de la bibliothèque

Une bibliothèque de tests doit être **plus testée que le code qu'elle teste**.

| Niveau | Ce qu'on vérifie | Outils | Cible |
|---|---|---|---|
| **Unitaire** | Chaque panne, matcher, scénario, assertion isolément | PHPUnit | 95 % de couverture sur `src/` |
| **Contrat** | Une **même suite de tests** exécutée sur chaque adaptateur (PSR-18 + Guzzle, Symfony HttpClient) : mêmes entrées → mêmes comportements | PHPUnit, data providers | 100 % des adaptateurs |
| **Intégration** | Vrai serveur HTTP local (`php -S` sur une fixture) pour les timeouts et les coupures réelles | PHPUnit + processus en arrière-plan | Scénarios critiques |
| **Déterminisme** | Même graine → même séquence sur 1 000 requêtes ; graines différentes → séquences différentes | PHPUnit | Obligatoire |
| **Mutation** | Les tests détectent vraiment les bugs | Infection | MSI ≥ 85 % |
| **Analyse statique** | Types, appels impossibles | PHPStan niveau max, PHP-CS-Fixer | 0 erreur |
| **Documentation exécutable** | Les exemples du README et de la doc sont extraits et exécutés en CI | Script maison | 100 % des exemples |
| **Bridges** | Bundle chargé dans un mini-kernel Symfony de test ; extension PHPUnit et plugin Pest testés sur des projets fixtures | KernelTestCase | Chaque bridge |
| **Surcoût** | Le décorateur sans règle ajoute un surcoût négligeable | PHPBench | < 5 % |

**CI GitHub Actions** : matrice PHP 8.2 / 8.3 / 8.4 / 8.5 × Symfony 6.4 / 7.4 / 8.x × dépendances `lowest` / `highest`.

## 5. Phases

### Phase 0 — Fondations (≈ 1 semaine)
- Monorepo, `composer.json`, autoload PSR-4, licence MIT
- PHPStan max, PHP-CS-Fixer, Infection, PHPUnit configurés
- CI avec la matrice complète, badge de couverture
- **Terminé quand** : la CI est verte sur un test vide, sur toute la matrice.

### Phase 1 — Cœur des pannes + PSR-18 (≈ 2 semaines)
- `Fault/*`, `Rule/*`, générateur à graine, décorateur PSR-18
- Suite de contrat v1 (exécutée sur Guzzle)
- **Terminé quand** : chaque panne est testée unitairement et passe la suite de contrat.

### Phase 2 — Adaptateur Symfony + bundle (≈ 2 semaines)
- `DurandalHttpClient` (décorateur natif, compatible avec le streaming asynchrone)
- Bundle : configuration YAML validée, décoration automatique des clients nommés, activé seulement en `test`
- Suite de contrat exécutée aussi sur Symfony HttpClient
- **Terminé quand** : un projet Symfony vierge active `durandal` uniquement par configuration.

### Phase 3 — Journal, scénarios et assertions (≈ 2 semaines)
- Journal des requêtes, scénarios séquentiels (`failTimes`, `offlineFor`, `slowThenFast`)
- `DurandalAssert` : `retriedExactly`, `noDuplicateWrites`, `gaveUpAfter`, `respectedRetryAfter`
- Extension PHPUnit (graine affichée en cas d'échec, réinitialisation entre tests) et plugin Pest
- **Terminé quand** : un échec affiche la graine et le journal lisible des requêtes.
- 🏷️ **Release v0.1** sur Packagist.

### Phase 4 — Horloge virtuelle (≈ 1 semaine)
- `VirtualClock` PSR-20, latence et timeouts en temps virtuel
- Compatible avec `symfony/clock` (`MockClock`)
- **Terminé quand** : un test avec 10 s de latence simulée s'exécute en moins de 50 ms.

### Phase 5 — Cassettes : enregistrement et rejeu (≈ 2 semaines)
- Format YAML ou JSON lisible et versionnable
- **Masquage automatique des secrets** (`Authorization`, cookies, tokens dans l'URL) avant écriture
- Modes : `record`, `replay`, `record_new` ; erreur claire si une requête n'a pas de correspondance
- Possibilité de rejouer une cassette **avec des pannes injectées par-dessus**
- **Terminé quand** : un test d'API réelle tourne hors ligne à partir de sa cassette.
- 🏷️ **Release v0.2**.

### Phase 6 — Détecteur de tests instables (≈ 1 semaine)
- Commande `vendor/bin/durandal flaky --runs=20` : relance des tests et rapport de ceux qui échouent par moments
- **Terminé quand** : le rapport indique le taux d'échec par test et la graine qui reproduit l'échec.

### Phase 7 — Réseau bas niveau (plus tard)
- Pannes DNS, refus de connexion TCP, sockets lentes
- Pilote optionnel pour Toxiproxy (pannes réelles au niveau TCP, pour bases de données et files de messages)

### Phase 8 — v1.0
- API gelée, guide de migration, site de documentation, article de lancement
- 🏷️ **Release v1.0**.

## 6. Risques et parades

| Risque | Parade |
|---|---|
| Le comportement asynchrone de Symfony HttpClient est complexe à décorer | Utiliser `AsyncDecoratorTrait` ; la suite de contrat couvre le streaming |
| Le temps virtuel ne couvre pas le code qui appelle `sleep()` directement | Le documenter ; fournir un `Sleeper` injectable ; détecter et avertir |
| Fuite de secrets dans les cassettes | Masquage par défaut, liste configurable, test dédié qui échoue si un token apparaît |
| La matrice de compatibilité devient trop lourde | Supporter uniquement les versions maintenues de PHP et de Symfony |

## 7. Lien avec `pavois`

Voir [Pavois/docs/PLAN.md](../../Pavois/docs/PLAN.md). `pavois` est le **premier utilisateur réel** de `durandal` : ses clients Packagist, GitHub et endoflife.date sont testés avec des cassettes et des pannes injectées. Dépendance : `pavois` a besoin de **durandal v0.1** pour sa phase 3 et de **v0.2** pour sa phase 4.
