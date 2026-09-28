<?php

namespace App\Security;

use App\Entity\Customer;
use App\Entity\Order;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may do what with an order: its customer may view and pay it; an administrator may view every
 * order, but pays none on someone else's behalf.
 *
 * @extends Voter<self::VIEW|self::PAY, Order>
 */
final class OrderVoter extends Voter
{
    public const string VIEW = 'ORDER_VIEW';
    public const string PAY = 'ORDER_PAY';

    /**
     * Creates the voter.
     */
    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    /**
     * Votes on the order attributes only.
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::PAY], true) && $subject instanceof Order;
    }

    /**
     * Grants access to the order's own customer, and viewing to administrators.
     *
     * @param Order $subject
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$token->getUser() instanceof Customer) {
            return false;
        }

        if (self::VIEW === $attribute && $this->accessDecisionManager->decide($token, ['ROLE_ADMIN'])) {
            return true;
        }

        return $subject->isPlacedBy($token->getUser());
    }
}
