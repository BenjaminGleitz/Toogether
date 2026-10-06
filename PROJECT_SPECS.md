# Toogether — Spécifications

## Description générale

Application web de **gestion d'événements** permettant à des utilisateurs de créer, consulter et
rejoindre des événements localisés par ville et catégorie. La page d'un événement se met à jour
en temps réel (participants, places restantes).

---

## Stack technique

- **Framework :** Symfony 8.1 (`--webapp`)
- **PHP :** 8.4+
- **Base de données :** PostgreSQL 16 (via `compose.yaml`)
- **ORM :** Doctrine ORM, schéma géré **uniquement** par migrations
- **Templating :** Twig + AssetMapper (pas d'API REST)
- **Front interactif :** Symfony UX Turbo
- **Temps réel :** Mercure (`symfony/mercure-bundle`) via Turbo Streams
- **Authentification :** SecurityBundle, `form_login` (email + mot de passe), remember me, login throttling
- **Comptes :** `symfonycasts/verify-email-bundle` (confirmation d'email), `symfonycasts/reset-password-bundle` (mot de passe oublié)
- **Emails :** Symfony Mailer, envoyés en asynchrone via Messenger
- **Tâches planifiées :** Symfony Scheduler
- **Temps :** composant Clock (`ClockInterface`), jamais `new \DateTimeImmutable()` dans le code métier
- **Upload :** natif Symfony (`FileType` + service `FileUploader`), pas de VichUploaderBundle
- **Tests :** PHPUnit + Zenstruck Foundry (factories) + DoctrineFixturesBundle

---

## Enums

```php
enum EventStatus: string { case Open = 'OPEN'; case Closed = 'CLOSED'; }
enum Gender: string { case Female = 'FEMALE'; case Male = 'MALE'; case Other = 'OTHER'; }
```

Mappés en Doctrine avec `enumType`, affichés dans les formulaires avec `EnumType`.

---

## Entités

### User
| Champ | Type | Contraintes |
|---|---|---|
| id | int | PK, auto |
| email | string(180) | unique, required, `Assert\Email` |
| password | string | hashé, required |
| roles | array (json) | défaut : `ROLE_USER` |
| isVerified | bool | défaut : `false` (verify-email-bundle) |
| firstname | string | required |
| lastname | string | required |
| gender | `Gender` | required |
| birthdate | DateTimeImmutable | required, doit être dans le passé |
| nationality | string | nullable |
| description | text | nullable |
| image | string | nullable (nom du fichier) |
| createdAt | DateTimeImmutable | auto (Clock) |
| updatedAt | DateTimeImmutable | nullable, auto (Clock) |

**Relations :**
- `favoriteCity` → ManyToOne → City (nullable)
- `eventsCreated` → OneToMany → Event (mappedBy `creator`)
- `events` → ManyToMany → Event (mappedBy `participants`)

---

### Event
| Champ | Type | Contraintes |
|---|---|---|
| id | int | PK, auto |
| title | string(50) | required |
| description | text | required |
| startAt | DateTimeImmutable | required, doit être dans le futur (à la création) |
| participantLimit | int | nullable, `Assert\Positive` |
| status | `EventStatus` | défaut : `Open` |
| createdAt | DateTimeImmutable | auto (Clock) |
| updatedAt | DateTimeImmutable | nullable, auto (Clock) |

**Relations :**
- `city` → ManyToOne → City (required)
- `category` → ManyToOne → Category (required)
- `creator` → ManyToOne → User (required)
- `participants` → ManyToMany → User (côté propriétaire, table `event_user`)

Le pays n'est **pas** stocké sur l'événement : il se lit via `event.city.country`.

---

### Category
| Champ | Type | Contraintes |
|---|---|---|
| id | int | PK, auto |
| title | string(50) | required, unique |
| image | string | nullable |

**Relations :**
- `events` → OneToMany → Event

---

### Country
| Champ | Type | Contraintes |
|---|---|---|
| id | int | PK, auto |
| name | string(50) | required |
| countryCode | string(10) | required, unique (code ISO) |

**Relations :**
- `cities` → OneToMany → City

---

### City
| Champ | Type | Contraintes |
|---|---|---|
| id | int | PK, auto |
| name | string(50) | required |

**Relations :**
- `country` → ManyToOne → Country (required)
- `events` → OneToMany → Event
- `users` → OneToMany → User (en tant que `favoriteCity`)

---

### Suppressions et intégrité

- Pas d'`orphanRemoval` ni de cascade remove entre référentiels et événements.
- On **ne peut pas supprimer** une Category ou une City qui a encore des événements, ni un
  Country qui a encore des villes : le backoffice affiche une erreur.
- Supprimer une City met `favoriteCity` à `NULL` pour les utilisateurs concernés
  (`onDelete: SET NULL`).
- Supprimer un User n'est pas prévu dans cette version.

Le schéma SQL est généré par Doctrine (`make:migration`) : il n'est pas décrit à la main ici.

---

## Pages et fonctionnalités

### Publiques (sans connexion)
- **Accueil** : liste des événements `Open` à venir, triés par date
- **Détail d'un événement** : infos, liste des participants, places restantes (mis à jour en
  temps réel via Mercure)
- **Login** : formulaire email/mot de passe, avec « se souvenir de moi »
- **Inscription** : création de compte, puis email de confirmation
- **Mot de passe oublié** : demande de lien de réinitialisation par email

### Utilisateur connecté (ROLE_USER, email vérifié)
- **Créer un événement** : formulaire avec ville, catégorie, date, limite de participants
- **Modifier son événement** : uniquement si créateur et événement `Open`
- **Supprimer son événement** : uniquement si créateur
- **Rejoindre un événement** : bouton participer (voir règles ci-dessous), dans une Turbo Frame
- **Quitter un événement** : bouton se désinscrire, dans une Turbo Frame
- **Mes événements** : liste des événements créés
- **Mes participations** : liste des événements rejoints
- **Événements dans ma ville favorite** : filtre automatique
- **Modifier son profil** : infos personnelles + photo de profil
- **Filtrer les événements** : par pays, ville, catégorie, date minimum (formulaire GET,
  `#[MapQueryString]`)

Un utilisateur dont l'email n'est pas vérifié peut se connecter et consulter, mais pas créer ni
rejoindre d'événement.

### Administrateur (ROLE_ADMIN)
- **Backoffice** : CRUD complet sur Category, City, Country (généré avec `make:crud`, sous
  `/admin`)

---

## Logique métier

### Statut des événements
- Un événement est **clos** dès que `maintenant > startAt + 10 minutes`.
- Une tâche **Scheduler** s'exécute chaque minute et passe en `Closed` les événements `Open`
  concernés. Aucune écriture en base pendant une simple consultation (GET).
- Les règles métier n'attendent pas la tâche : `Event::isClosed(ClockInterface)` vérifie aussi
  l'heure, ce qui évite une fenêtre d'une minute où un événement terminé serait encore joignable.

### Participation
Toutes ces règles sont centralisées dans un **Voter** `EventVoter` (attributs `EDIT`, `DELETE`,
`JOIN`, `LEAVE`), utilisé dans les contrôleurs (`#[IsGranted]`) et dans Twig (`is_granted()`).

- Le créateur est **automatiquement ajouté** comme premier participant à la création.
- Le créateur **ne peut pas quitter** son propre événement (il doit le supprimer).
- Un utilisateur **ne peut pas** rejoindre deux fois le même événement.
- Si `participantLimit` est défini, on ne peut pas dépasser la limite.
- On ne peut ni rejoindre ni quitter un événement clos.
- **Concurrence :** l'inscription verrouille la ligne de l'événement (`PESSIMISTIC_WRITE` dans
  une transaction) avant de recompter les participants, pour que deux inscriptions simultanées
  ne dépassent pas la limite.

### Création et modification d'un événement
1. L'utilisateur choisit une ville (le pays s'en déduit).
2. Le créateur est défini sur l'utilisateur connecté et ajouté comme participant.
3. Le statut est initialisé à `Open`.
4. À la modification, `participantLimit` ne peut pas descendre en dessous du nombre actuel de
   participants (contrainte de validation).
5. Un événement clos ne peut plus être modifié.

### Temps réel (Mercure)
- La page détail s'abonne au topic de l'événement (`<twig:Turbo:Stream:Listen>` / `turbo_stream_listen()`).
- Après un join/leave/modification, le serveur publie un Turbo Stream qui met à jour la liste
  des participants et le compteur de places restantes pour tous les visiteurs de la page.

### Emails (Mailer + Messenger async)
- Confirmation d'adresse email à l'inscription.
- Lien de réinitialisation de mot de passe.

### Upload d'image
- Champ `FileType` non mappé dans le formulaire de profil, avec contrainte
  `Assert\Image(maxSize: '2M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'])`.
- Un service `FileUploader` renomme le fichier (slug + UUID via `symfony/uid`), le déplace dans
  `public/uploads/users/` (dossier git-ignoré, répertoire configuré via `#[Autowire]`), et
  supprime l'ancienne photo quand elle est remplacée.

---

## Sécurité

| Rôle | Accès |
|---|---|
| Anonyme | Accueil, détail événement, login, inscription, mot de passe oublié |
| ROLE_USER | Toutes les pages utilisateur (création/participation : email vérifié) |
| ROLE_ADMIN | Backoffice `/admin` (catégories, villes, pays) |

- Authentification par formulaire (`form_login`), remember me.
- `login_throttling` activé (RateLimiter) contre le brute force.
- Hash des mots de passe : `auto`.
- Protection CSRF sur tous les formulaires et sur les boutons d'action (join/leave/delete en POST).
- Un utilisateur ne peut modifier que son propre profil (la route profil agit toujours sur
  l'utilisateur connecté, pas d'ID dans l'URL).
- Seul le créateur peut modifier/supprimer un événement (`EventVoter`).

---

## Tests

- **Foundry** : une factory par entité (`UserFactory`, `EventFactory`, …), réutilisée par les
  fixtures et par les tests.
- **Fixtures** de démo : pays, villes, catégories, quelques utilisateurs (dont un admin) et
  événements passés et futurs.
- **Tests unitaires** : `EventVoter` (toutes les règles de participation), `Event::isClosed()`
  avec une `MockClock`.
- **Tests fonctionnels** (`WebTestCase`) : inscription, login, création d'événement,
  join/leave, accès refusé aux non-créateurs et aux non-admins.
- **Test du Scheduler** : la tâche ferme bien les événements dépassés (avec `MockClock`).

---

## Structure des templates Twig (suggestion)

```
templates/
├── base.html.twig
├── security/
│   ├── login.html.twig
│   └── register.html.twig
├── reset_password/            # généré par make:reset-password
├── event/
│   ├── index.html.twig        # liste + filtres
│   ├── show.html.twig         # détail + abonnement Mercure
│   ├── _participants.html.twig  # fragment mis à jour par Turbo Stream
│   ├── _join_button.html.twig   # Turbo Frame join/leave
│   ├── new.html.twig
│   └── edit.html.twig
├── user/
│   ├── profile.html.twig
│   ├── my_events.html.twig
│   └── my_participations.html.twig
├── admin/
│   ├── index.html.twig
│   ├── category/
│   ├── city/
│   └── country/
└── email/
    └── confirmation_email.html.twig
```

---

## Évolutions possibles (hors scope v1)

- Pagination de la liste d'événements (Doctrine Paginator ou Pagerfanta)
- Filtres dynamiques avec UX Live Components, sélection de ville avec UX Autocomplete
- Carte des événements avec UX Map (Leaflet), nécessite `latitude`/`longitude` sur City
- Pays pré-remplis depuis Symfony Intl (`Countries::getNames()`)
- Backoffice avec EasyAdmin à la place de `make:crud`
- Notifications par email (rappel la veille, événement modifié/annulé)
