# Webshop API

[![CI](https://github.com/jparengkuan/api-platform-webshop/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/jparengkuan/api-platform-webshop/actions/workflows/ci.yml)

A REST/JSON-LD API for a simple webshop, built with **Symfony 8.1** and **API Platform 5**.
Customers can register, log in through an OAuth2 client application, browse the catalog and place
an order. Administrators maintain the product catalog.

The code follows the [Symfony best practices](https://symfony.com/doc/current/best_practices.html),
the SOLID principles and a set of targeted design patterns (see [Architecture](#architecture)).

---

## Contents

- [Features](#features)
- [Tech stack](#tech-stack)
- [Installation](#installation)
- [Authentication (OAuth2)](#authentication-oauth2)
- [Using the API](#using-the-api)
- [Architecture](#architecture)
- [Business rules](#business-rules)
- [Error handling](#error-handling)
- [Security](#security)
- [Quality and tests](#quality-and-tests)
- [Deployment (Docker)](#deployment-docker)
- [Project structure](#project-structure)
- [Conventions](#conventions)
- [Known limitations and roadmap](#known-limitations-and-roadmap)

---

## Features

| Area      | What it does                                                                        |
|-----------|-------------------------------------------------------------------------------------|
| Customers | Register; log in with email and password through a registered OAuth2 client app; view own profile |
| Catalog   | Browse products (public), filter by category; manage products and categories (admin only) |
| Orders    | Place an order with one or more lines; view own orders; admins see all orders       |
| Stock     | Reserved when an order is placed, safe under concurrent orders                      |

## Tech stack

| Component        | Choice                                                           |
|------------------|------------------------------------------------------------------|
| Language         | PHP 8.4                                                          |
| Framework        | Symfony 8.1                                                      |
| API              | API Platform 5 (JSON-LD/Hydra, OpenAPI)                          |
| Database         | PostgreSQL 17 via Doctrine ORM 3 and Doctrine Migrations         |
| Authentication   | OAuth2 Authorization Code + PKCE (`league/oauth2-server-bundle`); JWT access tokens, refresh tokens |
| Local environment| [DDEV](https://ddev.com) (nginx-fpm, PHP 8.4, PostgreSQL 17)      |
| Production       | Docker: FrankenPHP (worker mode) behind Varnish 8, with tag-based cache invalidation |
| Tests            | PHPUnit 13, `api-platform/test`                                  |
| Test data        | Foundry (`zenstruck/foundry`) factories and stories with Faker (`nl_NL`) |
| Quality          | PHPStan (level 5) with Symfony/Doctrine/PHPUnit extensions, PHP-CS-Fixer (`@Symfony`) |

---

## Installation

### Requirements

- [Docker](https://www.docker.com/) (Docker Desktop on Windows/macOS)
- [DDEV](https://ddev.readthedocs.io/en/stable/users/install/) 1.24 or newer

You don't need PHP, Composer or PostgreSQL installed locally; they run inside the DDEV containers.
That's why every command below runs through `ddev exec` or `ddev composer`.

### Step by step

```bash
# 1. Start the containers
ddev start

# 2. Install dependencies
ddev composer install

# 3. Generate the key pair that signs OAuth2 access tokens (config/jwt/*.pem, git-ignored).
#    It's encrypted with OAUTH_PASSPHRASE; the dev default in .env is fine locally.
ddev exec bin/console app:oauth:generate-keys

# 4. Create the database schema
ddev exec bin/console doctrine:migrations:migrate --no-interaction

# 5. Create the test database (Doctrine appends the _test suffix automatically)
ddev exec bin/console doctrine:database:create --env=test --if-not-exists
ddev exec bin/console doctrine:migrations:migrate --env=test --no-interaction

# 6. Fill the dev database with realistic test data, including a demo OAuth2 client
ddev composer fixtures
```

The API now runs at **https://api-platform.ddev.site/api**.
The interactive documentation (Swagger UI) is at **https://api-platform.ddev.site/api/docs**.

### Configuration

`.env` is committed and holds defaults only. Real values belong in a `.env.*.local` file
(git-ignored) or in the [Symfony secrets vault](https://symfony.com/doc/current/configuration/secrets.html).

| Variable             | Meaning                                                          |
|----------------------|------------------------------------------------------------------|
| `DATABASE_URL`       | PostgreSQL connection; DDEV sets this automatically              |
| `OAUTH_PRIVATE_KEY` / `OAUTH_PUBLIC_KEY` | Key pair that signs and verifies access tokens (`config/jwt/*.pem`) |
| `OAUTH_PASSPHRASE`   | Encrypts the private key. The value in `.env` is a dev default; production sets its own |
| `OAUTH_ENCRYPTION_KEY` | Encrypts authorization codes and refresh tokens. Dev default in `.env`; production sets its own |
| `CORS_ALLOW_ORIGIN`  | Regex of allowed origins for browser clients                     |
| `APP_SECRET`         | Symfony secret; in production via the secrets vault              |

### Test data

`ddev composer fixtures` fills the dev database with a realistic Dutch webshop in about 4 seconds:

| What        | Amount | Details                                                                  |
|-------------|--------|--------------------------------------------------------------------------|
| OAuth2 client | 1    | `webshop-demo`, secret `demo-secret`, redirect URI `http://localhost:3000/callback` |
| Accounts    | 2      | `admin@example.com` (admin) and `klant@example.com` (customer)           |
| Customers   | 50     | Dutch names; the email matches the name (`floris.devries123@gmail.com`)  |
| Categories  | 6      | Accessoires, Audio, Kantoor, Kleding, Mokken & flessen, Tassen           |
| Products    | 200    | E.g. "Hoodie Utrecht, marineblauw" in "Kleding"; prices ending in ,95; ~10% inactive |
| Orders      | ~460   | 1–5 lines each, spread over the last year, never before registration     |

**Every account's password is `password`.** Log in as `klant@example.com` to see a customer with
order history, or as `admin@example.com` to manage the catalog.

> ⚠️ The command **empties the dev database first** and rebuilds the schema from the migrations.
> Use `bin/console foundry:load-fixtures --append` to add data without resetting.

How it works:

- **Factories** in `src/Factory/` describe one entity each with realistic defaults (Faker, `nl_NL`).
  Anything can be overridden per call: `ProductFactory::createOne(['stock' => 0])`.
- **`OrderFactory` goes through `Order::place()`**, so generated orders obey the same business rules
  as real ones: stock is reserved, prices are snapshotted and totals add up. The story only orders
  products that are active and in stock, so stock levels reflect a year of sales.
- **`src/Story/AppStory.php`** (`#[AsFixture(name: 'main')]`) combines the factories into the full
  data set.
- **Reproducible:** the script sets `FOUNDRY_FAKER_SEED=1234`, so every developer gets exactly the
  same data. Run `bin/console foundry:load-fixtures` without the seed for a different data set.

Foundry is a dev dependency: the bundle is only enabled in the `dev` and `test` environments.

### Creating an administrator

With the fixtures loaded, `admin@example.com` is an administrator already. On a database without
fixtures there is no endpoint for assigning roles (yet): register a regular customer and then give
it the `ROLE_ADMIN` role:

```bash
ddev exec bin/console dbal:run-sql \
  "UPDATE customer SET roles = '[\"ROLE_ADMIN\"]' WHERE email = 'admin@example.com'"
```

The new role applies to the next request: the API loads the user (and their roles) from the
database for every access token.

---

## Authentication (OAuth2)

The API is an **OAuth2 authorization server** and **resource server**. Users only log in through a
**registered client application** (e.g. your server-side frontend), with the **Authorization Code
grant and PKCE**: they type their email and password on the API's own login page, never in the
client application.

```
Client app (server-side)           API                                     User's browser
────────────────────────           ───                                     ──────────────
1. redirect to /authorize ─────────────────────────────────────────────────▶ GET /authorize?…
                                   not logged in → 302 /login ◀────────────
                                   login page (email + password) ◀────────── POST /login
                                   logged in → 302 back to /authorize ─────▶
                                   PKCE OK → 302 redirect_uri?code=…&state=… ▶ callback
2. POST /token (code + client secret + PKCE verifier)
   ◀── { access_token (1 h), refresh_token (1 month) }
3. GET /api/…  Authorization: Bearer <access_token>
```

| Rule                                              | Why                                                      |
|---------------------------------------------------|----------------------------------------------------------|
| Only the Authorization Code and Refresh Token grants are enabled | No password, implicit or client-credentials grant: the password is only ever entered on the API |
| PKCE with `S256` is required for **every** client | OAuth 2.1; the server itself only enforces it for public clients, `AuthorizationRequestListener` does it for all |
| Redirect URIs must be registered exactly          | Codes can't be sent to another site                       |
| Clients are registered by an administrator only   | So they're trusted: no consent screen                     |
| Login form: CSRF token, 5 attempts per 15 minutes | Against forged logins and password guessing (`login_throttling`) |
| Access token: 1 hour, JWT signed with RS256       | The API verifies it with the public key and loads the user and roles from the database on every request |
| Refresh token: 1 month, rotated on every use      | A new access token without logging in again               |
| No `/api/login` anymore                           | The only way to a token is `/token`                       |

### Endpoints

| Method   | Path         | Purpose                                                                       |
|----------|--------------|-------------------------------------------------------------------------------|
| GET      | `/authorize` | Start of the flow: `response_type=code`, `client_id`, `redirect_uri`, `scope=api`, `state`, `code_challenge`, `code_challenge_method=S256` |
| GET/POST | `/login`     | Login page (email + password); `/authorize` sends users here                   |
| GET      | `/logout`    | Ends the login session on the API                                             |
| POST     | `/token`     | `grant_type=authorization_code` (with `code`, `redirect_uri`, `code_verifier`) or `grant_type=refresh_token` (with `refresh_token`); always with `client_id` and `client_secret` |

### Registering a client

```bash
ddev exec bin/console league:oauth2-server:create-client "My frontend" my-frontend \
  --redirect-uri=https://www.example.com/callback \
  --grant-type=authorization_code --grant-type=refresh_token --scope=api
```

The command prints the generated secret **once** (it's stored hashed). The dev fixtures already
contain a demo client: `webshop-demo` / `demo-secret`, redirect URI `http://localhost:3000/callback`.

### Trying the flow by hand

```bash
API=https://api-platform.ddev.site
VERIFIER=$(openssl rand -hex 32)
CHALLENGE=$(printf '%s' "$VERIFIER" | openssl dgst -binary -sha256 | openssl base64 -A | tr '+/' '-_' | tr -d '=')

# 1. Open this URL in a browser, log in as klant@example.com / password
echo "$API/authorize?response_type=code&client_id=webshop-demo&redirect_uri=http%3A%2F%2Flocalhost%3A3000%2Fcallback&scope=api&state=xyz&code_challenge=$CHALLENGE&code_challenge_method=S256"

# 2. The browser ends on http://localhost:3000/callback?code=…&state=xyz (nothing runs there;
#    copy the code from the address bar) and exchange it for tokens:
curl -X POST $API/token \
  -d grant_type=authorization_code -d client_id=webshop-demo -d client_secret=demo-secret \
  --data-urlencode redirect_uri=http://localhost:3000/callback \
  --data-urlencode code=<code> -d code_verifier=$VERIFIER
```

In Swagger UI (`/api/docs`), click **Authorize** and paste `Bearer <access_token>`.

---

## Using the API

All endpoints live under `/api`. The API speaks **JSON-LD** (`application/ld+json`) by default;
plain JSON (`application/json`) works too. Relations are referenced by **IRI**, for example
`/api/products/0199…`.

### Endpoints

| Method  | Path                    | Access                  | Description                                    |
|---------|-------------------------|-------------------------|------------------------------------------------|
| POST    | `/api/register`         | public                  | Create a customer account (logging in: see [Authentication](#authentication-oauth2)) |
| GET     | `/api/me`               | authenticated           | Profile of the logged-in customer              |
| GET     | `/api/customers/{id}`   | owner or admin          | Get a customer                                 |
| GET     | `/api/categories`       | public                  | All categories, sorted by name (not paginated) |
| GET     | `/api/categories/{id}`  | public                  | A single category                              |
| POST    | `/api/categories`       | admin                   | Create a category (slug derived from the name unless given) |
| PATCH   | `/api/categories/{id}`  | admin                   | Rename a category or change its slug           |
| DELETE  | `/api/categories/{id}`  | admin                   | Delete an empty category (`409` while products still use it) |
| GET     | `/api/products`         | public                  | Catalog (paginated, sorted by name); filter with `?category=<IRI>` or `?category.slug=kleding` |
| GET     | `/api/products/{id}`    | public                  | A single product                               |
| POST    | `/api/products`         | admin                   | Create a product                               |
| PATCH   | `/api/products/{id}`    | admin                   | Update a product (`application/merge-patch+json`) |
| POST    | `/api/orders`           | authenticated           | Place an order                                 |
| GET     | `/api/orders`           | authenticated           | Own orders (admin: all), newest first          |
| GET     | `/api/orders/{id}`      | owner or admin          | A single order                                 |

### Example: from registration to order

```bash
API=https://api-platform.ddev.site/api

# Register
curl -X POST $API/register -H 'Content-Type: application/json' -d '{
  "email": "jan@example.com",
  "firstName": "Jan",
  "lastName": "Jansen",
  "plainPassword": "S3cure-webshop-pass!"
}'

# Log in through the OAuth2 flow (see "Authentication") and keep the access token
TOKEN=<access_token>

# Browse the catalog, or only one category
curl $API/products
curl "$API/products?category.slug=kleding"

# Place an order
curl -X POST $API/orders \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "lines": [
      { "product": "/api/products/0199aaaa-0000-7000-8000-000000000001", "quantity": 2 },
      { "product": "/api/products/0199aaaa-0000-7000-8000-000000000002", "quantity": 1 }
    ]
  }'
```

Response (`201 Created`, abbreviated):

```json
{
  "@id": "/api/orders/0199bbbb-…",
  "@type": "Order",
  "customer": "/api/customers/0199cccc-…",
  "status": "placed",
  "lines": [
    {
      "product": "/api/products/0199aaaa-0000-7000-8000-000000000001",
      "sku": "MUG-01",
      "productName": "Mug",
      "unitPrice": { "amount": 1250, "currency": "EUR" },
      "quantity": 2,
      "lineTotal": { "amount": 2500, "currency": "EUR" }
    }
  ],
  "total": { "amount": 4999, "currency": "EUR" },
  "placedAt": "2026-09-27T12:00:00+00:00"
}
```

### Creating a category and a product (admin)

```bash
# The slug is derived from the name: "Mokken & flessen" becomes "mokken-flessen"
curl -X POST $API/categories \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{ "name": "Mokken & flessen" }'

curl -X POST $API/products \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "sku": "MUG-01",
    "name": "Mug",
    "description": "Sturdy stoneware mug, 350 ml",
    "category": "/api/categories/0199dddd-…",
    "price": { "amount": 1250, "currency": "EUR" },
    "stock": 100
  }'
```

A product is sent with its category as an IRI, and returned with the category embedded:
`"category": { "@id": "/api/categories/0199dddd-…", "name": "Mokken & flessen", "slug": "mokken-flessen" }`.

> **Amounts are always in cents** (the smallest currency unit): `1250` is € 12.50.
> This way floating-point rounding errors can never occur.

---

## Architecture

### Layers

```
HTTP (API Platform)          Application                  Domain                     Infrastructure
───────────────────          ───────────                  ──────                     ──────────────
PlaceOrderInput (DTO)  ─┐
                        ├─▶  PlaceOrderProcessor  ─▶  PlaceOrderHandler  ─▶  Order::place()  ◀─▶  ProductRepository
Validator (#[Assert])  ─┘       (adapter)               (use case,            Product::reserveStock()   OrderRepository
                                                       transaction)          Money                     (Doctrine ORM)
```

1. **API Platform** deserializes the request into `PlaceOrderInput` and validates it with the
   Validator constraints on the DTO.
2. **`PlaceOrderProcessor`** (adapter) turns the DTO into a `PlaceOrder` command, together with
   the logged-in customer.
3. **`PlaceOrderHandler`** (use case) opens a transaction, loads the products with a write lock
   and lets the domain do the work.
4. **`Order::place()`** checks the business rules, reserves stock and calculates the total.
5. On success the transaction commits and API Platform returns the order as `201`. On failure it
   rolls back and nothing is stored.

### SOLID in practice

| Principle                 | Where                                                                                      |
|---------------------------|--------------------------------------------------------------------------------------------|
| **S**ingle Responsibility | Processor = HTTP translation, handler = transaction, entities = business rules, repository = storage |
| **O**pen/Closed           | A new business rule = a new subclass of `OrderingException`; the 422 mapping doesn't change |
| **L**iskov Substitution   | Every implementation of the repository interfaces must honor the same contract (e.g. an in-memory variant for tests) |
| **I**nterface Segregation | Small repository interfaces with only what the use case needs (`findForUpdate`, `add`)     |
| **D**ependency Inversion  | `PlaceOrderHandler` depends on interfaces and `Psr\Clock\ClockInterface`, not on Doctrine classes |

### Design patterns

| Pattern                 | Class(es)                                         | Why                                                        |
|-------------------------|---------------------------------------------------|------------------------------------------------------------|
| Repository              | `ProductRepository`, `OrderRepository`            | Storage logic separated from domain and use case           |
| Command + Handler       | `PlaceOrder`, `PlaceOrderHandler`                 | Use case independent of HTTP; reusable from CLI or Messenger |
| Adapter                 | `PlaceOrderProcessor`                             | Connects API Platform to the use case                      |
| Data Transfer Object    | `PlaceOrderInput`, `OrderLineInput`               | API contract decoupled from the domain model               |
| Aggregate Root          | `Order` (with `OrderLine`)                        | Lines can only be created through the order                |
| Factory Method          | `Order::place()`, `Money::zero()`, named constructors on exceptions | Always a valid object; intent in the name |
| Value Object            | `Money` (Doctrine embeddable, `readonly`)         | Immutable, self-validating, no float rounding              |
| Snapshot                | `OrderLine` copies SKU, name and price            | Later catalog changes never alter past orders              |
| Query Extension         | `CurrentCustomerOrdersExtension`                  | Automatically filters `GET /api/orders` to the logged-in customer |

### Concurrency

Two customers ordering the last item at the same time must not both succeed. That's why:

- `ProductRepository::findForUpdate()` loads products with `SELECT … FOR UPDATE` (pessimistic
  write lock), inside the handler's transaction.
- Products are always locked **in id order**, so two orders containing the same products can't
  deadlock each other.
- `Query::HINT_REFRESH` makes sure stock is read from the database *after* the lock is acquired,
  even when the product was already in the identity map.
- On an exception, `EntityManager::wrapInTransaction()` rolls back and closes the EntityManager.
  Stock already deducted in memory can therefore never be flushed afterwards.

---

## Business rules

| Rule                                                        | Enforced in                          | Result |
|-------------------------------------------------------------|--------------------------------------|--------|
| An order has 1 to 50 lines                                  | `PlaceOrderInput` (Validator) and `Order::place()` | 422 |
| Quantity per line is 1 to 100                               | `OrderLineInput` (Validator)         | 422    |
| Duplicate lines for the same product are merged             | `PlaceOrder`                         | —      |
| The product must exist                                      | API Platform (IRI) and `PlaceOrderHandler` | 400 / 422 |
| The product must be active                                  | `Product::reserveStock()`            | 422    |
| Enough stock, otherwise the whole order is rejected         | `Product::reserveStock()` + transaction | 422 |
| All products in one currency                                | `Order::place()`                     | 422    |
| A price is never negative                                   | `Money`                              | 422    |
| SKU is unique and contains only uppercase letters, digits and `-` | `Product` (Validator, `UniqueEntity`) | 422 |
| Every product belongs to exactly one category                | `Product` (Validator, `NOT NULL` column) | 422 |
| Category name and slug are unique; the slug is lowercase with dashes | `Category` (Validator, `UniqueEntity`) | 422 |
| A category with products can't be deleted                    | `CategoryRemoveProcessor`            | 409       |

---

## Error handling

Errors are returned as [RFC 7807 Problem Details](https://www.rfc-editor.org/rfc/rfc7807)
(in JSON-LD as a Hydra `Error`).

| Status | When                                                                     |
|--------|--------------------------------------------------------------------------|
| 400    | Invalid JSON or an unknown IRI                                           |
| 401    | Missing or invalid token                                                 |
| 403    | Authenticated but not allowed (e.g. someone else's order, not an admin)  |
| 404    | Resource does not exist                                                  |
| 422    | Validation error (`violations`) or broken business rule (`detail`)       |

Example of a broken business rule:

```json
{
  "@type": "Error",
  "title": "An error occurred",
  "detail": "Insufficient stock for \"SHIRT-01\": 2 requested, 1 available.",
  "status": 422
}
```

**How this works technically:**

- All business errors extend `App\Exception\OrderingException`.
- The order operation maps that base class to 422 via `exceptionToStatus`.
- `#[WithLogLevel(LogLevel::INFO)]` logs them as an expected outcome instead of `critical`, so
  they don't trigger alerts in production.

---

## Security

- **OAuth2 only:** tokens come exclusively from `/token`, for registered clients, through the
  Authorization Code grant with PKCE (see [Authentication](#authentication-oauth2)). Every `/api`
  route reads the access token from the `Authorization: Bearer …` header (stateless firewall `api`,
  `oauth2: true`). The login page uses a separate, session-based firewall (`main`) with CSRF
  protection and login throttling.
- **Behind proxies:** in production Symfony trusts `X-Forwarded-*` from the private network (the TLS
  proxy and Varnish), so it sees HTTPS: redirects stay on `https://` and the session cookie is
  `Secure`, `HttpOnly`, `SameSite=Lax`.
- **Authorization per operation:** through `security:` expressions in the `#[ApiResource]`
  attributes, for example `is_granted('ROLE_ADMIN') or (is_granted('ROLE_USER') and object.isPlacedBy(user))`.
- **Collections:** `CurrentCustomerOrdersExtension` limits the order list to the customer's own
  orders. This happens in the query itself, so no one else's data can leak through pagination or
  filters.
- **Passwords:** hashed with the `auto` hasher and upgraded automatically on login
  (`PasswordUpgraderInterface`). On registration the password is checked against known data
  breaches (`NotCompromisedPassword`). The plain-text password is never stored or returned.
- **Identifiers:** UUID v7, so they can't be guessed or enumerated.
- **Secrets:** the OAuth2 signing keys (`config/jwt/*.pem`) and `.env.*.local` are git-ignored;
  client secrets are stored hashed.

---

## Quality and tests

Everything runs through Composer scripts:

```bash
ddev composer qa        # all of the below in sequence
ddev composer cs        # check code style (PHP-CS-Fixer, dry run with diff)
ddev composer cs:fix    # fix code style automatically
ddev composer phpstan   # static analysis (level 5)
ddev composer test      # PHPUnit
ddev composer fixtures  # reset the dev database and load the test data
```

### Continuous integration

GitHub Actions (`.github/workflows/ci.yml`) runs on **every push to every branch** and on every
pull request. Three jobs run in parallel and must all pass:

| Job                                  | Runs                                           | Where errors show up                         |
|--------------------------------------|------------------------------------------------|----------------------------------------------|
| **Code style (PHP-CS-Fixer)**        | `php-cs-fixer fix --dry-run`                   | Annotations on the lines + a diff in the log |
| **Static analysis (PHPStan level 5)**| `phpstan analyse`                              | Annotations on the lines + a table in the log|
| **Tests (PHPUnit)**                  | Full suite against PostgreSQL 17               | Annotations on the failing assertion + a list in the job summary |

Annotations appear in the **Files changed** tab of a pull request and in the **Summary** of the
workflow run, so you rarely need to dig through the logs. Every failure also writes the command
to reproduce it locally into the job summary.

The CI mirrors the local commands: if `ddev composer qa` passes, CI passes.

### Tests

| Suite                          | Type                    | What is tested                                             |
|--------------------------------|-------------------------|------------------------------------------------------------|
| `tests/Unit/Entity/MoneyTest`  | Unit                    | Arithmetic, equality and validation of `Money`             |
| `tests/Unit/Entity/OrderTest`  | Unit                    | Business rules of `Order::place()`, price snapshots        |
| `tests/Api/CustomerAuthTest`   | Functional (HTTP)       | Registration, logging in, access to profiles               |
| `tests/Api/OAuthTest`          | Functional (HTTP)       | Authorization Code flow, PKCE, redirect URIs, client secret, refresh tokens, disabled grants |
| `tests/Api/ProductTest`        | Functional (HTTP)       | Public catalog, category filters, admin management, validation, JSON schema |
| `tests/Api/CategoryTest`       | Functional (HTTP)       | Public list, slugs, uniqueness, admin-only, delete protection |
| `tests/Api/HttpCacheTest`      | Functional (HTTP)       | Cache headers, cache tags, invalidation on changes and orders |
| `tests/Api/PlaceOrderTest`     | Functional (HTTP)       | Ordering, stock, rollback, validation, authorization       |

The functional tests run against a real PostgreSQL test database (`db_test`) and truncate the
tables before every test. They create their data with the same Foundry factories as the dev
fixtures. `tests/Api/WebshopTestTrait.php` wraps them so each test pins down exactly what it relies on
(SKU, price, stock, active) and leaves the rest random. Its `login()` helper runs the real OAuth2
flow, exactly like a client application: `/authorize` → login page → callback with a code → `/token`.
Foundry's PHPUnit extension is registered in `phpunit.dist.xml`.

### Static analysis

- `phpstan.dist.neon` loads the Symfony container (`var/cache/dev/…Container.xml`) and the real
  Doctrine mapping (`tests/object-manager.php`). That way PHPStan also checks service IDs,
  repository types and DQL.
- Want different settings locally? Create a `phpstan.neon` (it's git-ignored).

---

## Deployment (Docker)

DDEV is for development only. For servers there is a production stack in `compose.prod.yaml`:

```
reverse proxy (TLS)  →  Varnish :80  →  FrankenPHP :80  →  PostgreSQL 17
  e.g. Coolify           HTTP cache       Symfony app
```

- **FrankenPHP** runs PHP and the Caddy web server in one container, in **worker mode**: the
  Symfony kernel stays in memory between requests, so responses take a few milliseconds.
- **Varnish** caches public responses (see [HTTP caching](#http-caching-varnish)).
- **TLS** is terminated by the hosting platform's reverse proxy in front of Varnish (Coolify
  does this automatically, with Let's Encrypt). The stack itself only speaks HTTP and publishes no
  public ports.

### Run it

```bash
cp .env.prod.local.dist .env.prod.local     # fill in real values; the copy is git-ignored
docker compose -f compose.prod.yaml --env-file .env.prod.local up -d --build --wait
curl http://127.0.0.1:8080/api/products     # through Varnish, from the server itself
```

| Variable                   | Meaning                                                               |
|----------------------------|-----------------------------------------------------------------------|
| `APP_SECRET`               | Symfony secret, e.g. `openssl rand -hex 32`                           |
| `OAUTH_PASSPHRASE`         | Encrypts the OAuth2 signing key, e.g. `openssl rand -hex 32`. Never change it afterwards |
| `OAUTH_ENCRYPTION_KEY`     | Encrypts authorization codes and refresh tokens, e.g. `openssl rand -hex 32` |
| `POSTGRES_PASSWORD`        | Database password (`POSTGRES_DB`/`POSTGRES_USER` default to `app`)    |
| `CORS_ALLOW_ORIGIN`        | Regex of your frontend's origin(s)                                    |
| `VARNISH_SIZE`             | Memory for Varnish's cache (default `256M`)                            |
| `HTTP_PORT`                | Port on `127.0.0.1` of the server for local testing (default `8080`); give every stack on the same server its own port |
| `INSTALL_DEV_DEPENDENCIES` | Build argument; `1` for staging (adds Foundry for the demo data), `0` (default) for production |
| `RESET_DEMO_DATA`          | Staging only: `1` replaces all data with the demo fixtures on every start. Refuses to run on a production image. |

### HTTP caching (Varnish)

Public catalog responses (`GET` on products and categories) are cached by Varnish for an hour and
by browsers for a minute (`CacheHeaders::PUBLIC_CATALOG`). They never go stale in Varnish:

1. Every response carries a `Cache-Tags` header with the IRIs of all resources in it
   (e.g. `/api/products/0199…,/api/categories/0199…,/api/products`).
2. When a resource changes through Doctrine (an admin edits a price, an order lowers the stock, a
   category is added), API Platform sends Varnish a `BAN` request for the affected IRIs.
3. Varnish drops exactly the responses tagged with them; the next request fetches fresh data.

| Request                                  | Cached by Varnish? |
|------------------------------------------|--------------------|
| `GET /api/products`, `/api/categories`, … without `Authorization` | ✅ Yes, until a ban |
| Anything with an `Authorization` header or cookies (`/api/me`, `/api/orders`, logged-in catalog requests) | ❌ Always passed to the app |
| `POST`, `PATCH`, `DELETE`                | ❌ Never            |

Details in `docker/varnish/default.vcl`:

- `X-Cache: HIT|MISS` on every response shows what happened.
- Conditional requests with `If-None-Match` get a `304 Not Modified` straight from Varnish.
- If the app is down, Varnish keeps serving cached responses for up to an hour (grace mode).
- `BAN` is only accepted from the private network **and** for the internal host name `varnish`, so
  it can't be triggered through the public domain. The `Cache-Tags` header is removed before
  responses leave Varnish.

> Tip for a frontend: load the public catalog **without** the `Authorization` header, so it comes
> from the cache; send the token only for personal endpoints.

In tests, `App\Tests\Support\RecordingPurger` replaces the Varnish purger, and
`tests/Api/HttpCacheTest.php` checks the cache headers, the tags and which resources each change
invalidates.

### Staging

Every push to `develop` is deployed to a staging environment, **after** code style, PHPStan and the
tests pass: the `deploy-staging` job in `.github/workflows/ci.yml` calls the deploy webhook of the
hosting platform (Coolify). It needs two repository secrets, `COOLIFY_STAGING_WEBHOOK` and
`COOLIFY_TOKEN`; without them the job skips itself with a notice.

Staging runs the same stack, built with `INSTALL_DEV_DEPENDENCIES=1` and started with
`RESET_DEMO_DATA=1`, so every deployment starts with the same fresh demo data (`admin@example.com`
and `klant@example.com`, password `password`). Never reuse staging secrets or its database for
production.

### What happens on start

The entrypoint of the app container (`docker/frankenphp/docker-entrypoint.sh`) runs before the web
server starts:

1. Generates the OAuth2 signing key pair on the first start (`app:oauth:generate-keys`, stored in the
   `jwt_keys` volume, reused afterwards).
2. Waits until PostgreSQL accepts connections.
3. With `RESET_DEMO_DATA=1` only: drops all tables.
4. Runs the migrations (`--all-or-nothing`: a failing migration changes nothing).
5. With `RESET_DEMO_DATA=1` only: loads the demo fixtures.

Compose starts the services in order and waits for each health check: PostgreSQL, then the app,
then Varnish.

### The images

| Choice                                  | Why                                                                   |
|-----------------------------------------|-----------------------------------------------------------------------|
| `composer install --no-dev`             | No Foundry, PHPStan, PHPUnit or tests in production                    |
| `composer dump-env prod`                | `.env` compiled to PHP; real values come from environment variables    |
| Runs as the unprivileged user `app`     | A compromised process has no root in the container                     |
| OPcache without timestamp checks        | Code never changes in a running container                              |
| `.dockerignore` excludes `.env.*.local`, `config/jwt/*.pem`, `.git`, tests | Secrets and dev files never end up in the image |
| Health checks on the app (Caddy admin endpoint) and Varnish (`varnishadm ping`) | Docker and the platform see when a container is unhealthy |
| VCL baked into the Varnish image (`docker/varnish/Dockerfile`) | Deploying needs no bind mounts            |

Data lives in two named volumes: `database_data` (PostgreSQL) and `jwt_keys`. **Back up
`database_data`** (e.g. a daily `pg_dump`); it also holds the OAuth2 clients and refresh tokens.
The signing keys are regenerated if lost (users then log in again).

Run a console command in production:

```bash
docker compose -f compose.prod.yaml --env-file .env.prod.local exec php bin/console about
```

---

## Project structure

```
├── .github/                 CI workflow and the shared PHP setup action
├── config/                  Symfony configuration (packages, routes, services)
├── docker/
│   ├── frankenphp/          Caddyfile and entrypoint of the app image
│   ├── php/                 Production php.ini
│   └── varnish/             VCL and image of the HTTP cache
├── migrations/              Doctrine migrations (never change the schema by hand)
├── src/
│   ├── ApiResource/         Shared API settings (HTTP cache headers)
│   ├── Command/             Console commands (app:oauth:generate-keys)
│   ├── Controller/          SecurityController: the login page of the OAuth2 flow
│   ├── EventListener/       AuthorizationRequestListener: approves /authorize requests with PKCE
│   ├── Doctrine/            API Platform query extensions
│   ├── Dto/                 API input DTOs
│   ├── Entity/              Doctrine entities and API resources
│   │   └── Embeddable/      Value objects (Money)
│   ├── Enum/                Backed enums (OrderStatus)
│   ├── Exception/           Domain exceptions
│   ├── Factory/             Foundry factories for test data (dev/test only)
│   ├── Ordering/            Use case: PlaceOrder command and handler
│   ├── Repository/          Repository interfaces and Doctrine implementations
│   ├── State/               API Platform state providers and processors
│   └── Story/               Foundry story with the full dev data set
├── templates/security/      The login page
├── tests/
│   ├── Api/                 Functional HTTP tests
│   ├── Support/             Test doubles (RecordingPurger)
│   └── Unit/                Domain unit tests
├── Dockerfile               Production image (FrankenPHP, worker mode)
├── compose.prod.yaml        Production stack: Varnish + app + PostgreSQL
├── .php-cs-fixer.dist.php   Code style (@Symfony)
├── phpstan.dist.neon        Static analysis (level 5)
└── phpunit.dist.xml         PHPUnit configuration
```

---

## Conventions

- **New packages** are installed with `composer require`, so the Flex recipe generates the
  configuration. Don't edit `config/bundles.php` by hand.
- **Metadata via PHP attributes**: `#[ApiResource]`, `#[Assert\…]`, `#[ORM\…]`, `#[Autowire]`.
  No YAML or XML routing.
- **Autowiring**: dependencies through the constructor with type hints. A YAML service
  definition is the last resort.
- **Schema changes** always go through a migration:
  ```bash
  ddev exec bin/console make:migration
  ddev exec bin/console doctrine:migrations:migrate
  ```
- **Docblocks**: every function has a summary sentence. Use `@param`, `@return` and `@throws`
  only when they add something to the type hints (the `@Symfony` ruleset removes redundant tags).
- **Definition of done**: `ddev composer qa` passes and the feature has a test that calls it the
  way a client would.
- **When something breaks**, check `var/log/dev.log` and the profiler (`/_profiler`) first.

---

## Known limitations and roadmap

- **Idempotency:** retrying `POST /api/orders` after a timeout can create a duplicate order.
  An idempotency-key header would solve this.
- **Order lifecycle:** `OrderStatus` currently only knows `placed`. Paid, shipped and cancelled
  (with stock returned) are still to come, for example with the Symfony Workflow component.
- **Administration:** there is no endpoint or console command to create administrators (the
  fixtures provide `admin@example.com` for development).
- **OAuth2 consent screen:** every registered client is trusted, so users aren't asked to approve
  access. Needed before third-party applications may register as clients.
- **Password reset:** the login page has no "forgot password" flow yet.
- **Tests use TRUNCATE:** Foundry's `ResetDatabase` trait would rebuild the whole database before
  every test without `dama/doctrine-test-bundle`. That bundle's recipe is in `recipes-contrib`,
  which this project doesn't allow (`allow-contrib: false`). Enabling it would make the test
  isolation transactional and faster.
- **Catalog:** inactive products still show up in the public list, although they can't be ordered.
- **Notifications:** an order confirmation email, sent asynchronously via Symfony Messenger and Mailer.
- **Concurrency test:** the locking is designed for concurrent orders, but there is no test yet
  that actually fires parallel requests.

---

## License

MIT. See [`LICENSE`](LICENSE).
