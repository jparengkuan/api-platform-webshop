<?php

namespace App\Serializer;

use App\Entity\Order;
use App\Exception\OrderNotPayableException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Adds what a client can do next with an order: while it can be paid, "paymentUrl" (the endpoint that
 * starts the payment) and "payableUntil"; otherwise both are null. Clients show a "Pay" button only
 * when there is a paymentUrl, instead of repeating the payment rules themselves.
 *
 * A normalizer, not entity getters, because "can be paid now" depends on the clock.
 */
final class OrderPaymentNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /** Marks the context so the next normalizer, not this one, normalizes the order itself. */
    private const string ALREADY_CALLED = 'order_payment_normalizer_already_called';

    /**
     * Creates the normalizer.
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Normalizes the order as usual and adds the payment fields.
     *
     * @param Order                $data
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|string|int|float|bool|\ArrayObject<string, mixed>|null
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $normalized = $this->normalizer->normalize($data, $format, [self::ALREADY_CALLED => true] + $context);
        if (!\is_array($normalized)) {
            return $normalized;
        }

        $payable = true;
        try {
            $data->assertPayable($this->clock->now());
        } catch (OrderNotPayableException) {
            $payable = false;
        }

        $normalized['payableUntil'] = $payable ? $data->payableUntil()->format(\DateTimeInterface::RFC3339) : null;
        $normalized['paymentUrl'] = $payable ? $this->urlGenerator->generate('order_payment', ['id' => $data->getId()->toRfc4122()]) : null;

        return $normalized;
    }

    /**
     * Handles orders, once per normalization.
     *
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Order && !isset($context[self::ALREADY_CALLED]);
    }

    /**
     * Orders only; not cacheable, because the answer depends on the context.
     */
    public function getSupportedTypes(?string $format): array
    {
        return [Order::class => false];
    }
}
