<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Ordering\Application\Event\OrderPlaced;
use App\Ordering\Domain\Order;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PlaceOrderTest extends ApiTestCase
{
    use WebshopTestTrait;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    /**
     * Creates an API client and starts every test with an empty database.
     */
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->resetDatabase();
    }

    /**
     * A customer places an order: prices are totalled, stock is reserved and the order can be read back.
     */
    public function testCustomerPlacesOrder(): void
    {
        $customer = $this->createCustomer('jan@example.com');
        $mug = $this->createProduct('MUG-01', 1250, 10);
        $shirt = $this->createProduct('SHIRT-01', 2499, 5);
        $token = $this->login('jan@example.com');

        $response = $this->client->request('POST', '/api/orders', ['auth_bearer' => $token, 'json' => ['lines' => [
            ['product' => $this->iri($mug), 'quantity' => 2],
            ['product' => $this->iri($shirt), 'quantity' => 1],
        ]]]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains([
            '@type' => 'Order',
            'customer' => '/api/customers/'.$customer->getId()->toRfc4122(),
            'status' => 'placed',
            'total' => ['amount' => 4999, 'currency' => 'EUR'],
            'lines' => [
                ['product' => $this->iri($mug), 'sku' => 'MUG-01', 'quantity' => 2, 'unitPrice' => ['amount' => 1250], 'lineTotal' => ['amount' => 2500]],
                ['product' => $this->iri($shirt), 'sku' => 'SHIRT-01', 'quantity' => 1, 'lineTotal' => ['amount' => 2499]],
            ],
        ]);
        self::assertMatchesResourceItemJsonSchema(Order::class);
        self::assertSame(8, $this->reloadProduct($mug)->getStock());
        self::assertSame(4, $this->reloadProduct($shirt)->getStock());

        $this->client->request('GET', $response->toArray()['@id'], ['auth_bearer' => $token]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Frontends that send and accept plain JSON (not JSON-LD) can place an order as well.
     */
    public function testOrderCanBePlacedWithPlainJson(): void
    {
        $this->createCustomer('jan@example.com');
        $mug = $this->createProduct('MUG-01', 1250, 10);

        $response = $this->client->request('POST', '/api/orders', [
            'auth_bearer' => $this->login('jan@example.com'),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => json_encode(['lines' => [['product' => $this->iri($mug), 'quantity' => 2]]], \JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertStringStartsWith('application/json', $response->getHeaders()['content-type'][0]);
        $order = $response->toArray();
        self::assertSame(2500, $order['total']['amount']);
        self::assertArrayNotHasKey('@id', $order);
    }

    /**
     * Lines for the same product are merged into one line.
     */
    public function testDuplicateLinesAreMerged(): void
    {
        $this->createCustomer('jan@example.com');
        $mug = $this->createProduct('MUG-01', 1000, 10);

        $this->client->request('POST', '/api/orders', ['auth_bearer' => $this->login('jan@example.com'), 'json' => ['lines' => [
            ['product' => $this->iri($mug), 'quantity' => 2],
            ['product' => $this->iri($mug), 'quantity' => 3],
        ]]]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['lines' => [['quantity' => 5]], 'total' => ['amount' => 5000]]);
        self::assertSame(5, $this->reloadProduct($mug)->getStock());
    }

    /**
     * A placed order is announced to RabbitMQ with plain values that survive the JSON round trip.
     */
    public function testPlacedOrderIsPublished(): void
    {
        $customer = $this->createCustomer('jan@example.com');
        $mug = $this->createProduct('MUG-01', 1250, 10);
        $token = $this->login('jan@example.com');

        $response = $this->client->request('POST', '/api/orders', ['auth_bearer' => $token, 'json' => ['lines' => [
            ['product' => $this->iri($mug), 'quantity' => 2],
        ]]]);

        self::assertResponseStatusCodeSame(201);
        $sent = $this->sentEvents();
        self::assertCount(1, $sent);

        $event = $sent[0]->getMessage();
        self::assertInstanceOf(OrderPlaced::class, $event);
        self::assertSame($response->toArray()['@id'], '/api/orders/'.$event->orderId);
        self::assertSame($customer->getId()->toRfc4122(), $event->customerId);
        self::assertSame(2500, $event->totalAmount);
        self::assertSame('EUR', $event->currency);
        self::assertSame([[
            'productId' => $mug->getId()->toRfc4122(),
            'sku' => 'MUG-01',
            'name' => $mug->getName(),
            'quantity' => 2,
            'unitPrice' => 1250,
        ]], $event->lines);
    }

    /**
     * When one line lacks stock, nothing is ordered and no stock is taken for the other lines.
     */
    public function testInsufficientStockRejectsWholeOrder(): void
    {
        $this->createCustomer('jan@example.com');
        $mug = $this->createProduct('MUG-01', 1000, 10);
        $shirt = $this->createProduct('SHIRT-01', 2000, 1);

        $this->client->request('POST', '/api/orders', ['auth_bearer' => $this->login('jan@example.com'), 'json' => ['lines' => [
            ['product' => $this->iri($mug), 'quantity' => 2],
            ['product' => $this->iri($shirt), 'quantity' => 2],
        ]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['detail' => 'Insufficient stock for "SHIRT-01": 2 requested, 1 available.']);
        self::assertSame(10, $this->reloadProduct($mug)->getStock(), 'Stock reserved for earlier lines must be rolled back.');
        self::assertSame(0, $this->entityManager()->getRepository(Order::class)->count());
        self::assertSame([], $this->sentEvents(), 'A rejected order must not be announced.');
    }

    /**
     * Products taken out of sale cannot be ordered.
     */
    public function testInactiveProductCannotBeOrdered(): void
    {
        $this->createCustomer('jan@example.com');
        $mug = $this->createProduct('MUG-01', 1000, 10, active: false);

        $this->client->request('POST', '/api/orders', ['auth_bearer' => $this->login('jan@example.com'), 'json' => ['lines' => [
            ['product' => $this->iri($mug), 'quantity' => 1],
        ]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['detail' => 'Product "MUG-01" is not available for sale.']);
    }

    /**
     * An order cannot combine products priced in different currencies.
     */
    public function testMixedCurrenciesAreRejected(): void
    {
        $this->createCustomer('jan@example.com');
        $eur = $this->createProduct('MUG-EUR', 1000, 10);
        $usd = $this->createProduct('MUG-USD', 1000, 10, 'USD');

        $this->client->request('POST', '/api/orders', ['auth_bearer' => $this->login('jan@example.com'), 'json' => ['lines' => [
            ['product' => $this->iri($eur), 'quantity' => 1],
            ['product' => $this->iri($usd), 'quantity' => 1],
        ]]]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Empty orders, invalid quantities and unknown products are rejected.
     */
    public function testInvalidInputIsRejected(): void
    {
        $this->createCustomer('jan@example.com');
        $mug = $this->createProduct('MUG-01', 1000, 10);
        $token = $this->login('jan@example.com');

        $this->client->request('POST', '/api/orders', ['auth_bearer' => $token, 'json' => ['lines' => []]]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/api/orders', ['auth_bearer' => $token, 'json' => ['lines' => [
            ['product' => $this->iri($mug), 'quantity' => 0],
        ]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'lines[0].quantity']]]);

        $this->client->request('POST', '/api/orders', ['auth_bearer' => $token, 'json' => ['lines' => [
            ['product' => '/api/products/0199aaaa-0000-7000-8000-000000000000', 'quantity' => 1],
        ]]]);
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Anonymous users cannot place orders.
     */
    public function testPlacingAnOrderRequiresAuthentication(): void
    {
        $mug = $this->createProduct('MUG-01', 1000, 10);

        $this->client->request('POST', '/api/orders', ['json' => ['lines' => [['product' => $this->iri($mug), 'quantity' => 1]]]]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Customers only list and read their own orders; admins see all of them.
     */
    public function testCustomersOnlySeeTheirOwnOrders(): void
    {
        $this->createCustomer('jan@example.com');
        $this->createCustomer('piet@example.com');
        $this->createCustomer('admin@example.com', ['ROLE_ADMIN']);
        $mug = $this->createProduct('MUG-01', 1000, 10);
        $jan = $this->login('jan@example.com');
        $piet = $this->login('piet@example.com');

        $order = ['json' => ['lines' => [['product' => $this->iri($mug), 'quantity' => 1]]]];
        $jansOrder = $this->client->request('POST', '/api/orders', ['auth_bearer' => $jan] + $order)->toArray()['@id'];
        $this->client->request('POST', '/api/orders', ['auth_bearer' => $piet] + $order);

        $this->client->request('GET', '/api/orders', ['auth_bearer' => $jan]);
        self::assertJsonContains(['totalItems' => 1, 'member' => [['@id' => $jansOrder]]]);

        $this->client->request('GET', $jansOrder, ['auth_bearer' => $piet]);
        self::assertResponseStatusCodeSame(403);

        $admin = $this->login('admin@example.com');
        $this->client->request('GET', '/api/orders', ['auth_bearer' => $admin]);
        self::assertJsonContains(['totalItems' => 2]);
        $this->client->request('GET', $jansOrder, ['auth_bearer' => $admin]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Returns the events published during the last request (decoded again from their JSON form).
     *
     * @return list<Envelope>
     */
    private function sentEvents(): array
    {
        $transport = self::getContainer()->get('messenger.transport.events');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values([...$transport->getSent()]);
    }
}
