# Webshop API

Een REST/JSON-LD API voor een eenvoudige webshop, gebouwd met **Symfony 8.1** en **API Platform 5**.
Klanten kunnen zich registreren, inloggen met een JWT, de catalogus bekijken en een bestelling
plaatsen. Beheerders onderhouden de productcatalogus.

De code volgt de [Symfony best practices](https://symfony.com/doc/current/best_practices.html),
de SOLID-principes en een aantal gerichte design patterns (zie [Architectuur](#architectuur)).

---

## Inhoud

- [Functionaliteit](#functionaliteit)
- [Techniek](#techniek)
- [Installatie](#installatie)
- [De API gebruiken](#de-api-gebruiken)
- [Architectuur](#architectuur)
- [Bedrijfsregels](#bedrijfsregels)
- [Foutafhandeling](#foutafhandeling)
- [Beveiliging](#beveiliging)
- [Kwaliteit en tests](#kwaliteit-en-tests)
- [Projectstructuur](#projectstructuur)
- [Werkafspraken](#werkafspraken)
- [Bekende beperkingen en roadmap](#bekende-beperkingen-en-roadmap)

---

## Functionaliteit

| Onderdeel   | Wat kan het                                                                                   |
|-------------|-----------------------------------------------------------------------------------------------|
| Klanten     | Registreren, inloggen (JWT), eigen profiel opvragen                                           |
| Catalogus   | Producten bekijken (publiek); aanmaken en wijzigen (alleen admin)                             |
| Bestellingen| Bestelling plaatsen met één of meer regels; eigen bestellingen bekijken; admin ziet alles     |
| Voorraad    | Wordt bij het plaatsen gereserveerd, veilig bij gelijktijdige bestellingen                    |

## Techniek

| Onderdeel        | Keuze                                                            |
|------------------|------------------------------------------------------------------|
| Taal             | PHP 8.4                                                          |
| Framework        | Symfony 8.1                                                      |
| API              | API Platform 5 (JSON-LD/Hydra, OpenAPI)                          |
| Database         | PostgreSQL 17 via Doctrine ORM 3 en Doctrine Migrations          |
| Authenticatie    | LexikJWTAuthenticationBundle (stateless, `Bearer`-token)         |
| Lokale omgeving  | [DDEV](https://ddev.com) (nginx-fpm, PHP 8.4, PostgreSQL 17)      |
| Tests            | PHPUnit 13, `api-platform/test`                                  |
| Kwaliteit        | PHPStan (level 5) met Symfony/Doctrine/PHPUnit-extensies, PHP-CS-Fixer (`@Symfony`) |

---

## Installatie

### Vereisten

- [Docker](https://www.docker.com/) (Docker Desktop op Windows/macOS)
- [DDEV](https://ddev.readthedocs.io/en/stable/users/install/) 1.24 of nieuwer

PHP, Composer en PostgreSQL hoef je niet lokaal te installeren; die draaien in de DDEV-containers.
Alle commando's hieronder draai je daarom via `ddev exec` of `ddev composer`.

### Stap voor stap

```bash
# 1. Containers starten
ddev start

# 2. Dependencies installeren
ddev composer install

# 3. JWT-passphrase instellen (kies zelf een lange willekeurige waarde)
#    .env.local wordt gebruikt in dev, .env.test.local in de testomgeving.
#    Beide moeten dezelfde passphrase hebben, want ze delen hetzelfde sleutelpaar.
echo 'JWT_PASSPHRASE=kies-een-lange-willekeurige-waarde' >> .env.local
echo 'JWT_PASSPHRASE=kies-een-lange-willekeurige-waarde' >> .env.test.local

# 4. Sleutelpaar voor het ondertekenen van tokens genereren (config/jwt/*.pem)
ddev exec bin/console lexik:jwt:generate-keypair

# 5. Database-schema aanmaken
ddev exec bin/console doctrine:migrations:migrate --no-interaction

# 6. Testdatabase aanmaken (Doctrine voegt automatisch het achtervoegsel _test toe)
ddev exec bin/console doctrine:database:create --env=test --if-not-exists
ddev exec bin/console doctrine:migrations:migrate --env=test --no-interaction
```

De API draait nu op **https://api-platform.ddev.site/api**.
De interactieve documentatie (Swagger UI) staat op **https://api-platform.ddev.site/api/docs**.

### Configuratie

`.env` staat in git en bevat alleen standaardwaarden. Echte waarden horen in een `.env.*.local`-bestand
(staat in `.gitignore`) of in de [Symfony secrets vault](https://symfony.com/doc/current/configuration/secrets.html).

| Variabele            | Betekenis                                                        |
|----------------------|------------------------------------------------------------------|
| `DATABASE_URL`       | Verbinding met PostgreSQL; DDEV zet deze automatisch             |
| `JWT_SECRET_KEY`     | Pad naar de private key (`config/jwt/private.pem`)               |
| `JWT_PUBLIC_KEY`     | Pad naar de public key (`config/jwt/public.pem`)                 |
| `JWT_PASSPHRASE`     | Passphrase van de private key: **nooit in `.env` committen**     |
| `CORS_ALLOW_ORIGIN`  | Regex met toegestane origins voor browser-clients                |
| `APP_SECRET`         | Symfony-secret; in productie via de secrets vault                |

### Een beheerder aanmaken

Er is (nog) geen endpoint om rollen toe te kennen. Registreer een gewone klant en geef die
daarna de rol `ROLE_ADMIN`:

```bash
ddev exec bin/console dbal:run-sql \
  "UPDATE customer SET roles = '[\"ROLE_ADMIN\"]' WHERE email = 'admin@example.com'"
```

Log daarna opnieuw in; de rollen staan in het token.

---

## De API gebruiken

Alle endpoints vallen onder `/api`. Standaard spreekt de API **JSON-LD** (`application/ld+json`).
Gewone JSON (`application/json`) werkt ook. Relaties worden aangeduid met een **IRI**, bijvoorbeeld
`/api/products/0199…`.

### Overzicht van endpoints

| Methode | Pad                     | Toegang                 | Omschrijving                                   |
|---------|-------------------------|-------------------------|------------------------------------------------|
| POST    | `/api/register`         | publiek                 | Klantaccount aanmaken                          |
| POST    | `/api/login`            | publiek                 | Inloggen; geeft `{"token": "<JWT>"}` terug     |
| GET     | `/api/me`               | ingelogd                | Profiel van de ingelogde klant                 |
| GET     | `/api/customers/{id}`   | eigenaar of admin       | Klant opvragen                                 |
| GET     | `/api/products`         | publiek                 | Catalogus (gepagineerd, gesorteerd op naam)    |
| GET     | `/api/products/{id}`    | publiek                 | Eén product                                    |
| POST    | `/api/products`         | admin                   | Product aanmaken                               |
| PATCH   | `/api/products/{id}`    | admin                   | Product wijzigen (`application/merge-patch+json`) |
| POST    | `/api/orders`           | ingelogd                | Bestelling plaatsen                            |
| GET     | `/api/orders`           | ingelogd                | Eigen bestellingen (admin: alle), nieuwste eerst |
| GET     | `/api/orders/{id}`      | eigenaar of admin       | Eén bestelling                                 |

### Voorbeeld: van registratie tot bestelling

```bash
API=https://api-platform.ddev.site/api

# Registreren
curl -X POST $API/register -H 'Content-Type: application/json' -d '{
  "email": "jan@example.com",
  "firstName": "Jan",
  "lastName": "Jansen",
  "plainPassword": "S3cure-webshop-pass!"
}'

# Inloggen en token bewaren
TOKEN=$(curl -s -X POST $API/login -H 'Content-Type: application/json' \
  -d '{"email": "jan@example.com", "password": "S3cure-webshop-pass!"}' | jq -r .token)

# Catalogus bekijken
curl $API/products

# Bestelling plaatsen
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

Antwoord (`201 Created`, ingekort):

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
      "productName": "Mok",
      "unitPrice": { "amount": 1250, "currency": "EUR" },
      "quantity": 2,
      "lineTotal": { "amount": 2500, "currency": "EUR" }
    }
  ],
  "total": { "amount": 4999, "currency": "EUR" },
  "placedAt": "2026-09-27T12:00:00+00:00"
}
```

### Product aanmaken (admin)

```bash
curl -X POST $API/products \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "sku": "MUG-01",
    "name": "Mok",
    "description": "Stevige stenen mok, 350 ml",
    "price": { "amount": 1250, "currency": "EUR" },
    "stock": 100
  }'
```

> **Bedragen staan altijd in centen** (de kleinste munteenheid): `1250` is € 12,50.
> Zo ontstaan er nooit afrondingsfouten door floating-point-berekeningen.

---

## Architectuur

### Lagen

```
HTTP (API Platform)          Applicatie                   Domein                     Infrastructuur
───────────────────          ──────────                   ──────                     ──────────────
PlaceOrderInput (DTO)  ─┐
                        ├─▶  PlaceOrderProcessor  ─▶  PlaceOrderHandler  ─▶  Order::place()  ◀─▶  ProductRepository
Validator (#[Assert])  ─┘       (adapter)               (use case,            Product::reserveStock()   OrderRepository
                                                       transactie)           Money                     (Doctrine ORM)
```

1. **API Platform** deserialiseert het request naar `PlaceOrderInput` en valideert dat met de
   Validator-constraints op de DTO.
2. **`PlaceOrderProcessor`** (adapter) vertaalt de DTO naar een `PlaceOrder`-command, met de
   ingelogde klant erbij.
3. **`PlaceOrderHandler`** (use case) opent een transactie, laadt de producten met een write-lock
   en laat het domein het werk doen.
4. **`Order::place()`** controleert de bedrijfsregels, reserveert voorraad en berekent het totaal.
5. Bij succes volgt een commit en geeft API Platform de order terug als `201`. Bij een fout volgt een
   rollback en wordt er niets opgeslagen.

### SOLID in de praktijk

| Principe                  | Waar                                                                                       |
|---------------------------|--------------------------------------------------------------------------------------------|
| **S**ingle Responsibility | Processor = HTTP-vertaling, handler = transactie, entities = bedrijfsregels, repository = opslag |
| **O**pen/Closed           | Nieuwe bedrijfsregel = nieuwe subklasse van `OrderingException`; de 422-mapping hoeft niet te veranderen |
| **L**iskov Substitution   | Elke implementatie van de repository-interfaces moet hetzelfde contract nakomen (bijv. een in-memory variant voor tests) |
| **I**nterface Segregation | Kleine repository-interfaces met alleen wat de use case nodig heeft (`findForUpdate`, `add`) |
| **D**ependency Inversion  | `PlaceOrderHandler` hangt af van interfaces en `Psr\Clock\ClockInterface`, niet van Doctrine-klassen |

### Design patterns

| Pattern                 | Klasse(n)                                         | Waarom                                                     |
|-------------------------|---------------------------------------------------|------------------------------------------------------------|
| Repository              | `ProductRepository`, `OrderRepository`            | Opslaglogica gescheiden van domein en use case             |
| Command + Handler       | `PlaceOrder`, `PlaceOrderHandler`                 | Use case los van HTTP; ook bruikbaar vanuit CLI of Messenger |
| Adapter                 | `PlaceOrderProcessor`                             | Koppelt API Platform aan de use case                       |
| Data Transfer Object    | `PlaceOrderInput`, `OrderLineInput`               | API-contract los van het domeinmodel                       |
| Aggregate Root          | `Order` (met `OrderLine`)                         | Regels kunnen alleen via de order ontstaan                 |
| Factory Method          | `Order::place()`, `Money::zero()`, named constructors op exceptions | Altijd een geldig object; intentie in de naam |
| Value Object            | `Money` (Doctrine embeddable, `readonly`)         | Onveranderlijk, zelfvaliderend, geen float-afronding       |
| Snapshot                | `OrderLine` kopieert SKU, naam en prijs           | Latere catalogus-wijzigingen veranderen oude orders niet   |
| Query Extension         | `CurrentCustomerOrdersExtension`                  | Filtert `GET /api/orders` automatisch op de ingelogde klant |

### Gelijktijdigheid (concurrency)

Twee klanten die tegelijk het laatste exemplaar bestellen mogen niet allebei slagen. Daarom:

- `ProductRepository::findForUpdate()` laadt producten met `SELECT … FOR UPDATE` (pessimistic
  write lock), binnen de transactie van de handler.
- Producten worden altijd **op id gesorteerd** gelockt, zodat twee orders met dezelfde producten
  elkaar niet in een deadlock houden.
- `Query::HINT_REFRESH` zorgt dat de voorraad ná het verkrijgen van de lock uit de database wordt
  gelezen, ook als het product al in de identity map zat.
- Bij een exception doet `EntityManager::wrapInTransaction()` een rollback en sluit de
  EntityManager. Voorraad die al in het geheugen was afgeboekt, kan daardoor nooit alsnog
  worden opgeslagen.

---

## Bedrijfsregels

| Regel                                                        | Afgedwongen in                      | Resultaat |
|--------------------------------------------------------------|-------------------------------------|-----------|
| Een bestelling heeft 1 tot 50 regels                         | `PlaceOrderInput` (Validator) en `Order::place()` | 422 |
| Aantal per regel is 1 tot 100                                | `OrderLineInput` (Validator)        | 422       |
| Dubbele regels voor hetzelfde product worden samengevoegd    | `PlaceOrder`                        | —         |
| Product moet bestaan                                         | API Platform (IRI) en `PlaceOrderHandler` | 400 / 422 |
| Product moet actief zijn                                     | `Product::reserveStock()`           | 422       |
| Voldoende voorraad, anders gaat de hele bestelling niet door | `Product::reserveStock()` + transactie | 422    |
| Alle producten in één valuta                                 | `Order::place()`                    | 422       |
| Prijs is nooit negatief                                      | `Money`                             | 422       |
| SKU is uniek en bevat alleen hoofdletters, cijfers en `-`    | `Product` (Validator, `UniqueEntity`) | 422     |

---

## Foutafhandeling

Fouten komen terug als [RFC 7807 Problem Details](https://www.rfc-editor.org/rfc/rfc7807)
(in JSON-LD als Hydra `Error`).

| Status | Wanneer                                                                  |
|--------|--------------------------------------------------------------------------|
| 400    | Ongeldige JSON of een onbekende IRI                                      |
| 401    | Geen of een ongeldig token                                               |
| 403    | Ingelogd, maar geen toegang (bijv. andermans bestelling, geen admin)     |
| 404    | Resource bestaat niet                                                    |
| 422    | Validatiefout (`violations`) of overtreden bedrijfsregel (`detail`)       |

Voorbeeld van een overtreden bedrijfsregel:

```json
{
  "@type": "Error",
  "title": "An error occurred",
  "detail": "Insufficient stock for \"SHIRT-01\": 2 requested, 1 available.",
  "status": 422
}
```

**Hoe werkt dit technisch?**

- Alle bedrijfsfouten erven van `App\Exception\OrderingException`.
- De order-operatie mapt die basisklasse via `exceptionToStatus` naar 422.
- Met `#[WithLogLevel(LogLevel::INFO)]` worden ze gelogd als verwachte uitkomst, niet als
  `critical`, zodat ze in productie geen alarm veroorzaken.

---

## Beveiliging

- **Stateless JWT:** `POST /api/login` (firewall `login`, `json_login`) geeft een token. Alle andere
  `/api`-routes lezen het token uit de `Authorization: Bearer …`-header (firewall `api`).
- **Autorisatie per operatie:** via `security:`-expressies in de `#[ApiResource]`-attributen, bijvoorbeeld
  `is_granted('ROLE_ADMIN') or (is_granted('ROLE_USER') and object.isPlacedBy(user))`.
- **Collecties:** `CurrentCustomerOrdersExtension` beperkt de orderlijst tot de eigen orders. Dat
  gebeurt in de query zelf, zodat er geen andermans data kan uitlekken via paginering of filters.
- **Wachtwoorden:** gehasht met de `auto`-hasher en automatisch geüpgraded bij inloggen
  (`PasswordUpgraderInterface`). Bij registratie wordt het wachtwoord gecontroleerd tegen bekende
  datalekken (`NotCompromisedPassword`). Het plain-text wachtwoord wordt nooit opgeslagen of teruggestuurd.
- **Identifiers:** UUID v7, dus niet te raden of op te sommen.
- **Secrets:** private keys (`config/jwt/*.pem`) en `.env.*.local` staan in `.gitignore`.

---

## Kwaliteit en tests

Alles draait via Composer-scripts:

```bash
ddev composer qa        # alles hieronder achter elkaar
ddev composer cs        # code style controleren (PHP-CS-Fixer, dry-run met diff)
ddev composer cs:fix    # code style automatisch herstellen
ddev composer phpstan   # statische analyse (level 5)
ddev composer test      # PHPUnit
```

### Tests

| Suite                          | Type                    | Wat wordt getest                                           |
|--------------------------------|-------------------------|------------------------------------------------------------|
| `tests/Unit/Entity/MoneyTest`  | Unit                    | Rekenen, gelijkheid, validatie van `Money`                 |
| `tests/Unit/Entity/OrderTest`  | Unit                    | Bedrijfsregels van `Order::place()`, snapshot van prijzen  |
| `tests/Api/CustomerAuthTest`   | Functioneel (HTTP)      | Registratie, login, toegang tot profielen                  |
| `tests/Api/ProductTest`        | Functioneel (HTTP)      | Publieke catalogus, admin-beheer, validatie, JSON-schema   |
| `tests/Api/PlaceOrderTest`     | Functioneel (HTTP)      | Bestellen, voorraad, rollback, validatie, autorisatie      |

De functionele tests draaien tegen een echte PostgreSQL-testdatabase (`db_test`) en leegmaken de
tabellen vóór elke test. Gedeelde fixtures en login-helpers staan in `tests/Api/WebshopTestTrait.php`.

### Statische analyse

- `phpstan.dist.neon` laadt de Symfony-container (`var/cache/dev/…Container.xml`) en de echte
  Doctrine-mapping (`tests/object-manager.php`). Zo controleert PHPStan ook service-ID's,
  repository-types en DQL.
- Wil je lokaal andere instellingen? Maak dan een `phpstan.neon` aan (die staat in `.gitignore`).

---

## Projectstructuur

```
├── config/                  Symfony-configuratie (packages, routes, services)
├── migrations/              Doctrine-migraties (nooit met de hand schema's wijzigen)
├── src/
│   ├── Controller/          LoginController (route voor de json_login-firewall)
│   ├── Doctrine/            API Platform query-extensies
│   ├── Dto/                 Input-DTO's van de API
│   ├── Entity/              Doctrine-entities en API-resources
│   │   └── Embeddable/      Value objects (Money)
│   ├── Enum/                Backed enums (OrderStatus)
│   ├── Exception/           Domein-exceptions
│   ├── Ordering/            Use case: PlaceOrder-command en handler
│   ├── Repository/          Repository-interfaces en Doctrine-implementaties
│   └── State/               API Platform state providers en processors
├── tests/
│   ├── Api/                 Functionele HTTP-tests
│   └── Unit/                Unit-tests van het domein
├── .php-cs-fixer.dist.php   Code style (@Symfony)
├── phpstan.dist.neon        Statische analyse (level 5)
└── phpunit.dist.xml         PHPUnit-configuratie
```

---

## Werkafspraken

Zie ook [`AGENTS.md`](AGENTS.md).

- **Nieuwe packages** installeer je met `composer require`, zodat de Flex-recipe de configuratie
  aanmaakt. Pas `config/bundles.php` niet met de hand aan.
- **Metadata via PHP-attributen**, dus `#[ApiResource]`, `#[Assert\…]`, `#[ORM\…]`, `#[Autowire]`.
  Geen YAML- of XML-routing.
- **Autowiring**: dependencies via de constructor met type-hints. Een YAML-servicedefinitie is het
  laatste redmiddel.
- **Schemawijzigingen** altijd via een migratie:
  ```bash
  ddev exec bin/console make:migration
  ddev exec bin/console doctrine:migrations:migrate
  ```
- **Docblocks**: elke functie heeft een samenvattende zin. `@param`, `@return` en `@throws` alleen
  als ze iets toevoegen aan de type-hints (de `@Symfony`-ruleset verwijdert overbodige tags).
- **Definition of done**: `ddev composer qa` is groen en de feature heeft een test die hem aanroept
  zoals een client dat doet.
- **Bij problemen** eerst `var/log/dev.log` en de profiler (`/_profiler`) bekijken.

---

## Bekende beperkingen en roadmap

- **Idempotency:** een retry van `POST /api/orders` na een time-out kan een dubbele bestelling
  opleveren. Een idempotency-key header lost dit op.
- **Order-lifecycle:** `OrderStatus` kent nu alleen `placed`. Betaald, verzonden en geannuleerd
  (met teruggeboekte voorraad) volgen nog, bijvoorbeeld met de Symfony Workflow-component.
- **Beheer:** er is geen endpoint of console-command om beheerders aan te maken.
- **Catalogus:** inactieve producten staan nog in de publieke lijst. Ze zijn wel niet te bestellen.
- **Notificaties:** een orderbevestiging per e-mail, asynchroon via Symfony Messenger en Mailer.
- **Concurrency-test:** de locking is ontworpen voor gelijktijdige bestellingen, maar er is nog
  geen test die echt parallelle requests uitvoert.

---

## Licentie

MIT. Zie [`LICENSE`](LICENSE).
