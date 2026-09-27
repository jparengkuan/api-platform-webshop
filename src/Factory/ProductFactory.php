<?php

namespace App\Factory;

use App\Entity\Category;
use App\Entity\Embeddable\Money;
use App\Entity\Product;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\force;
use function Zenstruck\Foundry\lazy;

/**
 * Creates products that look like a real merchandise shop: typed names, prices ending in ,95
 * and a stock distribution that includes sold-out and low-stock items.
 *
 * @extends PersistentObjectFactory<Product>
 */
final class ProductFactory extends PersistentObjectFactory
{
    /** Product types with their SKU prefix, price range in cents and category. */
    private const array TYPES = [
        'Mok' => ['MOK', 800, 1800, 'Mokken & flessen'],
        'T-shirt' => ['TSH', 1500, 3500, 'Kleding'],
        'Hoodie' => ['HOO', 3500, 7500, 'Kleding'],
        'Rugzak' => ['RUG', 3000, 12000, 'Tassen'],
        'Waterfles' => ['WAT', 1200, 3000, 'Mokken & flessen'],
        'Notitieboek' => ['NOT', 600, 2000, 'Kantoor'],
        'Koptelefoon' => ['KOP', 2500, 25000, 'Audio'],
        'Sokken' => ['SOK', 500, 1500, 'Kleding'],
        'Pet' => ['PET', 1200, 2800, 'Accessoires'],
        'Sjaal' => ['SJA', 1500, 4500, 'Accessoires'],
    ];

    private const array COLLECTIONS = ['Amsterdam', 'Utrecht', 'Delft', 'Haarlem', 'Leiden', 'Zwolle', 'Maastricht', 'Groningen'];

    private const array COLORS = ['zwart', 'wit', 'grijs', 'marineblauw', 'olijfgroen', 'oker', 'bordeaux', 'zandkleur'];

    private const array FEATURES = [
        'Gemaakt van gerecyclede materialen.',
        'Ontworpen en getest in Nederland.',
        'Vaatwasser- en wasmachinebestendig.',
        'Geleverd in plasticvrije verpakking.',
        'Met twee jaar garantie.',
        'Een bestseller sinds de eerste collectie.',
    ];

    /**
     * Returns the class this factory creates.
     */
    public static function class(): string
    {
        return Product::class;
    }

    /**
     * Makes the product sold out.
     */
    public function soldOut(): self
    {
        return $this->with(['stock' => 0]);
    }

    /**
     * Takes the product out of sale.
     */
    public function inactive(): self
    {
        return $this->with(['active' => false]);
    }

    /**
     * Returns realistic default values; any of them can be overridden per call.
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $faker = self::faker();
        $type = $faker->randomKey(self::TYPES);
        [$skuPrefix, $minPrice, $maxPrice, $category] = self::TYPES[$type];
        $collection = $faker->randomElement(self::COLLECTIONS);
        $color = $faker->randomElement(self::COLORS);

        return [
            'sku' => $skuPrefix.'-'.$faker->unique()->numerify('#####'),
            'name' => sprintf('%s %s, %s', $type, $collection, $color),
            // Only looked up when the caller doesn't pass a category.
            'category' => lazy(static fn (): Category => CategoryFactory::findOrCreate(['name' => $category])),
            'description' => sprintf('%s uit de %s-collectie in %s. %s', $type, $collection, $color, $faker->randomElement(self::FEATURES)),
            // Prices like 12,95 and 34,95 instead of arbitrary cents.
            'price' => new Money(intdiv($faker->numberBetween($minPrice, $maxPrice), 100) * 100 + 95, 'EUR'),
            'stock' => self::randomStock(),
            'active' => $faker->boolean(90),
            'createdAt' => force(\DateTimeImmutable::createFromMutable($faker->dateTimeBetween('-3 years', '-1 month'))),
        ];
    }

    /**
     * Picks a stock level: about 8% sold out, 12% nearly sold out, the rest well stocked.
     */
    private static function randomStock(): int
    {
        $roll = self::faker()->numberBetween(1, 100);

        return match (true) {
            $roll <= 8 => 0,
            $roll <= 20 => self::faker()->numberBetween(1, 5),
            default => self::faker()->numberBetween(10, 250),
        };
    }
}
