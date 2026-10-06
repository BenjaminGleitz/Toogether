.DEFAULT_GOAL := help
.PHONY: help install start stop restart logs \
        migration migrate migrate-test db-status db-validate fixtures db-reset \
        test test-dox phpstan lint check cc

SYMFONY  := symfony
CONSOLE  := $(SYMFONY) console
PHP      := $(SYMFONY) php
COMPOSE  := docker compose

## —— Aide ————————————————————————————————————————————————————————————————
help: ## Liste les commandes disponibles
	@grep -E '(^[a-zA-Z_-]+:.*?## .*$$)|(^## )' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}{ if ($$0 ~ /^## /) { sub(/^## /, ""); printf "\n\033[33m%s\033[0m\n", $$0 } else { printf "  \033[32m%-14s\033[0m %s\n", $$1, $$2 } }'
	@echo

## —— Projet ——————————————————————————————————————————————————————————————
install: ## Installe les dépendances PHP
	composer install

start: ## Démarre Postgres, Mailpit et le serveur web
	$(COMPOSE) up -d --wait
	$(SYMFONY) serve -d

stop: ## Arrête le serveur web et les conteneurs (les données sont conservées)
	-$(SYMFONY) server:stop
	$(COMPOSE) stop

restart: stop start ## Redémarre tout

logs: ## Affiche les logs du serveur web en continu
	$(SYMFONY) server:log

cc: ## Vide le cache Symfony
	$(CONSOLE) cache:clear

## —— Base de données —————————————————————————————————————————————————————
migration: ## Génère une migration à partir des entités modifiées
	$(CONSOLE) make:migration

migrate: ## Applique les migrations en dev
	$(CONSOLE) doctrine:migrations:migrate -n

migrate-test: ## Applique les migrations sur la base de test
	$(CONSOLE) doctrine:migrations:migrate -n --env=test

db-status: ## Affiche l'état des migrations (dev)
	$(CONSOLE) doctrine:migrations:status

db-validate: ## Vérifie que le mapping et la base sont synchronisés
	$(CONSOLE) doctrine:schema:validate

fixtures: ## Recharge les données de démo (vide la base de dev !)
	$(CONSOLE) doctrine:fixtures:load -n

db-reset: ## Recrée la base de dev de zéro : drop, create, migrate, fixtures
	$(CONSOLE) doctrine:database:drop --force --if-exists
	$(CONSOLE) doctrine:database:create
	$(CONSOLE) doctrine:migrations:migrate -n
	$(CONSOLE) doctrine:fixtures:load -n

## —— Qualité —————————————————————————————————————————————————————————————
test: ## Lance les tests (ex : make test f=CityTest pour filtrer)
	$(PHP) bin/phpunit $(if $(f),--filter $(f))

test-dox: ## Lance les tests avec le détail de chaque test
	$(PHP) bin/phpunit --testdox

phpstan: ## Analyse statique
	vendor/bin/phpstan analyse --memory-limit=1G

lint: ## Lint du container, des templates Twig et de la config YAML
	$(CONSOLE) lint:container
	$(CONSOLE) lint:twig templates/
	$(CONSOLE) lint:yaml config/

check: lint phpstan db-validate test ## Tout vérifier avant un commit
