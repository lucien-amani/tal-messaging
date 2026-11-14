# API interne — Module 1 (Core Foundation)

Authentification par **session cookie** (`tal_session`, HttpOnly). Toute requête modifiante envoie `X-CSRF-Token` (valeur de `<meta name="csrf-token">`, renouvelée à la connexion) et `Content-Type: application/json`.

Succès : `{ "data": ... }` (listes : `{ "data": [...], "meta": { "page", "per_page", "total" } }`).
Erreur : `{ "error": { "code": "...", "message": "...", "details": { "fields": { "champ": ["..."] } } } }`.

| Code HTTP | `error.code` |
|---|---|
| 400 | BAD_REQUEST |
| 401 | AUTH_REQUIRED, INVALID_CREDENTIALS, TWO_FACTOR_INVALID, TWO_FACTOR_EXPIRED |
| 403 | FORBIDDEN, PERMISSION_DENIED, CSRF_INVALID, ACCOUNT_DISABLED, SECURITY_SETUP_REQUIRED, ROLE_ESCALATION, SELF_MODIFICATION, NO_TENANT_SCOPE, TWO_FACTOR_REQUIRED |
| 404 | NOT_FOUND (inclut tout accès hors périmètre tenant) |
| 409 | CONFLICT, EMAIL_TAKEN, LAST_OWNER, TWO_FACTOR_ALREADY_ENABLED |
| 422 | VALIDATION_FAILED |
| 429 | RATE_LIMITED |
| 500 | INTERNAL_ERROR |

## Public

| Méthode | Route | Description |
|---|---|---|
| GET | `/health` | `{"status":"ok","checks":{"database":"up"}}` (503 si base indisponible) |
| GET | `/login` | Page de connexion |
| POST | `/api/auth/login` | `{"email","password"}` → `{"data":{"requires_2fa":false,"csrf_token":"…","redirect":"/"}}` ou `{"requires_2fa":true}` |
| POST | `/api/auth/2fa/verify-login` | `{"code":"123456"}` (ou code de récupération `abcde-12345`) → comme login |

## Compte connecté (autorisé même si 2FA / changement de mot de passe à finaliser)

| Méthode | Route | Description |
|---|---|---|
| GET | `/api/auth/me` | Profil, rôle, organisation, permissions, `security_setup_required` |
| POST | `/api/auth/logout` | Détruit la session |
| POST | `/api/auth/password` | `{"current_password","new_password"}` — révoque les autres sessions |
| POST | `/api/auth/2fa/setup` | → `{"secret","otpauth_uri"}` |
| POST | `/api/auth/2fa/enable` | `{"code":"123456"}` → `{"enabled":true,"recovery_codes":[8 codes],"csrf_token"}` |
| POST | `/api/auth/2fa/disable` | `{"password","code"}` — clients uniquement (403 pour les internes) |

## Organisations

| Méthode | Route | Permission | Notes |
|---|---|---|---|
| GET | `/api/organizations?q=&status=&page=&per_page=&sort=&dir=` | view_organization | Client : sa seule organisation |
| POST | `/api/organizations` | manage_organizations | Voir ci-dessous |
| GET | `/api/organizations/{id}` | view_organization | 404 hors périmètre |
| PUT | `/api/organizations/{id}` | manage_organizations (tous champs) ou manage_organization_settings (name, email, phone) | |
| DELETE | `/api/organizations/{id}` | manage_organizations | Suppression logique + révocation des sessions |

```json
POST /api/organizations
{ "name": "Boutique Kivu", "email": "contact@kivu.cd", "phone": "+243970000000", "country": "CD",
  "owner_email": "patron@kivu.cd", "owner_first_name": "Jean", "owner_last_name": "Mbuyi" }

201 { "data": { "organization": { "id": 3, "uuid": "…", "slug": "boutique-kivu", "status": "active", … },
                "owner_user_id": 4, "temporary_password": "Xk9…(affiché une seule fois)" } }
```

## Utilisateurs (permission `manage_team`)

| Méthode | Route | Notes |
|---|---|---|
| GET | `/api/users?q=&status=&organization_id=&scope=internal` | `organization_id`/`scope` : contexte global uniquement |
| POST | `/api/users` | `{"email","first_name","last_name","role","organization_id?","password?"}` — client : organisation forcée ; interne : `organization_id` requis pour un rôle client ; rôle interne requiert `manage_internal_users` |
| GET | `/api/users/{id}` | |
| PUT | `/api/users/{id}` | `first_name`, `last_name`, `role`, `status` (`active`/`suspended`) |
| DELETE | `/api/users/{id}` | Suppression logique |

Règles : pas d'attribution d'un rôle supérieur au sien ; Owner attribuable uniquement par un Owner ; pas d'auto-modification de rôle/statut ; au moins un Owner actif par organisation. Un nouvel utilisateur reçoit `must_change_password = 1`.

## Rôles & permissions (lecture)

| Méthode | Route | Permission |
|---|---|---|
| GET | `/api/roles` | manage_team (rôles assignables ; matrice complète si view_roles) |
| GET | `/api/permissions` | view_roles |

## Audit

`GET /api/audit-logs?action=&user_id=&resource_type=&from=&to=&organization_id=&page=&per_page=` — permission `view_audit_logs`. Client : uniquement le journal de son organisation.

```json
{ "data": [ { "id": 42, "user_id": 2, "organization_id": 1, "action": "users.create", "resource_type": "user",
              "resource_id": "5", "ip": "41.x.x.x", "user_agent": "…", "metadata": { "status": 201 },
              "created_at": "2026-11-07 00:30:12.123" } ], "meta": { "page": 1, "per_page": 25, "total": 1 } }
```
Actions journalisées : `auth.login`, `auth.login_failed`, `auth.login_blocked`, `auth.2fa_failed`, `auth.logout`, toute écriture (`organizations.*`, `users.*`, `flags.update`, `security.*`) et chaque refus 403/404 sur une ressource (`*.denied`, ex. tentative cross-tenant).

## Feature flags

| Méthode | Route | Permission |
|---|---|---|
| GET | `/api/feature-flags/effective` | authentifié — `{"campaigns_module":false,…}` pour l'organisation courante |
| GET | `/api/feature-flags` | view_feature_flags |
| PUT | `/api/feature-flags/{key}` | manage_feature_flags — `{"enabled":true,"organization_id":3?}` (surcharge par organisation si fourni) |

## Thème (permission `manage_appearance`, réservé au super admin)

| Méthode | Route | Description |
|---|---|---|
| GET | `/theme.css` | Variables CSS du thème (public, utilisé par toutes les pages) |
| GET | `/api/theme` | Palette courante, mode par défaut et valeurs d'origine |
| PUT | `/api/theme` | `{ "default_mode": "light\|dark\|system", "palette": { "light": { "brand": "#4F46E5", … }, "dark": { … } } }` |
| POST | `/api/theme/reset` | Revient à la palette d'origine |

Jetons : `canvas`, `surface`, `surface2`, `line`, `ink`, `mute`, `brand`, `brandfg` (couleurs `#RRGGBB`).


## Contacts (permission `view_contacts` en lecture, `manage_contacts` en écriture)
Toutes les routes sont filtrées par organisation (ressource hors périmètre → 404). Un compte interne global doit fournir `organization_id` pour créer.

| Méthode | Route | Description |
|---|---|---|
| GET | `/api/contacts` | Liste paginée. Filtres : `q`, `operator`, `status`, `country`, `source`, `tag`, `group_id`, `import_id`, `no_group`, `has_email`, `created_from`, `created_to`, `organization_id` (global) ; `sort` (`created_at`, `name`, `phone`), `dir`, `page`, `per_page` |
| POST | `/api/contacts` | Créer `{phone, country?, first_name?, last_name?, email?, status?, tags?, group_ids?, attributes?}` → 409 `CONTACT_EXISTS` si le numéro existe |
| GET/PUT/DELETE | `/api/contacts/{id}` | Fiche / modification / suppression (logique) |
| GET | `/api/contacts/{id}/history` | Historique des modifications |
| POST | `/api/contacts/bulk` | `{action: delete\|block\|unblock\|group_add\|group_remove\|tag_add\|tag_remove, ids[] ou rules, group_id?, tag?}` |
| POST | `/api/contacts/count` | `{rules}` → nombre + échantillon |
| GET | `/api/contacts/duplicates?by=phone_tail\|email\|name` · POST `/api/contacts/merge` `{keep_id, merge_ids[]}` | Doublons et fusion |
| GET | `/api/contacts/stats` · `/api/contacts/tags` | Indicateurs, catalogue d'étiquettes |
| GET | `/api/contacts/export` | CSV (permission `export_contacts`, audité) |
| GET/POST/PUT/DELETE | `/api/contact-groups[/{id}]` | Groupes `{name, description?}` |
| GET/POST/PUT/DELETE | `/api/segments[/{id}]` | Segments `{name, description?, rules}` ; `rules = {match: all\|any, conditions:[{field, op, value}]}` |
| GET | `/api/contact-imports` · `/api/contact-imports/{id}` | Historique / progression |
| POST | `/api/contact-imports` | Envoi (multipart, champ `file`) → aperçu et mappage suggéré |
| POST | `/api/contact-imports/{id}/start` | `{mapping:{phone,first_name,last_name,email,country,tags,custom:[{key,column}]}, options:{duplicate_mode: skip\|update\|merge, default_country, group_id?, new_group_name?, tags?}}` |
| POST | `/api/contact-imports/{id}/process` | Traite un lot ; à rappeler tant que `status = processing` |
| POST | `/api/contact-imports/{id}/cancel` | Annule |
| GET | `/api/contact-imports/{id}/errors` · `/errors.csv` | Lignes rejetées (`kind=invalid\|duplicate`) / CSV pour correction |

## Data & Compliance (`access_dashboard_data`)
`GET /api/data/stats`, `GET/PUT /api/data/retention` (écriture : `manage_data_retention`), `POST /api/data/purge` (interne global).

## Sender IDs (`view_sender_ids` en lecture, `manage_sender_ids` en écriture, `review_sender_ids` pour la validation)

| Méthode | Route | Description |
|---|---|---|
| GET | `/api/sender-ids` | Liste paginée. Filtres : `status` (`pending`, `approved`, `rejected`, `suspended`), `q`, `organization_id` (global), `sort`, `dir`, `page`, `per_page` |
| GET | `/api/sender-ids/approved` | Sender IDs utilisables pour un envoi, défaut en premier |
| GET | `/api/sender-ids/stats` | Compteurs par statut, délai moyen de validation, plus ancienne demande |
| GET | `/api/sender-ids/{id}` | Détail |
| GET | `/api/sender-ids/{id}/events` | Historique (demande, décisions, motifs) |
| POST | `/api/sender-ids` | Demander `{name, country?, purpose?, description?, sample_message?, organization_id? (global)}` → 422 `SENDER_ID_RESERVED`, 409 `SENDER_ID_TAKEN`, `SENDER_ID_LIMIT`, `SENDER_ID_PENDING_LIMIT` |
| PUT | `/api/sender-ids/{id}` | Modifier (en attente) ou corriger et renvoyer (rejeté) → 409 `SENDER_ID_LOCKED` sinon |
| DELETE | `/api/sender-ids/{id}` | Annuler / retirer (suppression logique) |
| POST | `/api/sender-ids/{id}/default` | Définir par défaut (approuvé) → 409 `SENDER_ID_NOT_APPROVED` |
| POST | `/api/sender-ids/{id}/review` | `{decision: approve|reject|suspend|reactivate, reason?}` (motif de 5 caractères minimum pour reject et suspend) → 409 `SENDER_ID_BAD_TRANSITION` |

## Messages (`view_messages` en lecture, `send_messages` en écriture)

Les envois sont mis en file puis expédiés par le worker (`scripts/worker.php`). Statuts : `queued` (en file ou programmé), `sending`, `sent` (accepté par le fournisseur), `delivered`, `failed`, `cancelled`. Les dates sont en UTC.

| Méthode | Route | Description |
|---|---|---|
| POST | `/api/messages/preview` | Simule un envoi sans rien enregistrer. Corps identique à `send` (sans `idempotency_key`). Renvoie destinataires, SMS facturables, invalides, bloqués, doublons, variables manquantes, exemples |
| POST | `/api/messages/send` | `{sender_id_id, body, numbers?, group_ids?, segment_ids?, scheduled_at? (ISO 8601), idempotency_key?, organization_id? (global)}` → `{batch, replayed, summary}`. 422 `NO_RECIPIENTS`, 422 `RECIPIENTS_LIMIT`, 409 `SENDER_ID_NOT_APPROVED` |
| POST | `/api/messages/analyze` | `{body}` → encodage, longueur, segments |
| GET | `/api/messages` | Liste paginée. Filtres : `status`, `batch_id`, `q` (numéro ou texte), `date_from`, `date_to`, `organization_id` (global), `sort`, `dir`, `page`, `per_page` |
| GET | `/api/messages/{id}` | Détail |
| GET | `/api/messages/stats` | Compteurs 30 jours, aujourd'hui, SMS facturables, taux de livraison |
| POST | `/api/messages/{id}/cancel` | Annule un message encore en file → 409 `MESSAGE_NOT_CANCELLABLE` sinon |
| GET | `/api/message-batches` | Liste des envois avec compteurs par statut |
| GET | `/api/message-batches/{id}` | Détail d'un envoi |
| POST | `/api/message-batches/{id}/cancel` | Annule les messages encore en file de l'envoi → `{cancelled}` |

## Campagnes (`view_campaigns` en lecture, `manage_campaigns` en écriture)

Statuts : `draft`, `launching`, `scheduled`, `running`, `completed`, `cancelled`. Les dates sont en UTC.

| Méthode | Route | Description |
|---|---|---|
| GET | `/api/campaigns` | Liste paginée. Filtres : `status`, `q`, `organization_id` (global), `sort`, `dir`, `page`, `per_page` |
| GET | `/api/campaigns/{id}` | Détail avec compteurs (en file, envoyés, livrés, échecs, annulés), taux de livraison, avancement |
| GET | `/api/campaigns/{id}/report` | Détail + répartition par opérateur + 5 échecs les plus fréquents |
| GET | `/api/campaigns/{id}/export` | Export CSV des messages de la campagne |
| POST | `/api/campaigns` | Créer un brouillon `{name, body, sender_id_id?, group_ids?, segment_ids?, scheduled_at? (ISO 8601), organization_id? (global)}` |
| PUT | `/api/campaigns/{id}` | Modifier un brouillon → 409 `CAMPAIGN_LOCKED` sinon |
| DELETE | `/api/campaigns/{id}` | Supprimer (brouillon, terminée ou annulée) → 409 `CAMPAIGN_ACTIVE` sinon |
| POST | `/api/campaigns/preview` | Simule le lancement sans rien enregistrer `{sender_id_id, body, group_ids, segment_ids, scheduled_at?}` |
| POST | `/api/campaigns/{id}/launch` | Lance ou programme → 409 `CAMPAIGN_NOT_DRAFT`, 409 `SENDER_ID_NOT_APPROVED`, 422 `NO_RECIPIENTS`, 422 `RECIPIENTS_LIMIT` |
| POST | `/api/campaigns/{id}/cancel` | Annule ce qui n'est pas encore parti (campagne programmée ou en cours) |
| POST | `/api/campaigns/{id}/duplicate` | Copie en brouillon |
| GET / POST | `/api/campaign-templates` | Lister / créer un modèle `{name, body}` |
| PUT / DELETE | `/api/campaign-templates/{id}` | Modifier / supprimer un modèle |

## Wallet et facturation

Montants en micro-unités entières (1 000 000 = 1,00 de la devise). Lecture : `view_wallet` ; demande de recharge : `manage_billing` ; confirmation : `approve_payments` ; ajustement : `adjust_wallets` ; tarifs : `manage_pricing`.

| Méthode | Route | Description |
|---|---|---|
| GET | `/api/wallet` | Solde, réservé, disponible, dépensé et rechargé sur 30 jours (`organization_id` pour l'équipe TAL) |
| GET | `/api/wallets` | Soldes de tous les clients (équipe TAL), filtre `q` |
| GET | `/api/wallet/transactions` | Journal des mouvements (recharges, ajustements, débits par envoi) |
| GET | `/api/pricing` | Tarifs effectivement appliqués au client |
| GET / POST | `/api/payments` | Lister / déclarer une recharge `{amount, method, reference, note?}` |
| POST | `/api/payments/{id}/cancel` | Le client retire sa demande en attente |
| POST | `/api/payments/{id}/confirm` | Confirme : crédite le wallet et émet la facture → 409 `PAYMENT_NOT_PENDING`, 409 `PAYMENT_REFERENCE_USED` |
| POST | `/api/payments/{id}/reject` | Rejette `{reason}` (5 caractères minimum) |
| GET | `/api/invoices` | Factures ; page imprimable `/billing/invoices/{id}` |
| POST | `/api/wallet/adjust` | `{organization_id, amount (négatif = correction), note}` |
| GET / POST / PUT / DELETE | `/api/pricing-rules` | Gestion des tarifs `{organization_id?, country?, operator?, currency, price, active}` → 409 `PRICING_EXISTS` |

Erreur d'envoi : 402 `INSUFFICIENT_BALANCE`. L'aperçu (`/api/messages/preview`, `/api/campaigns/preview`) renvoie `cost_micro`, `available_micro`, `enough_balance` et `unpriced`.

## API publique v1 (clé API Bearer, sans session)
- `POST /api/v1/messages` `{to, from, body, scheduled_at?}` + en-tête `Idempotency-Key` → 202 `{id,status,recipients,segments,cost,rejected}`. 402 INSUFFICIENT_BALANCE.
- `GET /api/v1/messages/{id}`, `GET /api/v1/batches/{id}`, `GET /api/v1/balance`.
- Erreurs : 401 INVALID_API_KEY, 403 INSUFFICIENT_SCOPE / ORGANIZATION_INACTIVE, 422, 429 RATE_LIMITED. En-têtes `X-RateLimit-Limit/Remaining`.

## Gestion (session) — `manage_api_keys` : `GET/POST /api/api-keys`, `DELETE /api/api-keys/{id}`
## Fournisseurs — `manage_providers` : `GET /api/providers`, `PUT /api/providers/{code}`, `POST /api/providers/{code}/test`, `POST …/rotate-webhook` ; webhook public `POST /webhooks/providers/{code}/{secret}`.
## Tickets — `create_tickets` / `manage_tickets` : `/api/tickets` (GET, POST), `/api/tickets/{id}` (GET), `/replies` (POST), `/status` (PUT), `/assign` (PUT, équipe), `GET /api/support/search?q=` (équipe).
## Dashboards : `GET /api/dashboards/{ceo|cto|operations|sales|support|client}` (permission `access_dashboard_<slug>`). Clients : `GET /api/clients/{id}/overview`. Prospects : `GET/PUT /api/leads` (`manage_crm`). Public : `POST /api/public/contact`.
