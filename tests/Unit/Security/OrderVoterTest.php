<?php

namespace App\Tests\Unit\Security;

use App\Entity\Customer;
use App\Entity\Embeddable\Money;
use App\Entity\Order;
use App\Entity\Product;
use App\Security\OrderVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class OrderVoterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function cases(): iterable
    {
        yield 'owner views' => ['owner', OrderVoter::VIEW, VoterInterface::ACCESS_GRANTED];
        yield 'owner pays' => ['owner', OrderVoter::PAY, VoterInterface::ACCESS_GRANTED];
        yield 'other customer views' => ['other', OrderVoter::VIEW, VoterInterface::ACCESS_DENIED];
        yield 'other customer pays' => ['other', OrderVoter::PAY, VoterInterface::ACCESS_DENIED];
        yield 'admin views' => ['admin', OrderVoter::VIEW, VoterInterface::ACCESS_GRANTED];
        yield 'admin pays someone else\'s order' => ['admin', OrderVoter::PAY, VoterInterface::ACCESS_DENIED];
        yield 'anonymous views' => ['anonymous', OrderVoter::VIEW, VoterInterface::ACCESS_DENIED];
    }

    /**
     * Only the customer may view and pay an order; an administrator may only view it.
     */
    #[DataProvider('cases')]
    public function testAccess(string $who, string $attribute, int $expected): void
    {
        $owner = new Customer();
        $order = Order::place($owner, [[new Product()->setSku('MUG-01')->setName('Mug')->setPrice(new Money(1250, 'EUR'))->setStock(1), 1]], new \DateTimeImmutable());

        $decisions = $this->createStub(AccessDecisionManagerInterface::class);
        $decisions->method('decide')->willReturn('admin' === $who);

        $token = match ($who) {
            'owner' => new UsernamePasswordToken($owner, 'api', ['ROLE_USER']),
            'other' => new UsernamePasswordToken(new Customer(), 'api', ['ROLE_USER']),
            'admin' => new UsernamePasswordToken(new Customer(), 'api', ['ROLE_ADMIN']),
            default => new NullToken(),
        };

        self::assertSame($expected, new OrderVoter($decisions)->vote($token, $order, [$attribute]));
    }
}
