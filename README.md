# Webshop API

[![CI](https://github.com/jparengkuan/api-platform-webshop/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/jparengkuan/api-platform-webshop/actions/workflows/ci.yml)

A REST/JSON-LD API for a simple webshop, built with **Symfony 8.1** and **API Platform 5**.
Customers can register, log in through an OAuth2 client application, browse the catalog and place
an order, and pay it with Stripe. Administrators maintain the product catalog. Every paid order is
announced on RabbitMQ; a Python service turns it into an invoice PDF, which the API mails to the
customer.

The code follows the [Symfony best practices](https://symfony.com/doc/current/best_practices.html),
the SOLID principles and a set of targeted design patterns (see [Architecture](#architecture)).

---

## Contents

- [Features](#features)
- [Tech stack](#tech-stack)
- [Installation](#installation)
- [Authentication (OAuth2)](#authentication-oauth2)
- [Using the API](#using-the-api)
- [Payments (Stripe)](#payments-stripe)
- [Architecture](#architecture)
- [Events (RabbitMQ)](#events-rabbitmq)
  - [Invoice service (Python)](#invoice-service-python)
  - [Invoice email (Messenger worker)](#invoice-email-messenger-worker)
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
| Payments  | Pay an order with Stripe Checkout (cards, Apple/Google Pay, iDEAL); unpaid orders expire after 75 minutes |
| Stock     | Reserved when an order is placed, safe under concurrent orders; released when an order expires |
| Events    | `OrderPlaced` and `OrderPaid` are published to RabbitMQ (JSON), for other systems   |
| Invoices  | A Python service turns every `OrderPaid` into an invoice PDF (`invoice.generated`); the API mails it to the customer |

## Tech stack

| Component        | Choice                                                           |
|------------------|------------------------------------------------------------------|
| Language         | PHP 8.4                                                          |
| Framework        | Symfony 8.1                                                      |
| API              | API Platform 5 (JSON-LD/Hydra, OpenAPI)                          |
| Database         | PostgreSQL 17 via Doctrine ORM 3 and Doctrine Migrations         |
| Messaging        | Symfony Messenger with the AMQP transport, RabbitMQ 4            |
| Email            | Symfony Mailer with Twig templates; Mailpit in DDEV              |
| Payments         | Stripe Checkout (`stripe/stripe-php`), webhooks                  |
| Scheduled tasks  | Symfony Scheduler, run by the Messenger worker                   |
| Invoice service  | Python 3.13 with `pika` and `fpdf2`; Ruff and pytest             |
| Authentication   | OAuth2 Authorization Code + PKCE (`league/oauth2-server-bundle`); JWT access tokens, refresh tokens |
| Local environment| [DDEV](https://ddev.com) (nginx-fpm, PHP 8.4, PostgreSQL 17, RabbitMQ add-on, Mailpit) |
| Production       | Docker: FrankenPHP (worker mode) behind Varnish 8, with tag-based cache invalidation; RabbitMQ 4 |
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
# 1. Start the containers: web (with the PHP amqp extension and the Messenger worker),
#    PostgreSQL, RabbitMQ and the Python invoice service
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
Emails (the invoices) land in Mailpit: `ddev mailpit` opens it.

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
| `MESSENGER_TRANSPORT_DSN` | RabbitMQ that receives the events; DDEV points it at its RabbitMQ add-on. Tests use an in-memory transport |
| `MAILER_DSN`         | Where emails go. `.env`: `null://null` (nothing is sent); DDEV: Mailpit; tests: always `null://null` |
| `MAILER_FROM`        | Sender address of the emails to customers                        |
| `STRIPE_SECRET_KEY`  | Stripe secret key; `sk_test_…` locally and on staging. Empty in `.env`: set it in `.env.dev.local` |
| `STRIPE_WEBHOOK_SECRET` | Signing secret of the webhook endpoint (`whsec_…`); tests use their own (`.env.test`) |
| `FRONTEND_URL`       | Where Stripe sends the customer back to after paying (default `http://localhost:3000`) |
| `APP_SECRET`         | Symfony secret; in production via the secrets vault              |

### Test data

`ddev composer fixtures` fills the dev database with a realistic Dutch webshop in about 4 seconds:

| What        | Amount | Details                                                                  |
|-------------|--------|--------------------------------------------------------------------------|
| OAuth2 client | 1    | `webshop-demo`, secret `demo-secret`, redirect URI `http://localhost:3000/callback` |
| Accounts    | 2      | `admin@example.com` (admin) and `klant@example.com` (customer)           |
| Customers   | 50     | Dutch names; the email matches the name (`floris.devries123@example.net`) |
| Categories  | 6      | Accessoires, Audio, Kantoor, Kleding, Mokken & flessen, Tassen           |
| Products    | 200    | E.g. "Hoodie Utrecht, marineblauw" in "Kleding"; prices ending in ,95; ~10% inactive |
| Orders      | ~460   | 1–5 lines each, spread over the last year, never before registration; all paid |

**Every account's password is `password`.** Log in as `klant@example.com` to see a customer with
order history, or as `admin@example.com` to manage the catalog. All addresses use the reserved
`example.com/.net/.org` domains, so the demo data (also on staging) can never email a real person.

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
| POST    | `/api/orders`           | authenticated           | Place an order; send an `Idempotency-Key` header to make retries safe (see below) |
| GET     | `/api/orders`           | authenticated           | Own orders (admin: all), newest first          |
| GET     | `/api/orders/{id}`      | owner or admin          | A single order                                 |
| POST    | `/api/orders/{id}/payment` | owner                | Start paying: returns a Stripe Checkout URL (see [Payments](#payments-stripe)) |
| POST    | `/stripe/webhook`       | Stripe (signed)         | Stripe reports payments here; not part of the `/api` |

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

# Place an order. The Idempotency-Key (one per cart, e.g. a UUID) makes a retry return the same order.
curl -X POST $API/orders \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: 4f9d1c2e-8a7b-4c3d-9e1f-0a2b3c4d5e6f' \
  -d '{
    "lines": [
      { "product": "/api/products/0199aaaa-0000-7000-8000-000000000001", "quantity": 2 },
      { "product": "/api/products/0199aaaa-0000-7000-8000-000000000002", "quantity": 1 }
    ]
  }'
```

Response (`201 Created`, header `Location: /api/orders/0199bbbb-…`, body abbreviated):

```json
{
  "@id": "/api/orders/0199bbbb-…",
  "@type": "Order",
  "id": "0199bbbb-…",
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
  "placedAt": "2026-09-27T12:00:00+00:00",
  "paidAt": null,
  "payableUntil": "2026-09-27T12:30:00+00:00",
  "paymentUrl": "/api/orders/0199bbbb-…/payment"
}
```

The order's id is in the body (`id`, and `@id` in JSON-LD) and in the `Location` header. **Every
order says what can happen next:** `paymentUrl` and `payableUntil` are set only while the order can
be paid right now (placed, and within its 30 minutes); otherwise both are `null`. A client shows a
"Pay" button exactly when `paymentUrl` is there, on the confirmation page and in "my orders",
without repeating the payment rules. Pay by posting to that URL:

```bash
curl -X POST $API/orders/0199bbbb-…/payment -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{}'
# 201: { "checkoutUrl": "https://checkout.stripe.com/c/pay/cs_test_…", "checkoutId": "cs_test_…", "expiresAt": "…" }
```

Send the customer to `checkoutUrl`. After paying, `GET /api/orders/{id}` shows `"status": "paid"`,
a `paidAt`, and no `paymentUrl` anymore.

#### Safe retries: `Idempotency-Key`

A double click or a network retry must not place two orders (each would reserve stock for 75
minutes). The client generates a key once per cart, for example a UUID, and sends it with
`POST /api/orders`:

| Request                                         | Result                                                        |
|-------------------------------------------------|---------------------------------------------------------------|
| First request with a key                         | `201`, a new order                                            |
| Same key, same lines (retry, double click)       | `201`, **the same order**: no second order, no second stock reservation, no second `OrderPlaced` |
| Same key, different lines                        | `422`: the key belongs to another cart; use a new key          |
| Same key from another customer                   | a separate order: keys are per customer                        |
| Key longer than 255 characters or not visible ASCII | `422` with a violation on `Idempotency-Key`                 |
| No key                                           | `201`, a new order every time (as before)                      |

Two identical requests at the same moment are safe too: they lock the same products, so the second
waits for the first and then finds its order. A unique index on (customer, key) is the last guard:
if two *different* carts race with one key, one wins and the other gets `409`.

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

## Payments (Stripe)

Orders are paid with [Stripe Checkout](https://docs.stripe.com/payments/checkout), Stripe's hosted
payment page. The API owns the payment: it creates the checkout from the order's own total (never
from what a client sends) and learns about the payment from Stripe's signed webhook. A frontend
only calls one endpoint and redirects.

```
Frontend                      API                                    Stripe
────────                      ───                                    ──────
POST /api/orders ───────────▶ order "placed", stock reserved
POST /api/orders/{id}/payment ▶ StartPaymentHandler ───────────────▶ Checkout session (lines, total)
◀── { checkoutUrl } ─────────
redirect to checkoutUrl ─────────────────────────────────────────▶ customer pays (card, iDEAL, …)
                              POST /stripe/webhook ◀──────────────── checkout.session.completed (signed)
                              ConfirmPaymentHandler: order "paid"
                              ──▶ OrderPaid ──▶ invoice service ──▶ invoice email
◀── redirect to FRONTEND_URL/checkout/success?order=…&session_id=…
GET /api/orders/{id} ────────▶ "status": "paid"
```

### Order lifecycle

| Status    | Meaning                                                      | Next                         |
|-----------|--------------------------------------------------------------|------------------------------|
| `placed`  | Stock reserved, awaiting payment                              | `paid`, or `expired`         |
| `paid`    | Stripe confirmed the payment (`paidAt`, Stripe PaymentIntent id stored) | final           |
| `expired` | Not paid in time; the stock is back on the shelf              | final                        |

| Rule                                                                   | Where                              |
|------------------------------------------------------------------------|------------------------------------|
| A payment can be started up to **30 minutes** after placing (`Order::PAYMENT_WINDOW_MINUTES`) | `Order::assertPayable()` → 422 |
| Only the customer who placed the order can pay it                       | `security` on the operation → 403  |
| A Stripe checkout stays open for 30 minutes (Stripe's minimum)          | `StripeCheckoutGateway`            |
| Unpaid orders expire **75 minutes** after placing: 30 + 30 + a margin for late webhooks (`Order::EXPIRES_AFTER_MINUTES`) | `ExpireUnpaidOrdersHandler`, every 5 minutes |
| Only immediate payment methods (cards incl. Apple/Google Pay, iDEAL): stock can't wait days for a bank transfer | `StripeCheckoutGateway` |
| Every webhook call must carry a valid `Stripe-Signature`; anything else is a `400` | `StripeWebhookParser` |
| Stripe may send the same event twice: the order is paid, and `OrderPaid` published, only once | `Order::markPaid()` |
| Payment and expiry lock the order row (`SELECT … FOR UPDATE`), so they can't both happen | `ConfirmPaymentHandler`, `ExpireOrderHandler` |
| A payment that arrives after the order expired isn't applied; it's logged as **critical** with the PaymentIntent id, to refund | `ConfirmPaymentHandler` |

### Trying it locally (test mode, free)

Stripe's test mode costs nothing and moves no real money. You need a (free) Stripe account for
the test keys.

1. Put the test key in `.env.dev.local` (git-ignored): `STRIPE_SECRET_KEY=sk_test_…`
2. Forward Stripe's webhooks to DDEV with the Stripe CLI (in Docker, on DDEV's network):
   ```bash
   docker run --rm -it --network ddev-api-platform_default -e STRIPE_API_KEY=sk_test_… \
     stripe/stripe-cli listen --forward-to http://web/stripe/webhook \
     --events checkout.session.completed,checkout.session.async_payment_succeeded
   ```
   It prints a `whsec_…` secret: put it in `.env.dev.local` as `STRIPE_WEBHOOK_SECRET`.
3. Place an order, call `POST /api/orders/{id}/payment` and open the `checkoutUrl`.
4. Pay with a [test card](https://docs.stripe.com/testing): `4242 4242 4242 4242` (any future
   date, any CVC) succeeds, `4000 0000 0000 9995` is declined, `4000 0025 0000 3155` asks for
   3D Secure. With iDEAL, Stripe's test page lets you approve or fail the payment.
5. The order becomes `paid`; the invoice email shows up in Mailpit (`ddev mailpit`).

If Stripe answers that `ideal` is an invalid payment method, enable iDEAL in the dashboard
(Settings → Payment methods).

### Tests

The tests never call Stripe. `FakeCheckoutGateway` replaces the Checkout adapter in the test
environment, and the webhook tests sign their own payloads with the test secret from `.env.test`,
exactly like Stripe does (HMAC-SHA256 of `timestamp.payload`). `tests/Api/PaymentTest` covers
`paymentUrl`/`payableUntil` per state, starting a payment (ownership, the payment window, a paid
order) and the webhook (paid, repeated, bad signature, unpaid, after expiry);
`tests/Integration/ExpireUnpaidOrdersTest` covers expiry and stock release.

### Going live

Nothing changes in the code: in production set the live keys (`sk_live_…`), and in the Stripe
dashboard add the webhook endpoint `https://<api domain>/stripe/webhook` for
`checkout.session.completed` and `checkout.session.async_payment_succeeded`; its signing secret
becomes `STRIPE_WEBHOOK_SECRET`. Staging uses the test keys and its own test-mode endpoint.

---

## Architecture

The code uses Symfony's standard directory layout (`Entity/`, `Repository/`, `State/`, …; see
[Project structure](#project-structure)) and the standard building blocks for the rest:

- **Every use case is a command on the Messenger bus.** Processors and controllers dispatch a
  command (`PlaceOrder`, `StartPayment`, `PaymentReceived`, `ExpireOrder`) through `CommandBus`;
  its `#[AsMessageHandler]` runs synchronously. The bus middleware wraps each command in **one
  database transaction** (`doctrine_transaction`), so handlers contain no transaction code.
- **Events go through a transactional outbox.** A handler records its event in the `outbox`
  (a Doctrine transport) inside that same transaction; the worker relays it to RabbitMQ. An event
  is stored if and only if its change is, and is delivered even when RabbitMQ was down.
- **Ports and adapters.** Use cases depend on interfaces; the Doctrine, Messenger, Mailer and
  Stripe specifics live in the classes that implement them, bound with `#[AsAlias]`.
- **Access rules live in a voter** (`OrderVoter`), not in expressions repeated per operation.

### Placing an order

```
HTTP (API Platform)       Command bus (one transaction)                Domain                   Adapters
───────────────────       ─────────────────────────────                ──────                   ────────
PlaceOrderInput (DTO) ─┐
                       ├▶ PlaceOrderProcessor ─▶ CommandBus ─▶ PlaceOrderHandler ─▶ Order::place()          ProductRepository
Validator (#[Assert]) ─┘     (adapter)       PlaceOrder        │                    Product::reserveStock()  OrderRepository
                                                               └▶ EventPublisherInterface ─▶ outbox table ──(worker)──▶ RabbitMQ
```

1. **API Platform** deserializes the request into `PlaceOrderInput` and validates it with the
   Validator constraints on the DTO, and the optional `Idempotency-Key` header with the constraints
   of its `HeaderParameter`.
2. **`PlaceOrderProcessor`** (adapter) dispatches a `PlaceOrder` command with the logged-in customer
   and the `Idempotency-Key`. The bus opens a transaction.
3. **`PlaceOrderHandler`** loads the products with a write lock. With an `Idempotency-Key` it then
   looks for an order placed with that key; if there is one, it returns that order and stops here.
4. **`Order::place()`** checks the business rules, reserves stock and calculates the total.
5. The handler stores the order and records `OrderPlaced` in the outbox; the bus commits both
   together. API Platform returns the order as `201`, with `OrderPaymentNormalizer` adding
   `paymentUrl` and `payableUntil`. On failure the transaction rolls back: no order, no event.

### Paying an order

```
POST /api/orders/{id}/payment ─▶ StartPaymentProcessor ─▶ CommandBus(StartPayment) ─▶ StartPaymentHandler ─▶ Order::assertPayable()
                                                                                         └▶ CheckoutGatewayInterface ─▶ StripeCheckoutGateway ─▶ Stripe
POST /stripe/webhook ─▶ StripeWebhookController ─▶ StripeWebhookParser (signature) ─▶ CommandBus(PaymentReceived)
                        ─▶ ConfirmPaymentHandler ─▶ Order::markPaid() └▶ EventPublisherInterface (OrderPaid → outbox)
Scheduler (every 5 min) ─▶ ExpireUnpaidOrdersHandler ─▶ one ExpireOrder per order, each in its own transaction
                        ─▶ ExpireOrderHandler ─▶ Order::expire() (stock back)
```

### Mailing an invoice

```
RabbitMQ invoice_generated ─▶ InvoiceEventSerializer ─▶ SendInvoiceEmailHandler ─▶ CustomerRepositoryInterface ─▶ CustomerRepository
                              (JSON → InvoiceGenerated)   (use case)               └▶ InvoiceMailerInterface     ─▶ TwigInvoiceMailer ─▶ SMTP
```

### Ports and adapters

| Port (interface)                | Adapter                        | Used by                                    |
|---------------------------------|--------------------------------|--------------------------------------------|
| `ProductRepositoryInterface`    | `ProductRepository` (Doctrine) | `PlaceOrderHandler`, `ExpireOrderHandler`  |
| `OrderRepositoryInterface`      | `OrderRepository` (Doctrine)   | `PlaceOrderHandler`, `ConfirmPaymentHandler`, `ExpireOrderHandler`, `ExpireUnpaidOrdersHandler` |
| `CustomerRepositoryInterface`   | `CustomerRepository` (Doctrine)| `SendInvoiceEmailHandler`                  |
| `EventPublisherInterface`       | `MessengerEventPublisher` (Messenger → outbox → RabbitMQ) | `PlaceOrderHandler`, `ConfirmPaymentHandler` |
| `InvoiceMailerInterface`        | `TwigInvoiceMailer` (Mailer + Twig) | `SendInvoiceEmailHandler`             |
| `CheckoutGatewayInterface`      | `StripeCheckoutGateway` (Stripe Checkout) | `StartPaymentHandler`           |

In unit tests the ports get in-memory stand-ins (`tests/Support/RecordingEventPublisher`,
`FakeCheckoutGateway`, stubs), so the use cases are tested without a database, broker, mail server
or Stripe.

### SOLID in practice

| Principle                 | Where                                                                                      |
|---------------------------|--------------------------------------------------------------------------------------------|
| **S**ingle Responsibility | Processor = HTTP translation, command bus = transactions, handler = use case, entities = business rules, repository = storage, outbox relay = delivery to RabbitMQ, voter = access rules, mailer = building and sending the email |
| **O**pen/Closed           | A new business rule = a new subclass of `BusinessRuleViolation`, the 422 mapping doesn't change. A new event implements `IntegrationEvent` and names its own routing key; the publisher doesn't change |
| **L**iskov Substitution   | Every implementation of a port honors the same contract, e.g. `EventPublisherInterface::publish()` records the event as part of the running transaction, in production (outbox) and in the test double |
| **I**nterface Segregation | Small ports with only what the use cases need: `findForUpdate()`, `add()`, `findByIdempotencyKey()`, `findUnpaidPlacedBefore()`, `findById()`, `publish()`, `send()`, `createCheckout()`. The Stripe webhook check is a separate class (`StripeWebhookParser`), not part of the checkout port |
| **D**ependency Inversion  | The use cases depend on ports and `Psr\Clock\ClockInterface`, not on Doctrine, Messenger, Mailer or Stripe classes |

### Design patterns

| Pattern                 | Class(es)                                         | Why                                                        |
|-------------------------|---------------------------------------------------|------------------------------------------------------------|
| Ports and Adapters      | See the table above                               | Use cases independent of frameworks; easy to test and replace |
| Repository              | `ProductRepository`, `OrderRepository`, `CustomerRepository` | Storage logic separated from domain and use case |
| Command Bus             | `CommandBus`, commands `PlaceOrder`, `StartPayment`, `PaymentReceived`, `ExpireOrder` with their `#[AsMessageHandler]` | Every use case is triggered the same way, from HTTP, the webhook or the scheduler |
| Unit of Work            | `doctrine_transaction` middleware                 | One transaction per command, committed or rolled back as a whole |
| Transactional Outbox    | `outbox` transport, `MessengerEventPublisher`, `RelayIntegrationEventHandler` | Events are stored with their change and delivered at least once |
| Voter                   | `OrderVoter` (`ORDER_VIEW`, `ORDER_PAY`)          | One place for who may see and pay an order                 |
| Publish/Subscribe       | `IntegrationEvent`, `OrderPlaced`, `OrderPaid`, `InvoiceGenerated` over the `webshop.events` topic exchange | Services react to events without knowing each other |
| Adapter                 | `PlaceOrderProcessor`, `StartPaymentProcessor`, `MessengerEventPublisher`, `TwigInvoiceMailer`, `StripeCheckoutGateway` | Connects a framework or provider to a use case |
| Translator              | `InvoiceEventSerializer`                          | Maps the Python service's JSON (`type: invoice.generated`) to a PHP message |
| Data Transfer Object    | `PlaceOrderInput`, `OrderLineInput`               | API contract decoupled from the domain model               |
| Aggregate Root          | `Order` (with `OrderLine`)                        | Lines can only be created through the order                |
| State                   | `OrderStatus` with `Order::markPaid()`, `Order::expire()`, `Order::assertPayable()` | Only valid transitions; paying and expiring are idempotent |
| Scheduler               | `Schedule` with `ExpireUnpaidOrders`              | Recurring work in the same worker, without cron            |
| Factory Method          | `Order::place()`, `OrderPlaced::fromOrder()`, `OrderPaid::fromOrder()`, `Money::zero()`, named constructors on exceptions | Always a valid object; intent in the name |
| Value Object            | `Money` (Doctrine embeddable, `readonly`)         | Immutable, self-validating, no float rounding              |
| Snapshot                | `OrderLine` copies SKU, name and price            | Later catalog changes never alter past orders              |
| Query Extension         | `CurrentCustomerOrdersExtension`                  | Automatically filters `GET /api/orders` to the logged-in customer |
| Decorator (serializer)  | `OrderPaymentNormalizer`                          | Adds `paymentUrl`/`payableUntil` to every order, using the clock; the entity stays free of it |
| Idempotent Receiver     | `Idempotency-Key` in `PlaceOrderHandler`          | A repeated request returns the first result instead of acting twice |
| Strategy                | `InvoiceRenderer` in the Python service           | The document format can change without touching the consumer |

### Concurrency

Two customers ordering the last item at the same time must not both succeed. That's why:

- `ProductRepository::findForUpdate()` loads products with `SELECT … FOR UPDATE` (pessimistic
  write lock), inside the handler's transaction.
- Products are always locked **in id order**, so two orders containing the same products can't
  deadlock each other.
- `Query::HINT_REFRESH` makes sure stock is read from the database *after* the lock is acquired,
  even when the product was already in the identity map.
- On an exception, the `doctrine_transaction` middleware rolls back and
  `ClearEntityManagerOnFailureMiddleware` detaches the changed entities. Stock already deducted in
  memory can therefore never be flushed by a later command.
- The same product locks serialize two identical requests with one `Idempotency-Key`: the second
  one waits, then finds the first one's order. A unique index on (customer, key) catches the rest.
- Paying and expiring lock the **order row** (`OrderRepository::findForUpdate()`), so a payment and
  the expiry task can never both change one order. Expiry also locks the products before it puts
  their stock back.

---

## Events (RabbitMQ)

Other systems learn about orders from events, not by polling the API. The events travel through a
**transactional outbox** (transports in `config/packages/messenger.yaml`):

```
handler ─▶ EventPublisherInterface ─▶ outbox (table messenger_messages, same transaction as the change)
worker  ─▶ RelayIntegrationEventHandler ─▶ events (RabbitMQ, exchange webshop.events, routing key per event)
```

| Event        | Routing key    | Recorded by              | Queue in this project              |
|--------------|----------------|--------------------------|------------------------------------|
| `OrderPlaced`| `order.placed` | `PlaceOrderHandler`      | none: bind your own to receive it  |
| `OrderPaid`  | `order.paid`   | `ConfirmPaymentHandler`  | `order_paid`, read by the [invoice service](#invoice-service-python) |
| `invoice.generated` | `invoice.generated` | the invoice service | `invoice_generated`, read by the [mailer worker](#invoice-email-messenger-worker) |

The API's events are JSON (`content_type: application/json`) with the header
`type: App\Message\OrderPlaced` or `App\Message\OrderPaid`. `OrderPaid` looks like this
(`OrderPlaced` is the same without `paidAt`):

```json
{
  "orderId": "01a0e480-2c56-7901-8a69-d653265d5d1f",
  "customerId": "01a0e480-2bc1-7f5f-8f48-3edd8527ee2c",
  "placedAt": "2026-09-22T20:54:48+00:00",
  "paidAt": "2026-09-22T20:58:12+00:00",
  "totalAmount": 26775,
  "currency": "EUR",
  "lines": [
    {"productId": "01a0e480-2bf6-706d-b99f-ff1c55f121de", "sku": "NOT-57333", "name": "Notitieboek Amsterdam, zwart", "quantity": 1, "unitPrice": 1395}
  ]
}
```

Amounts are in minor units (cents), like everywhere in the API. `productId` is `null` when the
product no longer exists.

- **Stored with the change:** the event is written to the outbox in the same transaction as the
  order or payment. A rolled-back order is never announced; a committed one always is.
- **At least once:** the worker relays the outbox to RabbitMQ. When RabbitMQ is down, the relay is
  retried (for about an hour and a half), then kept in the failure transport
  (`bin/console messenger:failed:show`, `messenger:failed:retry`). Orders and payments never wait
  for RabbitMQ. A crash between relaying and marking the message done can deliver an event twice,
  so consumers must tolerate duplicates (the invoice service does: same invoice number).
- **Consumers:** each system binds **its own** queue to `webshop.events` with the key it needs, so
  each one receives every event. Don't read `order_paid`: the invoice service would miss those
  orders. The API doesn't consume its own events.

Locally, DDEV's RabbitMQ add-on receives the events. Inspect them with:

```bash
ddev exec -s rabbitmq rabbitmqctl list_queues name messages consumers
ddev exec -s rabbitmq rabbitmqadmin get messages --queue=invoice_generated
```

The management UI runs at `https://api-platform.ddev.site:15673` (user `rabbitmq`, password `rabbitmq`).

Watch the outbox from the database: `SELECT queue_name, count(*) FROM messenger_messages GROUP BY 1`
(rows with `queue_name = 'outbox'` are waiting for the worker; `failed` needs attention).

In tests the `outbox` transport is `in-memory://?serialize=true`: `PlaceOrderTest` checks the
recorded event after its JSON round trip, `OutboxRelayTest` that the relay publishes it to the
`events` transport with its routing key, and `MessengerEventPublisherTest` that a failure to record
it fails the command instead of losing the event.

### Invoice service (Python)

`services/invoice/` is a small Python 3.13 service (`pika`, `fpdf2`) that turns every **paid**
order into an invoice PDF:

```
API ──order.paid──▶ webshop.events ──▶ order_paid ──▶ invoice service
                                                             │ renders the PDF
    invoice_generated ◀── webshop.events ◀──invoice.generated┘
```

1. It consumes the `order_paid` queue, one message at a time.
2. It renders the invoice (number, invoice date = payment date, order date, customer, lines, total,
   "Paid") as an A4 PDF.
3. It publishes an **`invoice.generated`** event to the `webshop.events` exchange, with the PDF
   Base64-encoded in the JSON. The durable `invoice_generated` queue keeps these events until the
   API's [mailer worker](#invoice-email-messenger-worker) reads them.
4. Only then does it acknowledge the `OrderPaid` message.

```json
{
  "invoiceNumber": "INV-01a0e480-2c56-7901-8a69-d653265d5d1f",
  "orderId": "01a0e480-2c56-7901-8a69-d653265d5d1f",
  "customerId": "01a0e480-2bc1-7f5f-8f48-3edd8527ee2c",
  "generatedAt": "2026-09-27T23:22:04+00:00",
  "fileName": "INV-01a0e480-2c56-7901-8a69-d653265d5d1f.pdf",
  "contentType": "application/pdf",
  "pdfBase64": "JVBERi0xLjMK…"
}
```

The message has `content_type: application/json`, the header `type: invoice.generated` (read by
Symfony Messenger) and the invoice number as `message_id`.

| Situation                            | What happens                                                        |
|--------------------------------------|---------------------------------------------------------------------|
| RabbitMQ restarts or the service crashes mid-way | The unacknowledged `OrderPaid` is delivered again; the service reconnects every 5 seconds |
| The same order arrives twice         | Same invoice number (`INV-<orderId>`), so consumers can drop duplicates by `message_id` |
| Malformed message                    | Logged and rejected without requeue (it would never succeed)        |
| Other routing keys                   | Acknowledged and ignored                                            |

Develop and test it (Python 3.13, or in any `python:3.13` container):

```bash
cd services/invoice
pip install -r requirements-dev.txt
ruff check . && ruff format --check .
pytest
```

In DDEV it runs as the `invoice` service with its code mounted: `ddev restart` picks up changes and
`ddev logs -s invoice` shows what it does. CI runs Ruff and pytest in the **Invoice service** job,
and staging deploys only when that job passes too.

Limitations: the invoice shows the customer id, not a name or address (the event doesn't carry
them), has no VAT breakdown and only uses Latin-1 characters (anything else becomes `?`).

### Invoice email (Messenger worker)

The API mails every invoice to the customer. A Messenger worker consumes the `invoice_generated`
queue (transport `invoices` in `config/packages/messenger.yaml`). The same worker also relays the
outbox (transport `outbox`) and runs the scheduled tasks (transport `scheduler_default`, see
`src/Schedule.php`):

1. `InvoiceEventSerializer` turns the JSON of the Python service into an `InvoiceGenerated` message
   (Symfony's serializer maps the `type: invoice.generated` header to the class).
2. `SendInvoiceEmailHandler` looks up the customer by `customerId` and checks the PDF.
3. `TwigInvoiceMailer` sends an HTML and text email (`templates/emails/invoice.*.twig`) from
   `MAILER_FROM`, with the PDF attached.

| Situation                            | What happens                                                        |
|--------------------------------------|---------------------------------------------------------------------|
| The mail server is unreachable       | Retried after 1, 5 and 25 minutes, then kept in the failure transport (`messenger:failed:retry`) |
| Unknown customer, or no valid PDF    | Rejected at once without retry (`UnrecoverableMessageHandlingException`) |
| The worker restarts                  | Unacknowledged messages are delivered again                         |

Running the worker:

| Where      | How                                                                              |
|------------|----------------------------------------------------------------------------------|
| DDEV       | A daemon in the web container (`web_extra_daemons` in `.ddev/config.yaml`); emails go to Mailpit (`ddev mailpit`) |
| Production | The `worker` service in `docker-compose.yaml`: the app image running `bin/console messenger:consume outbox invoices scheduler_default` |
| By hand    | `ddev exec bin/console messenger:consume outbox invoices scheduler_default -vv`   |

After changing PHP code, restart the worker (`ddev restart`, or `bin/console messenger:stop-workers`):
it keeps the old code in memory.

`tests/Integration/InvoiceEmailTest` feeds a message exactly as the Python service sends it through
the serializer and the handler, and checks the email, its recipient and the attachment.

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
| Only a placed order can be paid, within 30 minutes of placing | `Order::assertPayable()`             | 422       |
| One order per `Idempotency-Key` per customer; a repeat returns it, other lines are rejected | `PlaceOrderHandler`, unique index | 201 / 422 / 409 |
| An unpaid order expires after 75 minutes; its stock is released | `Order::expire()` (scheduled)      | —         |

---

## Error handling

Errors are returned as [RFC 7807 Problem Details](https://www.rfc-editor.org/rfc/rfc7807)
(in JSON-LD as a Hydra `Error`).

| Status | When                                                                     |
|--------|--------------------------------------------------------------------------|
| 400    | Invalid JSON or an unknown IRI; a Stripe webhook call without a valid signature |
| 401    | Missing or invalid token                                                 |
| 403    | Authenticated but not allowed (e.g. someone else's order, not an admin)  |
| 404    | Resource does not exist                                                  |
| 409    | Conflict: a category that still has products; two different carts raced with one `Idempotency-Key` |
| 422    | Validation error (`violations`, also for an invalid `Idempotency-Key`) or broken business rule (`detail`) |
| 503    | Stripe can't be reached when starting a payment (`Retry-After: 30`)      |

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

- All business errors extend `App\Exception\BusinessRuleViolation`: the stock errors of the
  catalog directly, the order's own rules through `OrderingException`.
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
- **Authorization per operation:** through `security:` in the `#[ApiResource]` attributes. Roles
  for the catalog (`is_granted('ROLE_ADMIN')`); for orders the `OrderVoter`:
  `is_granted('ORDER_VIEW', object)` (the customer, or an administrator) and
  `is_granted('ORDER_PAY', object)` (only the customer, not even an administrator).
- **Collections:** `CurrentCustomerOrdersExtension` limits the order list to the customer's own
  orders. This happens in the query itself, so no one else's data can leak through pagination or
  filters.
- **Passwords:** hashed with the `auto` hasher and upgraded automatically on login
  (`PasswordUpgraderInterface`). On registration the password is checked against known data
  breaches (`NotCompromisedPassword`). The plain-text password is never stored or returned.
- **Identifiers:** UUID v7, so they can't be guessed or enumerated.
- **Payments:** the amount always comes from the order in the database, never from the client. The
  Stripe webhook only accepts calls signed with `STRIPE_WEBHOOK_SECRET` (and at most 5 minutes
  old, against replays); only the customer who placed an order can start its payment.
- **Idempotency keys** are scoped per customer, so one customer's key can never return another
  customer's order.
- **Secrets:** the OAuth2 signing keys (`config/jwt/*.pem`) and `.env.*.local` are git-ignored;
  client secrets are stored hashed. Stripe keys live only in `.env.*.local` or the platform's
  environment variables; the frontend never needs one.

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
pull request. Four jobs run in parallel and must all pass:

| Job                                  | Runs                                           | Where errors show up                         |
|--------------------------------------|------------------------------------------------|----------------------------------------------|
| **Code style (PHP-CS-Fixer)**        | `php-cs-fixer fix --dry-run`                   | Annotations on the lines + a diff in the log |
| **Static analysis (PHPStan level 5)**| `phpstan analyse`                              | Annotations on the lines + a table in the log|
| **Tests (PHPUnit)**                  | Full suite against PostgreSQL 17               | Annotations on the failing assertion + a list in the job summary |
| **Invoice service (Ruff, pytest)**   | `ruff check`, `ruff format --check`, `pytest` in `services/invoice` | Annotations on the lines + the pytest output |

On a push to `develop`, **Deploy to test** runs only after all four passed (see [Staging](#staging)).

Annotations appear in the **Files changed** tab of a pull request and in the **Summary** of the
workflow run, so you rarely need to dig through the logs. Every failure also writes the command
to reproduce it locally into the job summary.

The CI mirrors the local commands: if `ddev composer qa` and the invoice service's
`ruff`/`pytest` pass, CI passes.

### Tests

| Suite                          | Type                    | What is tested                                             |
|--------------------------------|-------------------------|------------------------------------------------------------|
| `tests/Unit/Entity/MoneyTest`  | Unit                    | Arithmetic, equality and validation of `Money`             |
| `tests/Unit/Entity/OrderTest`  | Unit                    | Business rules of `Order::place()`, price snapshots, paying, the payment window, expiry |
| `tests/Unit/Ordering/PlaceOrderHandlerTest` | Unit       | The order is stored and `OrderPlaced` recorded; a rejected order is neither |
| `tests/Unit/Messenger/MessengerEventPublisherTest` | Unit | Events go onto the bus (the outbox); a failure to record one fails the command |
| `tests/Unit/Security/OrderVoterTest` | Unit              | Who may view and pay an order (owner, other customer, admin, anonymous) |
| `tests/Integration/ExpireUnpaidOrdersTest` | Integration (kernel) | Overdue unpaid orders expire and release stock, once; recent and paid orders stay |
| `tests/Integration/OutboxRelayTest` | Integration (kernel) | An event read from the outbox is published to RabbitMQ with its routing key, not back to the outbox |
| `tests/Integration/InvoiceEmailTest` | Integration (kernel) | Invoice from the Python service → email with PDF to the customer; unknown customer isn't retried; retry round trip |
| `tests/Api/CustomerAuthTest`   | Functional (HTTP)       | Registration, logging in, access to profiles               |
| `tests/Api/OAuthTest`          | Functional (HTTP)       | Authorization Code flow, PKCE, redirect URIs, client secret, refresh tokens, disabled grants |
| `tests/Api/ProductTest`        | Functional (HTTP)       | Public catalog, category filters, admin management, validation, JSON schema |
| `tests/Api/CategoryTest`       | Functional (HTTP)       | Public list, slugs, uniqueness, admin-only, delete protection |
| `tests/Api/HttpCacheTest`      | Functional (HTTP)       | Cache headers, cache tags, invalidation on changes and orders |
| `tests/Api/PlaceOrderTest`     | Functional (HTTP)       | Ordering, stock, rollback, validation, authorization, the id in body and `Location`, `Idempotency-Key` (repeat, other lines, per customer, invalid) |
| `tests/Api/PaymentTest`        | Functional (HTTP)       | `paymentUrl`/`payableUntil` per state, starting a Stripe payment (owner, window, paid), the signed webhook (paid, repeated, bad signature, unpaid, after expiry) |
| `services/invoice/tests/`      | Python (pytest)         | Parsing `OrderPaid`, the PDF's content, the `invoice.generated` payload, publish-then-acknowledge, malformed messages |

The functional tests run against a real PostgreSQL test database (`db_test`) and truncate the
tables before every test. They create their data with the same Foundry factories as the dev
fixtures. `tests/Support/DatabaseFixturesTrait.php` wraps them so each test pins down exactly what
it relies on (SKU, price, stock, active) and leaves the rest random. `tests/Api/WebshopTestTrait.php`
adds the HTTP helpers: its `login()` runs the real OAuth2 flow, exactly like a client application:
`/authorize` → login page → callback with a code → `/token`.
Foundry's PHPUnit extension is registered in `phpunit.dist.xml`.

### Static analysis

- `phpstan.dist.neon` loads the Symfony container (`var/cache/dev/…Container.xml`) and the real
  Doctrine mapping (`tests/object-manager.php`). That way PHPStan also checks service IDs,
  repository types and DQL.
- Want different settings locally? Create a `phpstan.neon` (it's git-ignored).

---

## Deployment (Docker)

DDEV is for development only. For servers there is a production stack in `docker-compose.yaml`:

```
reverse proxy (TLS)  →  Varnish :80  →  FrankenPHP :80  →  PostgreSQL 17
  e.g. Coolify           HTTP cache       Symfony app   ──→  RabbitMQ 4 (events)  ⇄  invoice service
                                                              │
                                          worker (mails the invoices)  ◀──┘  ──→  SMTP
```

- **FrankenPHP** runs PHP and the Caddy web server in one container, in **worker mode**: the
  Symfony kernel stays in memory between requests, so responses take a few milliseconds.
- **Varnish** caches public responses (see [HTTP caching](#http-caching-varnish)).
- **RabbitMQ** carries the events (see [Events](#events-rabbitmq)). It's only reachable inside
  the stack.
- **invoice** (Python) turns `OrderPaid` into invoice PDFs; **worker** (the app image running
  `messenger:consume outbox invoices scheduler_default`) relays the outbox to RabbitMQ, mails the
  invoices to the customers and expires unpaid orders.
- **TLS** is terminated by the hosting platform's reverse proxy in front of Varnish (Coolify
  does this automatically, with Let's Encrypt). The stack itself only speaks HTTP and publishes no
  public ports.

### Run it

The CI builds the app, varnish and invoice images and pushes them to the GitHub Container
Registry (`ghcr.io/jparengkuan/api-platform-webshop`, `…-varnish`, `…-invoice`); the server only
pulls them. A push to `develop` produces the tag `develop` (with the dev dependencies for the demo
data), a push to `master` the tag `master`; each build is also tagged `<branch>-<commit>`, to pin or
roll back to one build. The packages are private, so log the server in once with a token that has
`read:packages`: `docker login ghcr.io -u <github user>`.

```bash
cp .env.prod.local.dist .env.prod.local     # fill in real values; the copy is git-ignored
docker compose -f docker-compose.yaml --env-file .env.prod.local up -d --wait
curl http://127.0.0.1:8080/api/products     # through Varnish, from the server itself
```

To build the images on the machine itself instead, add `compose.build.yaml`:
`docker compose -f docker-compose.yaml -f compose.build.yaml --env-file .env.prod.local up -d --build --wait`.

| Variable                   | Meaning                                                               |
|----------------------------|-----------------------------------------------------------------------|
| `APP_SECRET`               | Symfony secret, e.g. `openssl rand -hex 32`                           |
| `OAUTH_PASSPHRASE`         | Encrypts the OAuth2 signing key, e.g. `openssl rand -hex 32`. Never change it afterwards |
| `OAUTH_ENCRYPTION_KEY`     | Encrypts authorization codes and refresh tokens, e.g. `openssl rand -hex 32` |
| `POSTGRES_PASSWORD`        | Database password (`POSTGRES_DB`/`POSTGRES_USER` default to `app`)    |
| `RABBITMQ_PASSWORD`        | RabbitMQ password (`RABBITMQ_USER` defaults to `app`), e.g. `openssl rand -hex 16`; the app's `MESSENGER_TRANSPORT_DSN` is built from it |
| `MAILER_DSN`               | Mail server for the invoice emails, e.g. `smtp://user:pass@smtp.example.com:587`. `null://null` sends nothing; use a sandbox (e.g. Mailtrap) on staging |
| `MAILER_FROM`              | Sender address of the invoice emails                                  |
| `STRIPE_SECRET_KEY`        | Stripe secret key: `sk_test_…` on staging, `sk_live_…` in production   |
| `STRIPE_WEBHOOK_SECRET`    | Signing secret of the webhook endpoint `https://<api domain>/stripe/webhook` in the Stripe dashboard |
| `FRONTEND_URL`             | Where Stripe sends the customer back to after paying                  |
| `CORS_ALLOW_ORIGIN`        | Regex of your frontend's origin(s)                                    |
| `VARNISH_SIZE`             | Memory for Varnish's cache (default `256M`)                            |
| `HTTP_PORT`                | Port on `127.0.0.1` of the server for local testing (default `8080`); give every stack on the same server its own port |
| `IMAGE_TAG`                | Which images to pull: `develop` for staging, `master` for production, or `<branch>-<commit>` to pin one build |
| `INSTALL_DEV_DEPENDENCIES` | Only with `compose.build.yaml`: `1` adds Foundry for the demo data (staging), `0` (default) for production |
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
tests pass: the `images` job in `.github/workflows/ci.yml` pushes the `develop` images to the
registry, then the `deploy-test` job calls the deploy webhook of the hosting platform (Coolify),
which pulls them. It needs two repository secrets, `COOLIFY_TEST_WEBHOOK` and
`COOLIFY_TOKEN`; without them the job skips itself with a notice.

Staging runs the same stack with `IMAGE_TAG=develop` (built with the dev dependencies) and
`RESET_DEMO_DATA=1`, so every deployment starts with the same fresh demo data (`admin@example.com`
and `klant@example.com`, password `password`). Never reuse staging secrets or its database for
production. Staging uses Stripe's **test** keys with its own test-mode webhook endpoint, so testers
pay with the test cards.

### What happens on start

The entrypoint of the app container (`docker/frankenphp/docker-entrypoint.sh`) runs before the web
server starts:

1. Generates the OAuth2 signing key pair on the first start (`app:oauth:generate-keys`, stored in the
   `jwt_keys` volume, reused afterwards).
2. Waits until PostgreSQL accepts connections.
3. With `RESET_DEMO_DATA=1` only: drops all tables.
4. Runs the migrations (`--all-or-nothing`: a failing migration changes nothing).
5. With `RESET_DEMO_DATA=1` only: loads the demo fixtures.

Compose starts the services in order and waits for each health check: PostgreSQL and RabbitMQ,
then the app and the invoice service, then Varnish and the worker. The worker runs the same image
without the web server; it stops after an hour (or 128 MB) and Docker starts a fresh one.

### The images

| Choice                                  | Why                                                                   |
|-----------------------------------------|-----------------------------------------------------------------------|
| `composer install --no-dev`             | No Foundry, PHPStan, PHPUnit or tests in production                    |
| `composer dump-env prod`                | `.env` compiled to PHP; real values come from environment variables    |
| Runs as the unprivileged user `app`     | A compromised process has no root in the container                     |
| OPcache without timestamp checks        | Code never changes in a running container                              |
| `.dockerignore` excludes `.env.*.local`, `config/jwt/*.pem`, `.git`, tests | Secrets and dev files never end up in the image |
| Health checks on the app (Caddy admin endpoint), Varnish (`varnishadm ping`) and RabbitMQ (`rabbitmq-diagnostics ping`); off for the worker, which has no web server | Docker and the platform see when a container is unhealthy |
| VCL baked into the Varnish image (`docker/varnish/Dockerfile`) | Deploying needs no bind mounts            |

Data lives in three named volumes: `database_data` (PostgreSQL), `jwt_keys` and `rabbitmq_data`
(events not consumed yet). **Back up `database_data`** (e.g. a daily `pg_dump`); it also holds the
OAuth2 clients, refresh tokens and the Stripe payment references. The signing keys are regenerated if lost (users then log in
again).

Run a console command in production:

```bash
docker compose -f docker-compose.yaml --env-file .env.prod.local exec php bin/console about
```

---

## Project structure

Symfony's standard layout: one directory per kind of class.

```
├── .github/                 CI workflow and the shared PHP setup action
├── config/                  Symfony configuration (packages, routes, services)
├── docker/
│   ├── frankenphp/          Caddyfile and entrypoint of the app image
│   ├── php/                 Production php.ini
│   └── varnish/             VCL and image of the HTTP cache
├── migrations/              Doctrine migrations (never change the schema by hand)
├── services/invoice/        Python service: OrderPaid in, invoice PDF out (invoice.generated)
├── src/
│   ├── ApiResource/         Shared API settings (HTTP cache headers)
│   ├── Command/             Console commands (app:oauth:generate-keys)
│   ├── Controller/          SecurityController (OAuth2 login page), StripeWebhookController
│   ├── Doctrine/            API Platform query extension (CurrentCustomerOrdersExtension)
│   ├── Dto/                 API input DTOs
│   ├── Entity/              Doctrine entities and API resources
│   │   └── Embeddable/      Value objects (Money)
│   ├── Enum/                Backed enums (OrderStatus)
│   ├── EventListener/       AuthorizationRequestListener: approves /authorize requests with PKCE
│   ├── Exception/           Domain exceptions (BusinessRuleViolation and its subclasses)
│   ├── Factory/             Foundry factories for test data (dev/test only)
│   ├── Mailer/              InvoiceMailerInterface and its Twig/Mailer implementation
│   ├── Message/             Integration events (OrderPlaced, OrderPaid, InvoiceGenerated) and scheduled tasks
│   ├── MessageHandler/      SendInvoiceEmailHandler, ExpireUnpaidOrdersHandler, RelayIntegrationEventHandler (worker)
│   ├── Messenger/           CommandBus, the outbox publisher, the failure middleware, InvoiceEventSerializer
│   ├── Ordering/            Commands and handlers: place an order, start a payment, confirm a payment, expire an order
│   ├── Payment/             CheckoutGatewayInterface, Stripe Checkout and webhook adapters
│   ├── Repository/          Repository interfaces and Doctrine implementations
│   ├── Security/            OrderVoter: who may view and pay an order
│   ├── Serializer/          OrderPaymentNormalizer: paymentUrl and payableUntil on every order
│   ├── State/               API Platform state providers and processors
│   ├── Story/               Foundry story with the full dev data set
│   └── Schedule.php         Recurring tasks (Symfony Scheduler): expire unpaid orders every 5 minutes
├── templates/
│   ├── emails/              The invoice email (HTML and text)
│   └── security/            The login page
├── tests/
│   ├── Api/                 Functional HTTP tests
│   ├── Integration/         Kernel tests without HTTP (the invoice email, expiring unpaid orders)
│   ├── Support/             Fixture helpers and test doubles (RecordingPurger, RecordingEventPublisher, FakeCheckoutGateway)
│   └── Unit/                Unit tests of entities, use cases and adapters
├── Dockerfile               Production image (FrankenPHP, worker mode)
├── docker-compose.yaml      Deployment stack (test and production): Varnish, app, worker, PostgreSQL, RabbitMQ, invoice service
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
- **Ports and adapters**: a use case depends on an interface; the implementation binds itself to
  it with `#[AsAlias(TheInterface::class)]`.
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

- **Order lifecycle:** placed → paid or expired. Shipping, cancelling a paid order and refunds
  are still to come; a payment that arrives after expiry must be refunded by hand (it's logged as
  critical).
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
- **Invoice emails:** a crash right after sending can send an email twice.
- **RabbitMQ timeouts:** the AMQP connect/read/write timeouts are unset (`0`). Requests no longer
  talk to RabbitMQ (the outbox does), but a RabbitMQ host that doesn't answer at all can still hold
  up the worker; a short `connect_timeout`/`read_timeout` on the transports would bound that.
- **Concurrency test:** the locking is designed for concurrent orders, but there is no test yet
  that actually fires parallel requests.

---

## License

MIT. See [`LICENSE`](LICENSE).
