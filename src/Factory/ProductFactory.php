<?php

namespace App\Factory;

use App\Entity\Embeddable\Money;
use App\Entity\Product;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\force;

/**
 * Creates products that look like a real merchandise shop: typed names, prices ending in ,95
 * and a stock distribution that includes sold-out and low-stock items.
 *
 * @extends PersistentObjectFactory<Product>
 */
final class ProductFactory extends PersistentObjectFactory
{
    /** Product types with their SKU prefix and price range in cents. */
    private const array TYPES = [
        'Mok' => ['MOK', 800, 1800],
        'T-shirt' => ['TSH', 1500, 3500],
        'Hoodie' => ['HOO', 3500, 7500],
        'Rugzak' => ['RUG', 3000, 12000],
        'Waterfles' => ['WAT', 1200, 3000],
        'Notitieboek' => ['NOT', 600, 2000],
        'Koptelefoon' => ['KOP', 2500, 25000],
        'Sokken' => ['SOK', 500, 1500],
        'Pet' => ['PET', 1200, 2800],
        'Sjaal' => ['SJA', 1500, 4500],
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
        [$skuPrefix, $minPrice, $maxPrice] = self::TYPES[$type];
        $collection = $faker->randomElement(self::COLLECTIONS);
        $color = $faker->randomElement(self::COLORS);

        return [
            'sku' => $skuPrefix.'-'.$faker->unique()->numerify('#####'),
            'name' => sprintf('%s %s, %s', $type, $collection, $color),
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
