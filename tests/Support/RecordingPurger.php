<?php

namespace App\Tests\Support;

use ApiPlatform\HttpCache\PurgerInterface;

/**
 * Stands in for the Varnish purger in tests: records which IRIs would be banned.
 *
 * The list is static because the test client reboots the kernel (and so the container) between requests.
 */
final class RecordingPurger implements PurgerInterface
{
    /** @var list<string> */
    private static array $purged = [];

    /**
     * Records the IRIs instead of sending a BAN request.
     *
     * @param string[] $iris
     */
    public function purge(array $iris): void
    {
        array_push(self::$purged, ...array_values($iris));
    }

    /**
     * Returns the cache tag header, exactly like the Varnish purger does.
     *
     * @param string[] $iris
     *
     * @return array<string, string>
     */
    public function getResponseHeaders(array $iris): array
    {
        return ['Cache-Tags' => implode(',', $iris)];
    }

    /**
     * Returns every IRI purged since the last {@see self::reset()}.
     *
     * @return list<string>
     */
    public static function purged(): array
    {
        return self::$purged;
    }

    /**
     * Forgets the recorded IRIs.
     */
    public static function reset(): void
    {
        self::$purged = [];
    }
}
