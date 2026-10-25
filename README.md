# Durandal

[![CI](https://github.com/CorbiatFlorentin/durandal/actions/workflows/ci.yml/badge.svg)](https://github.com/CorbiatFlorentin/durandal/actions/workflows/ci.yml)
[![Couverture](https://codecov.io/gh/CorbiatFlorentin/durandal/graph/badge.svg)](https://codecov.io/gh/CorbiatFlorentin/durandal)
[![Licence MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

Bibliothèque PHP de **tests de résilience** : elle simule des pannes réseau (latence, erreurs HTTP, timeouts, coupures) dans tes tests pour vérifier que ton code réessaie, abandonne proprement et ne fait jamais deux fois une action importante.

- Cœur indépendant du framework (PSR-18, PSR-20), intégration Symfony, PHPUnit et Pest
- Tests déterministes (graine fixe) et rapides (horloge virtuelle, aucune vraie attente)

> 🚧 En conception — voir le [plan](docs/PLAN.md).

## Développement

```bash
composer install
composer qa          # PHP-CS-Fixer + PHPStan (niveau max) + PHPUnit
composer infection   # tests de mutation (nécessite pcov ou Xdebug)
```

Projet frère : **Pavois**, le service d'analyse de dépôts qui utilise Durandal pour ses propres tests.
