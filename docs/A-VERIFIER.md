# À vérifier sur l'autre poste — phase 3

Note de contrôle avant de considérer la phase 3 terminée. À supprimer une fois tout validé.

## Déjà vérifié (poste Windows, PHP 8.4)

- `composer qa` : CS, PHPStan max et PHPUnit verts (195 tests)
- Couverture des lignes : 96,4 % (seuil 95 %), mesurée avec Xdebug
- Symfony 6.4 + PHPUnit 11.5 en dépendances minimales : 195 tests verts
- Projet fixture Pest (Pest 4.7.8) : 5 tests verts
- Infection : MSI 90 % (684 mutants tués sur 755, seuil 85 %)

## À contrôler

1. **Mise à jour des dépendances** : `psr/clock` est une nouvelle dépendance.
   ```bash
   git pull && composer install
   ```
2. **QA complète**
   ```bash
   composer qa
   composer test:coverage   # attendu ≥ 95 %
   ```
3. **Infection** : 90 % sur le poste Windows ; confirmer le même ordre de grandeur (seuil 85 %).
   ```bash
   composer infection
   ```
4. **Plugin Pest** dans le projet fixture :
   ```bash
   cd tests/Fixtures/pest-project && composer install && vendor/bin/pest
   ```
5. **Extension PHPUnit** à l'œil : la suite fixture échoue exprès et doit afficher le bloc
   « Durandal — requêtes des tests en échec » avec la graine et le journal.
   ```bash
   vendor/bin/phpunit -c tests/Bridge/PHPUnit/Fixtures/phpunit.xml
   ```
6. **CI GitHub** : le nouveau job « Plugin Pest » (PHP 8.2 → Pest 3, PHP 8.4 → Pest 4) n'a
   encore jamais tourné. Pest 3 sous PHP 8.2 n'a pas été testé en local.

## Puis

- Supprimer cette note.
- Release **v0.1** (plan, phase 3) : passer `Durandal::VERSION` à `0.1.0`, taguer `v0.1.0`,
  publier sur Packagist.
