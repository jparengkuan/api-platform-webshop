<?php

namespace App\Catalog\Infrastructure\Foundry;

use App\Catalog\Domain\Category;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Creates product categories; the slug is derived from the name by the entity.
 *
 * @extends PersistentObjectFactory<Category>
 */
final class CategoryFactory extends PersistentObjectFactory
{
    /** The categories of the demo shop, in display order. */
    public const array NAMES = ['Accessoires', 'Audio', 'Kantoor', 'Kleding', 'Mokken & flessen', 'Tassen'];

    /**
     * Returns the class this factory creates.
     */
    public static function class(): string
    {
        return Category::class;
    }

    /**
     * Returns a unique category name by default; tests and stories usually pass one of {@see self::NAMES}.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'name' => ucfirst(self::faker()->unique()->word()),
        ];
    }
}
