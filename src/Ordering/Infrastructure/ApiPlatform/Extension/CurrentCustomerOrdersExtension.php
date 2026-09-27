<?php

namespace App\Ordering\Infrastructure\ApiPlatform\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Ordering\Domain\Order;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Limits the order collection to the logged-in customer's own orders. Admins see all orders.
 */
final readonly class CurrentCustomerOrdersExtension implements QueryCollectionExtensionInterface
{
    /**
     * Creates the extension.
     */
    public function __construct(private Security $security)
    {
    }

    /**
     * Adds a customer filter to order collection queries of non-admin users.
     */
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Order::class !== $resourceClass || $this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $parameter = $queryNameGenerator->generateParameterName('customer');

        $queryBuilder
            ->andWhere(sprintf('%s.customer = :%s', $rootAlias, $parameter))
            ->setParameter($parameter, $this->security->getUser());
    }
}
