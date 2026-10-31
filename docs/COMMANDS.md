# Commandes utiles

Aide-mémoire du projet, complété au fil des étapes.
Toujours passer par `symfony console` / `symfony php` (ils injectent `DATABASE_URL` et `MAILER_DSN` depuis Docker).

## Raccourcis Makefile

`make` liste toutes les cibles. Les plus courantes :

| Commande | Rôle |
|---|---|
| `make start` / `make stop` | Démarre / arrête Postgres, Mailpit et le serveur web |
| `make url` | URL du serveur local |
| `make logs` | Logs du serveur web en continu |
| `make cc` | Vide le cache |
| `make migration` / `make migrate` / `make migrate-test` | Génère / applique les migrations (dev, test) |
| `make db-validate` | Vérifie que les entités et la base sont synchronisées |
| `make fixtures` / `make db-reset` | Recharge les données de démo / recrée la base de dev |
| `make test` / `make test f=CityTest` | Lance les tests (tous / filtrés) |
| `make phpstan` | Analyse statique |
| `make check` | Lint + PHPStan + schéma + tests, avant un commit |

## Messenger (emails asynchrones)

Les emails (`SendEmailMessage`) passent par le transport `async` (table `messenger_messages`) :
ils ne partent que si un worker tourne.

```bash
symfony console messenger:consume async -vv     # worker : traite la file (Ctrl+C pour arrêter)
symfony console messenger:stats                 # nombre de messages par transport (async, failed)
symfony console messenger:failed:show           # messages en échec
symfony console messenger:failed:show <id> -vv  # détail d'un échec, avec l'erreur
symfony console messenger:failed:retry          # relancer les messages en échec
symfony console messenger:stop-workers          # arrêter proprement les workers (après un déploiement de code)
```

Contenu de la file d'attente (pas de commande Symfony pour ça) :

```bash
symfony console dbal:run-sql "SELECT id, queue_name, created_at, available_at, delivered_at FROM messenger_messages ORDER BY id"
```

`queue_name = 'default'` → file `async` ; `'failed'` → échecs ; `delivered_at` non NULL → en cours de traitement.

## Mailer

```bash
symfony open:local:webmail      # ouvre Mailpit (emails reçus en local)
symfony var:export MAILER_DSN   # DSN réellement utilisé (injecté par le CLI Symfony)
```

## Base de données

```bash
symfony console doctrine:migrations:status                  # migrations appliquées / en attente
symfony console dbal:run-sql "SELECT ..."                   # requête SQL rapide
symfony console dbal:run-sql "SELECT ..." --env=test        # idem sur la base de test
```

Client SQL externe : Postgres sur `localhost:5433`.

## Traductions

```bash
symfony console translation:extract fr --force --domain=messages            # met à jour messages+intl-icu.fr.xlf
symfony console translation:extract fr --dump-messages --domain=messages    # aperçu des clés trouvées, sans écrire
symfony console translation:extract fr --dump-messages --domain=validators  # idem pour les messages de validation
symfony console debug:translation fr                                        # clés manquantes / inutilisées
```

## Debug

```bash
symfony console debug:router                          # liste des routes
symfony console debug:router app_register             # détail d'une route
symfony console debug:form "App\Form\RegistrationFormType"   # champs et options d'un form type
symfony console debug:autowiring <mot>                # services injectables
symfony console debug:container --parameters          # paramètres du container
symfony console debug:config security                 # config effective d'un bundle
tail -f var/log/dev.log                               # logs applicatifs
```

Profiler web : `/_profiler` sur l'URL du serveur.

## Git

```bash
git switch develop && git fetch origin develop && git pull && git switch -c feature/<nom>   # nouvelle branche
```

Pas de commit direct sur `develop` ni `main` : fusion par pull request.
