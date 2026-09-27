<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    /**
     * Binds the repository to the Category entity.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Tells whether any product still belongs to the category.
     */
    public function hasProducts(Category $category): bool
    {
        return null !== $this->getEntityManager()->getRepository(Product::class)->findOneBy(['category' => $category]);
    }
}
