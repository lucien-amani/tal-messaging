# TAL Messaging — Architecture & Module 1 (Core Foundation)

PHP 8.1+ MVC sans framework ni dépendance Composer · MySQL 8 · Tailwind CSS **local** (build npm) · icônes Tabler (police locale) · AJAX (fetch) · aucune ressource CDN.

## Arborescence

```
tal-messaging/
├── public/                 # seul dossier exposé (document root)
│   ├── index.php  .htaccess
│   └── assets/{css/app.css (généré), js/app.js, js/qr.js, js/theme-init.js, fonts/ et vendor/ (copiés par npm)}
├── app/
│   ├── Core/               # App, Router, Request/Response, Session (DB), Validator, ErrorHandler
│   ├── Middleware/         # session, auth, tenant, perm, csrf, guest, audit
│   ├── Tenancy/            # TenantContext (isolation fail-closed)
│   ├── Repositories/       # TenantRepository (filtre organization_id imposé) + dérivés
│   ├── Services/           # AuthService, AuditService, FeatureFlagService
│   ├── Controllers/  Views/  Support/  Modules/ (modules futurs)
├── config/                 # app, database, permissions (matrice), dashboards, routes
├── database/               # migrations/*.sql, migrate.php, seed.php
├── resources/css/input.css # source Tailwind
├── docker/  Dockerfile  docker-compose.yml
├── scripts/init-env.php    # génère .env avec secrets aléatoires
└── tests/                  # run.php (unitaires), e2e.php
```

## Démarrage — Docker

```bash
php scripts/init-env.php          # crée .env (APP_KEY, mots de passe DB aléatoires)
docker compose up --build         # MySQL + app, migrations et seed automatiques
```
Application : http://localhost:8080 · santé : `/health`. Les identifiants seed sont affichés **une seule fois** dans les logs : `docker compose logs web | grep -A6 IDENTIFIANTS`.

## Démarrage — XAMPP (Windows, config par défaut, sans Docker)

1. Placer le projet dans `C:\xampp\htdocs\tal-messaging`, démarrer **Apache** et **MySQL** dans le panneau XAMPP.
2. Créer la base (phpMyAdmin ou console) : `CREATE DATABASE tal_messaging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. Dans le dossier du projet : `php scripts/init-env.php`, puis éditer `.env` : `DB_HOST=127.0.0.1`, `DB_USER=root`, `DB_PASSWORD=` (vide par défaut sous XAMPP).
4. `npm install` (installe Tailwind, les icônes Tabler et les polices, puis les copie dans `public/assets`) puis `npm run build`.
5. **Double-cliquer sur `install.bat`** (migrations + données initiales + diagnostic), ou en console `php database/migrate.php && php database/seed.php`. **Ne pas importer `migrate.php` / `seed.php` dans phpMyAdmin** : ce sont des scripts PHP, pas du SQL. En cas de souci : `php scripts/doctor.php`.
   `php database/migrate.php && php database/seed.php` (relancer le seed après chaque mise à jour : il synchronise les permissions).
6. Ouvrir **http://localhost/tal-messaging/public/** (ou http://localhost/tal-messaging/, qui redirige vers `public/`).

L'application détecte seule son sous-dossier (`/tal-messaging/public`) : routage, redirections, cookies, CSS/JS et appels AJAX en tiennent compte.
Si besoin, forcer le préfixe avec `APP_BASE_PATH` dans `.env` (`/` pour une installation à la racine, ex. VirtualHost).
Prérequis Apache : `mod_rewrite` activé et `AllowOverride All` (valeurs par défaut de XAMPP).

## Interface, thème et palette

- Icônes : police **Tabler** (`@tabler/icons-webfont`), polices **Inter** (texte) et **Plus Jakarta Sans** (titres) — tout est local, rien n'est chargé depuis un CDN.
- Thème clair / sombre / automatique (bouton dans le header, mémorisé par utilisateur). Aucun dégradé.
- Le **super admin (CEO)** règle la palette de la plateforme dans **Apparence** (`/appearance`, permission `manage_appearance`) :
  8 couleurs par mode, préréglages, aperçu en direct, mode par défaut, réinitialisation. Les couleurs sont servies par `/theme.css`.
- Les sélecteurs natifs `<select>` sont remplacés par un sélecteur moderne : ajouter `data-ui="select"` à un `<select>`.
- Ajouter une icône : `icon('nom-tabler')` (catalogue : https://tabler.io/icons).

## Module 2 — Contacts Engine

**Mise à jour d'une installation existante** : `php database/migrate.php` (migration 004), `php database/seed.php` (synchronise les nouvelles permissions `view_contacts`, `export_contacts`, `manage_data_retention`), puis `npm run build` (nouveaux composants CSS).

Pages : `/contacts`, `/contacts/groups`, `/contacts/segments`, `/contacts/imports` et le dashboard **Data & Compliance** (`/data`).

- **Numéros** : normalisation E.164 (`app/Support/PhoneNumber.php`), opérateur RDC détecté par préfixe (`config/countries.php`, **à valider avec vos partenaires télécoms**).
- **Import CSV** : envoi → mappage → traitement par lots de `contacts.import.chunk_size` lignes (reprenable, sans Redis) → rapport + CSV des rejets. Fichiers dans `storage/imports/{organisation}/` (non servis par Apache).
- **Doublons** : à l'import (ignorer / mettre à jour / compléter), dans le fichier, et sur la base existante (fusion).
- **Segments** : règles enregistrées (liste blanche de champs et d'opérateurs, SQL paramétré) avec compteur en direct.
- **Export CSV** : permission `export_contacts`, chaque export est tracé dans l'audit.
- **Conservation** : durées réglables dans le dashboard Data ; purge via le bouton (compte interne) ou `php scripts/purge-data.php` (à planifier chaque nuit).
- Limites d'import (taille, lignes) : `config/contacts.php` ; côté PHP, `upload_max_filesize` et `post_max_size` doivent être au moins égales.

## Module 3 — Sender IDs

Nom d'expéditeur affiché chez le destinataire. Le client le demande, l'équipe TAL (Operations, CEO) le valide.

- Page `/sender-ids` : demande, correction d'un rejet (renvoi), annulation, Sender ID par défaut, historique ; file « En attente » pour les validateurs (approuver, rejeter ou suspendre avec motif, réactiver).
- Règles : 3 à 11 caractères (lettres, chiffres, `espace . _ -`, au moins une lettre, sans accent) ; noms d'opérateurs, banques et institutions réservés (`config/sender_ids.php`) ; nom unique par pays tant qu'il est actif ou en attente ; limites par organisation (10 au total, 5 en attente).
- Le premier Sender ID approuvé devient le défaut. Seuls les Sender IDs approuvés seront utilisables par le futur moteur d'envoi (`SenderIdService::approved()`).
- Permissions : `view_sender_ids` (tous les rôles clients, CTO, Sales), `manage_sender_ids` (owner, admin, campaign_manager, Operations), `review_sender_ids` (interne uniquement : Operations, CEO).

**Mise à jour depuis la version précédente** (dans le dossier du projet) :

```
php database/migrate.php     (applique 005_sender_ids.sql)
php database/seed.php        (synchronise les nouvelles permissions)
npm run build                (recompile le CSS)
```

## Module 4 — Messaging Engine (Quick Send, Message Center, worker d'envoi)

- **Quick Send** (`/messages/quick-send`) : Sender ID approuvé, destinataires (numéros saisis, groupes, segments), texte avec variables `{{first_name|client}}` (valeur de repli après `|`), envoi immédiat ou programmé (2 minutes à 30 jours), aperçu en direct (destinataires, SMS facturables, numéros invalides, bloqués, doublons) et confirmation avant envoi. Limite : 5 000 destinataires par envoi rapide (les grandes listes passeront par les campagnes).
- **Message Center** (`/messages`) : suivi message par message et par envoi, annulation de ce qui est encore en file, rafraîchissement automatique.
- **Comptage SMS** : GSM-7 (160 caractères, 153 par segment au-delà) ou Unicode dès qu'un accent hors alphabet GSM ou un emoji apparaît (70 / 67). Le nombre de segments est enregistré sur chaque message : le module Wallet s'en servira pour la facturation.
- **Sécurité** : le contexte tenant borne tout (Sender ID, groupes, segments, messages) ; un Sender ID non approuvé est refusé à l'envoi *et* au moment de l'expédition ; les contacts bloqués sont exclus ; une clé d'idempotence évite l'envoi en double (double clic) ; le texte et les numéros ne sont pas copiés dans le journal d'audit.
- **Fournisseur** : `MESSAGING_PROVIDER=auto` (défaut) utilise les fournisseurs configurés dans **Fournisseurs** ; tant qu'aucun n'est activé et complet, le simulateur répond (aucun SMS réel). `simulator` coupe l'envoi réel. Selon les deux derniers chiffres du numéro : `…99` échec définitif, `…98` échec temporaire (3 tentatives, à 1, 5 puis 15 minutes), `…97` envoyé sans accusé, autre : livré. 
- **Worker** : les messages sont envoyés par un processus à part. Sous XAMPP, double-cliquez sur `run-worker.bat` et laissez la fenêtre ouverte (ou `php scripts/worker.php --loop`). En production : `php scripts/worker.php` chaque minute via cron, ou en boucle sous Supervisor.
- Permissions : `send_messages` (envoyer, annuler), `view_messages` (consulter ; tous les rôles clients, CTO, Operations, Support).

Mise à jour depuis la v4 : `php database/migrate.php` (006), `php database/seed.php` (permission `view_messages`), `npm run build`.

## Module 5 — Campagnes

- **Campagnes** (`/campaigns`) : un brouillon (nom, Sender ID, groupes et segments, texte, programmation) que l'on prépare, enregistre, duplique, lance ou programme. Jusqu'à 50 000 destinataires par campagne (`config/campaigns.php`).
- **Lancement** : même aperçu et mêmes règles qu'un Quick Send (Sender ID approuvé, contacts bloqués exclus, doublons retirés, variables avec repli). Le lancement crée un envoi (source `campaign`) que le worker expédie. Un verrou empêche de lancer deux fois la même campagne.
- **Statuts** : brouillon, programmée, en cours, terminée, annulée. Ils sont déduits de l'état réel des messages ; une campagne annulée n'envoie plus ce qui n'était pas encore parti.
- **Rapport** (`/campaigns/{id}`) : livrés, envoyés sans accusé, échecs, avancement, répartition par opérateur, échecs les plus fréquents, export CSV (téléphones protégés contre l'injection de formules), mise à jour automatique tant que la campagne tourne.
- **Modèles de message** : textes réutilisables par organisation (bouton « Modèles », ou « Enregistrer comme modèle » dans le formulaire).
- Permissions : `manage_campaigns` (créer, lancer, annuler, supprimer), `view_campaigns` (consulter ; tous les rôles clients, CTO, Operations, Sales, Support).

Mise à jour depuis la v5 : `php database/migrate.php` (007), `php database/seed.php` (permission `view_campaigns`), `npm run build`. Le worker (`run-worker.bat`) doit tourner pour que les messages partent.

## Module 6 — Wallet, tarifs, recharges et factures

- **Prépayé** : un envoi n'est accepté que si le solde disponible couvre son coût (sinon erreur 402 « Solde insuffisant »). À la mise en file, le coût de chaque message est **réservé** ; il est **débité** quand le fournisseur accepte le message, et **libéré** s'il échoue ou est annulé. Les messages font foi : un arrêt brutal du worker ne fait jamais perdre d'argent (le règlement se rattrape au passage suivant).
- **Montants exacts** : tout est stocké en entiers (micro-unités, 1 000 000 = 1,00), sans calcul en virgule flottante. Devise par défaut : USD (`config/billing.php`).
- **Tarifs** (`/billing/pricing`, équipe TAL) : prix par SMS par pays et par opérateur, tarifs négociés par client. Le plus précis gagne. Tarifs de départ : 0,04 USD par défaut, 0,035 USD en RDC, modifiables.
- **Recharges** : le client déclare son paiement (Mobile Money, virement, espèces) avec la référence ; l'équipe TAL le confirme, ce qui crédite le wallet et émet la facture dans une seule transaction. Une référence déjà créditée ne peut pas l'être deux fois.
- **Ajustement manuel** (CEO) : crédit offert ou correction, avec motif obligatoire, visible du client et tracé dans l'audit.
- **Wallet** (`/billing`) : solde, réservé, dépensé et rechargé sur 30 jours, transactions, demandes de recharge, factures imprimables, tarifs appliqués. L'équipe TAL a une vue d'ensemble de tous les clients et la liste des recharges à confirmer.
- **Quick Send et campagnes** affichent le coût avant l'envoi et bloquent le bouton si le solde est insuffisant.
- Permissions : `view_wallet`, `manage_billing` (client : demander une recharge), `approve_payments`, `adjust_wallets`, `manage_pricing` (équipe TAL uniquement).

Mise à jour depuis la v6 : `php database/migrate.php` (008), `php database/seed.php` (nouvelles permissions), `npm run build`. Un nouveau client a un solde de 0 : créditez-le (recharge confirmée ou ajustement) avant d'envoyer.

## Module 7 — Fournisseurs et router (CEO/CTO, `manage_providers`)
Page `/providers` : Africa's Talking et Twilio (clés chiffrées AES-256-GCM, jamais réaffichées ; champ secret vide = valeur conservée), priorité, pays desservis, activation, « Tester la connexion », URL d'accusés de réception (secret dans l'URL, régénérable). Le router essaie les fournisseurs activés+complets par priorité et bascule sur le suivant en cas de panne fournisseur. **Sans fournisseur actif : simulation.** Accusés : `POST /webhooks/providers/{code}/{secret}` (public, sans session). HTTPS cURL ; sous XAMPP renseignez `CURL_CA_BUNDLE`.

## Module 8 — API publique v1 et Developer Center (`manage_api_keys`)
Clés `tal_live_…` (seule l'empreinte SHA-256 est stockée, affichage unique), droits `send`/`read`, révocation, 60 req/min/clé (`API_RATE_PER_MINUTE`). Endpoints : `POST /api/v1/messages`, `GET /api/v1/messages/{id}`, `GET /api/v1/batches/{id}`, `GET /api/v1/balance` (Bearer, idempotence via `Idempotency-Key`). Page `/developer`.

## Module 9 — Support, Équipe, Clients, Pipeline
`/tickets` (client : ouvrir/suivre ; équipe : traiter, assigner, notes internes, recherche message/campagne), `/team` (utilisateurs, rôles, mot de passe temporaire), `/clients` (fiche client, création d'organisation, statut), `/pipeline` (prospects du site public).

## Dashboards et site public
Les 7 dashboards (CEO, CTO, Operations, Sales, Data, Support, Client) affichent des données réelles et des accès rapides filtrés par permission. Site public : `/` (accueil, redirige vers `/dashboard` si connecté), `/tarifs` (tarifs par défaut réels), `/documentation`, `/contact` (→ pipeline, anti-spam : champ piège, jeton CSRF, 5 demandes/h/IP). `SEED_DEMO_USERS=true` crée un compte par rôle pour tout tester.

## Tests

```bash
php tests/run.php                                   # unitaires (sans base)
E2E_PASSWORD='...' php tests/e2e.php                # e2e (serveur lancé + base seedée)
```

## Sécurité intégrée

Argon2id (bcrypt en repli) · sessions serveur en base (ID hashé, cookie HttpOnly/SameSite, régénération au login, expiration inactivité/absolue, liaison User-Agent) · CSRF sur toute écriture · 2FA TOTP maison (secret chiffré AES-256-GCM, anti-rejeu, codes de récupération, **QR code d’enrôlement généré localement dans le navigateur**, `public/assets/js/qr.js`), **obligatoire pour les comptes internes** · anti brute-force (compte + IP) · requêtes préparées uniquement · échappement systématique des vues · CSP stricte `default-src 'self'` (pas de script/style inline, pas de CDN) · en-têtes de sécurité · erreurs typées sans stack trace · audit append-only avec masquage des secrets · anti-escalade de rôles.

## Isolation multi-tenant

Le filtre `organization_id` n'est **jamais** écrit dans un contrôleur : il est imposé par `TenantRepository`, alimenté par `TenantContext` (initialisé par `TenantMiddleware` après authentification). Sans contexte → exception (fail-closed). Un utilisateur client ne peut pas élargir son périmètre même avec des permissions étendues ; `organization_id` d'une ligne n'est jamais modifiable ; un accès hors périmètre répond **404**. Les seules requêtes non scopées : résolution d'identité au login et données de référence (rôles/permissions).

Voir `API_DOC.md` pour les endpoints et `app/Modules/README.md` pour ajouter les modules suivants.
