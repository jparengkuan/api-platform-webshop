<?php

namespace App\ApiResource;

/**
 * HTTP cache settings shared by API operations.
 */
final class CacheHeaders
{
    /**
     * Public catalog data (products, categories): identical for every visitor.
     *
     * - Varnish (shared_max_age) keeps it for an hour; API Platform bans it the moment it changes.
     * - Browsers (max_age) keep it for a minute, because nobody can purge a browser cache.
     * - Vary repeats the defaults, as operation-level cache headers replace them.
     */
    public const array PUBLIC_CATALOG = [
        'public' => true,
        'max_age' => 60,
        'shared_max_age' => 3600,
        'vary' => ['Content-Type', 'Authorization', 'Origin'],
    ];
}
