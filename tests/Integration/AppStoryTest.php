<?php

namespace App\Tests\Integration;

use App\Factory\OAuthClientFactory;
use App\Story\AppStory;
use App\Tests\Support\DatabaseFixturesTrait;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The demo data set that local, DDEV and test environments are loaded with.
 */
final class AppStoryTest extends KernelTestCase
{
    use DatabaseFixturesTrait;

    /**
     * Starts every test with an empty database.
     */
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    /**
     * The demo client redirects to the callback of the frontend this environment is configured with.
     */
    public function testDemoClientRedirectsToTheFrontendOfThisEnvironment(): void
    {
        AppStory::load();

        $client = self::getContainer()->get(ClientManagerInterface::class)->find(OAuthClientFactory::DEMO_IDENTIFIER);

        self::assertNotNull($client);
        self::assertSame(
            [rtrim($_SERVER['FRONTEND_URL'], '/').'/callback'],
            array_map(static fn (RedirectUri $uri): string => (string) $uri, $client->getRedirectUris()),
        );
    }
}
