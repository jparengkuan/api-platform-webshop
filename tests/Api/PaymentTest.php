<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Customer;
use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Factory\OrderFactory;
use App\Message\OrderPaid;
use App\Ordering\ExpireOrderHandler;
use App\Tests\Support\FakeCheckoutGateway;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Paying an order: starting a Stripe Checkout through the API, and Stripe's webhook confirming it.
 */
final class PaymentTest extends ApiTestCase
{
    use WebshopTestTrait;

    /** Must match STRIPE_WEBHOOK_SECRET in .env.test. */
    private const string WEBHOOK_SECRET = 'whsec_test_secret';

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    /**
     * Creates an API client and starts every test with an empty database.
     */
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->resetDatabase();
        FakeCheckoutGateway::reset();
    }

    /**
     * The customer gets a checkout URL to pay the order at Stripe.
     */
    public function testCustomerStartsPayment(): void
    {
        $order = $this->unpaidOrder($this->createCustomer('jan@example.com'));

        $this->client->request('POST', $this->paymentUrl($order), ['auth_bearer' => $this->login('jan@example.com'), 'json' => []]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['checkoutUrl' => 'https://checkout.stripe.test/c/pay/cs_test_'.$order->getId()->toBase58()]);
        self::assertSame([$order->getId()->toRfc4122()], FakeCheckoutGateway::orders());
    }

    /**
     * Nobody can start a payment for someone else's order.
     */
    public function testOnlyTheCustomerCanPay(): void
    {
        $order = $this->unpaidOrder($this->createCustomer('jan@example.com'));
        $this->createCustomer('piet@example.com');

        $this->client->request('POST', $this->paymentUrl($order), ['auth_bearer' => $this->login('piet@example.com'), 'json' => []]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], FakeCheckoutGateway::orders());
    }

    /**
     * The order tells the client whether it can be paid: a paymentUrl only while that's possible.
     */
    public function testOrderShowsWhetherItCanBePaid(): void
    {
        $customer = $this->createCustomer('jan@example.com');
        $unpaid = $this->unpaidOrder($customer);
        $late = $this->unpaidOrder($customer, new \DateTimeImmutable('-'.(Order::PAYMENT_WINDOW_MINUTES + 1).' minutes'), 'MUG-02');
        $paid = OrderFactory::createOne(['customer' => $customer]);
        $token = $this->login('jan@example.com');

        $this->client->request('GET', '/api/orders/'.$unpaid->getId()->toRfc4122(), ['auth_bearer' => $token]);
        self::assertJsonContains([
            'paymentUrl' => $this->paymentUrl($unpaid),
            'payableUntil' => $unpaid->payableUntil()->format(\DateTimeInterface::RFC3339),
        ]);

        foreach ([$late, $paid] as $order) {
            $this->client->request('GET', '/api/orders/'.$order->getId()->toRfc4122(), ['auth_bearer' => $token]);
            self::assertJsonContains(['paymentUrl' => null, 'payableUntil' => null]);
        }
    }

    /**
     * A paid order can't be paid again.
     */
    public function testPaidOrderCannotBePaidAgain(): void
    {
        $order = OrderFactory::createOne(['customer' => $this->createCustomer('jan@example.com'), 'placedAt' => new \DateTimeImmutable()]);

        $this->client->request('POST', $this->paymentUrl($order), ['auth_bearer' => $this->login('jan@example.com'), 'json' => []]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['detail' => 'This order can\'t be paid: it is paid.']);
    }

    /**
     * After the payment window a payment can't be started anymore; the order will expire.
     */
    public function testPaymentWindowCloses(): void
    {
        $order = $this->unpaidOrder($this->createCustomer('jan@example.com'), new \DateTimeImmutable('-'.(Order::PAYMENT_WINDOW_MINUTES + 1).' minutes'));

        $this->client->request('POST', $this->paymentUrl($order), ['auth_bearer' => $this->login('jan@example.com'), 'json' => []]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['detail' => 'The time to pay this order has passed.']);
    }

    /**
     * Stripe's webhook marks the order paid and OrderPaid is published for the invoice service.
     */
    public function testWebhookMarksOrderPaid(): void
    {
        $order = $this->unpaidOrder($this->createCustomer('jan@example.com'));

        $this->sendWebhook($this->checkoutEvent($order, 'paid'));

        self::assertResponseStatusCodeSame(204);
        $order = $this->reloadOrder($order);
        self::assertSame(OrderStatus::Paid, $order->getStatus());
        self::assertSame('pi_test_123', $order->getPaymentReference());
        self::assertNotNull($order->getPaidAt());

        $events = $this->sentEvents();
        self::assertCount(1, $events);
        $event = $events[0]->getMessage();
        self::assertInstanceOf(OrderPaid::class, $event);
        self::assertSame($order->getId()->toRfc4122(), $event->orderId);
        self::assertSame($order->getTotal()->amount, $event->totalAmount);
    }

    /**
     * Stripe may deliver a webhook more than once; the order is paid and announced only once.
     */
    public function testRepeatedWebhookIsIgnored(): void
    {
        $order = $this->unpaidOrder($this->createCustomer('jan@example.com'));
        $this->sendWebhook($this->checkoutEvent($order, 'paid'));

        $this->sendWebhook($this->checkoutEvent($order, 'paid'));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->sentEvents());
        self::assertSame(OrderStatus::Paid, $this->reloadOrder($order)->getStatus());
    }

    /**
     * A call that isn't signed with the webhook secret is rejected and changes nothing.
     */
    public function testWebhookWithInvalidSignatureIsRejected(): void
    {
        $order = $this->unpaidOrder($this->createCustomer('jan@example.com'));

        $this->sendWebhook($this->checkoutEvent($order, 'paid'), 'whsec_someone_else');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(OrderStatus::Placed, $this->reloadOrder($order)->getStatus());
    }

    /**
     * A completed checkout that isn't paid yet doesn't mark the order paid.
     */
    public function testUnpaidCheckoutIsIgnored(): void
    {
        $order = $this->unpaidOrder($this->createCustomer('jan@example.com'));

        $this->sendWebhook($this->checkoutEvent($order, 'unpaid'));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(OrderStatus::Placed, $this->reloadOrder($order)->getStatus());
    }

    /**
     * A payment for an order that has expired in the meantime isn't applied (it must be refunded).
     */
    public function testPaymentForExpiredOrderIsNotApplied(): void
    {
        $order = $this->unpaidOrder($this->createCustomer('jan@example.com'));
        self::assertTrue(self::getContainer()->get(ExpireOrderHandler::class)($order->getId()));

        $this->sendWebhook($this->checkoutEvent($order, 'paid'));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(OrderStatus::Expired, $this->reloadOrder($order)->getStatus());
        self::assertSame([], $this->sentEvents());
    }

    /**
     * Creates an order of two mugs that awaits payment.
     */
    private function unpaidOrder(Customer $customer, \DateTimeImmutable $placedAt = new \DateTimeImmutable(), string $sku = 'MUG-01'): Order
    {
        return OrderFactory::createOne([
            'customer' => $customer,
            'items' => [[$this->createProduct($sku, 1250, 10), 2]],
            'placedAt' => $placedAt,
            'paid' => false,
        ]);
    }

    /**
     * Returns the payment endpoint of the order.
     */
    private function paymentUrl(Order $order): string
    {
        return '/api/orders/'.$order->getId()->toRfc4122().'/payment';
    }

    /**
     * Returns a Stripe checkout.session.completed event for the order, as Stripe sends it.
     */
    private function checkoutEvent(Order $order, string $paymentStatus): string
    {
        return json_encode([
            'id' => 'evt_test_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_1',
                'object' => 'checkout.session',
                'client_reference_id' => $order->getId()->toRfc4122(),
                'payment_status' => $paymentStatus,
                'payment_intent' => 'pi_test_123',
            ]],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * Posts the payload to the webhook, signed like Stripe signs it (HMAC-SHA256 of "timestamp.payload").
     */
    private function sendWebhook(string $payload, string $secret = self::WEBHOOK_SECRET): void
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        $this->client->request('POST', '/stripe/webhook', [
            'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => 't='.$timestamp.',v1='.$signature],
            'body' => $payload,
        ]);
    }

    /**
     * Reads the order straight from the database.
     */
    private function reloadOrder(Order $order): Order
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        return $entityManager->find(Order::class, $order->getId()) ?? throw new \LogicException('The order is gone.');
    }

    /**
     * Returns the events published during the last request.
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
