# Webshop API

A REST/JSON-LD API for a simple webshop, built with **Symfony 8.1** and **API Platform 5**.
Customers can register, log in with a JWT, browse the catalog and place an order.
Administrators maintain the product catalog.

The code follows the [Symfony best practices](https://symfony.com/doc/current/best_practices.html),
the SOLID principles and a set of targeted design patterns (see [Architecture](#architecture)).

---

## Contents

- [Features](#features)
- [Tech stack](#tech-stack)
- [Installation](#installation)
- [Using the API](#using-the-api)
- [Architecture](#architecture)
- [Business rules](#business-rules)
- [Error handling](#error-handling)
- [Security](#security)
- [Quality and tests](#quality-and-tests)
- [Project structure](#project-structure)
- [Conventions](#conventions)
- [Known limitations and roadmap](#known-limitations-and-roadmap)

---

## Features

| Area      | What it does                                                                        |
|-----------|-------------------------------------------------------------------------------------|
| Customers | Register, log in (JWT), view own profile                                            |
| Catalog   | Browse products (public); create and update them (admin only)                       |
| Orders    | Place an order with one or more lines; view own orders; admins see all orders       |
| Stock     | Reserved when an order is placed, safe under concurrent orders                      |

## Tech stack

| Component        | Choice                                                           |
|------------------|------------------------------------------------------------------|
| Language         | PHP 8.4                                                          |
| Framework        | Symfony 8.1                                                      |
| API              | API Platform 5 (JSON-LD/Hydra, OpenAPI)                          |
| Database         | PostgreSQL 17 via Doctrine ORM 3 and Doctrine Migrations         |
| Authentication   | LexikJWTAuthenticationBundle (stateless, `Bearer` token)         |
| Local environment| [DDEV](https://ddev.com) (nginx-fpm, PHP 8.4, PostgreSQL 17)      |
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

# 3. Set the JWT passphrase (pick your own long random value)
#    .env.local is used in dev, .env.test.local in the test environment.
#    Both need the same passphrase, because they share the same key pair.
echo 'JWT_PASSPHRASE=pick-a-long-random-value' >> .env.local
echo 'JWT_PASSPHRASE=pick-a-long-random-value' >> .env.test.local

# 4. Generate the key pair used to sign tokens (config/jwt/*.pem)
ddev exec bin/console lexik:jwt:generate-keypair

# 5. Create the database schema
ddev exec bin/console doctrine:migrations:migrate --no-interaction

# 6. Create the test database (Doctrine appends the _test suffix automatically)
ddev exec bin/console doctrine:database:create --env=test --if-not-exists
ddev exec bin/console doctrine:migrations:migrate --env=test --no-interaction

# 7. Optional: fill the dev database with realistic test data
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
| `JWT_SECRET_KEY`     | Path to the private key (`config/jwt/private.pem`)               |
| `JWT_PUBLIC_KEY`     | Path to the public key (`config/jwt/public.pem`)                 |
| `JWT_PASSPHRASE`     | Passphrase of the private key: **never commit it to `.env`**     |
| `CORS_ALLOW_ORIGIN`  | Regex of allowed origins for browser clients                     |
| `APP_SECRET`         | Symfony secret; in production via the secrets vault              |

### Test data

`ddev composer fixtures` fills the dev database with a realistic Dutch webshop in about 4 seconds:

| What        | Amount | Details                                                                  |
|-------------|--------|--------------------------------------------------------------------------|
| Accounts    | 2      | `admin@example.com` (admin) and `klant@example.com` (customer)           |
| Customers   | 50     | Dutch names; the email matches the name (`floris.devries123@gmail.com`)  |
| Products    | 200    | E.g. "Hoodie Utrecht, marineblauw"; prices ending in ,95; ~10% inactive  |
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

Then log in again; the roles are stored in the token.

---

## Using the API

All endpoints live under `/api`. The API speaks **JSON-LD** (`application/ld+json`) by default;
plain JSON (`application/json`) works too. Relations are referenced by **IRI**, for example
`/api/products/0199…`.

### Endpoints

| Method  | Path                    | Access                  | Description                                    |
|---------|-------------------------|-------------------------|------------------------------------------------|
| POST    | `/api/register`         | public                  | Create a customer account                      |
| POST    | `/api/login`            | public                  | Log in; returns `{"token": "<JWT>"}`           |
| GET     | `/api/me`               | authenticated           | Profile of the logged-in customer              |
| GET     | `/api/customers/{id}`   | owner or admin          | Get a customer                                 |
| GET     | `/api/products`         | public                  | Catalog (paginated, sorted by name)            |
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

# Log in and keep the token
TOKEN=$(curl -s -X POST $API/login -H 'Content-Type: application/json' \
  -d '{"email": "jan@example.com", "password": "S3cure-webshop-pass!"}' | jq -r .token)

# Browse the catalog
curl $API/products

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

### Creating a product (admin)

```bash
curl -X POST $API/products \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "sku": "MUG-01",
    "name": "Mug",
    "description": "Sturdy stoneware mug, 350 ml",
    "price": { "amount": 1250, "currency": "EUR" },
    "stock": 100
  }'
```

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

- **Stateless JWT:** `POST /api/login` (firewall `login`, `json_login`) returns a token. All other
  `/api` routes read the token from the `Authorization: Bearer …` header (firewall `api`).
- **Authorization per operation:** through `security:` expressions in the `#[ApiResource]`
  attributes, for example `is_granted('ROLE_ADMIN') or (is_granted('ROLE_USER') and object.isPlacedBy(user))`.
- **Collections:** `CurrentCustomerOrdersExtension` limits the order list to the customer's own
  orders. This happens in the query itself, so no one else's data can leak through pagination or
  filters.
- **Passwords:** hashed with the `auto` hasher and upgraded automatically on login
  (`PasswordUpgraderInterface`). On registration the password is checked against known data
  breaches (`NotCompromisedPassword`). The plain-text password is never stored or returned.
- **Identifiers:** UUID v7, so they can't be guessed or enumerated.
- **Secrets:** private keys (`config/jwt/*.pem`) and `.env.*.local` are git-ignored.

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

### Tests

| Suite                          | Type                    | What is tested                                             |
|--------------------------------|-------------------------|------------------------------------------------------------|
| `tests/Unit/Entity/MoneyTest`  | Unit                    | Arithmetic, equality and validation of `Money`             |
| `tests/Unit/Entity/OrderTest`  | Unit                    | Business rules of `Order::place()`, price snapshots        |
| `tests/Api/CustomerAuthTest`   | Functional (HTTP)       | Registration, login, access to profiles                    |
| `tests/Api/ProductTest`        | Functional (HTTP)       | Public catalog, admin management, validation, JSON schema  |
| `tests/Api/PlaceOrderTest`     | Functional (HTTP)       | Ordering, stock, rollback, validation, authorization       |

The functional tests run against a real PostgreSQL test database (`db_test`) and truncate the
tables before every test. They create their data with the same Foundry factories as the dev
fixtures. `tests/Api/WebshopTestTrait.php` wraps them so each test pins down exactly what it relies on
(SKU, price, stock, active) and leaves the rest random. The login helpers live there too.
Foundry's PHPUnit extension is registered in `phpunit.dist.xml`.

### Static analysis

- `phpstan.dist.neon` loads the Symfony container (`var/cache/dev/…Container.xml`) and the real
  Doctrine mapping (`tests/object-manager.php`). That way PHPStan also checks service IDs,
  repository types and DQL.
- Want different settings locally? Create a `phpstan.neon` (it's git-ignored).

---

## Project structure

```
├── config/                  Symfony configuration (packages, routes, services)
├── migrations/              Doctrine migrations (never change the schema by hand)
├── src/
│   ├── Controller/          LoginController (route for the json_login firewall)
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
├── tests/
│   ├── Api/                 Functional HTTP tests
│   └── Unit/                Domain unit tests
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
