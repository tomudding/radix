<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;
use Symfony\Component\Panther\Client;

/**
 * The SCIM sync page under System > Integrations: the state it renders and the three pushes it
 * can dispatch. The test server's SCIM transport is in-memory and nothing consumes it, so the
 * tests see the dispatch, not a push to a source.
 */
final class ScimSyncTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testThePageShowsTheStateAndTheSidebarReachesIt(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/scim-sync',
        );

        self::assertStringContainsString(
            '/en/admin/scim-sync',
            $client->getCurrentURL(),
        );
        $this->assertPageContains(
            $client,
            'SCIM sync',
        );
        $this->assertPageContains(
            $client,
            'Push now',
        );
        $this->assertPageContains(
            $client,
            'Failed pushes',
        );
        self::assertSelectorExists('a[href="/en/admin/scim-sync"]');
    }

    public function testTheFullSyncDispatches(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/scim-sync',
        );

        $client->submitForm('Sync everything');
        $client->waitFor('.toast');

        $this->assertPageContains(
            $client,
            'The full sync has been dispatched to the worker queue.',
        );
    }

    public function testOneAccountDispatches(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/scim-sync',
        );

        $client->submitForm(
            'Sync account',
            [
                'lidnr' => '8000',
            ],
        );
        $client->waitFor('.toast');

        $this->assertPageContains(
            $client,
            'The account of member 8000 has been dispatched to the worker queue.',
        );
    }

    public function testAnUnknownAccountIsRefused(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/scim-sync',
        );

        $client->submitForm(
            'Sync account',
            [
                'lidnr' => '99999999',
            ],
        );
        $client->waitFor('.toast');

        $this->assertPageContains(
            $client,
            'No account with membership number 99999999.',
        );
    }

    public function testOneBodyDispatches(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/scim-sync',
        );

        // The first option is the placeholder; the second is the first body the seed holds.
        $foundationHash = $client->getCrawler()->filter('#scim-sync-body option')->eq(1)->attr('value');
        self::assertNotNull($foundationHash);

        $client->submitForm(
            'Sync body',
            [
                'body' => $foundationHash,
            ],
        );
        $client->waitFor('.toast');

        $this->assertPageContains(
            $client,
            'The body has been dispatched to the worker queue.',
        );
    }

    private function assertPageContains(
        Client $client,
        string $text,
    ): void {
        self::assertStringContainsString(
            $text,
            $client->getPageSource(),
        );
    }
}
