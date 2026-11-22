<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scim;

use App\Service\Scim\AuthentikScimClient;
use App\Service\Scim\ScimGroup;
use App\Service\Scim\ScimUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function in_array;
use function parse_url;
use function sprintf;

use const PHP_URL_HOST;
use const PHP_URL_PORT;
use const PHP_URL_SCHEME;

/**
 * Base class for the tests that talk to a real SCIM source.
 *
 * Subclasses carry the group: the `authentik` group is excluded from ordinary runs (`make test`
 * and `make test-coverage` pass `--exclude-group authentik`), so only `make test-authentik`
 * (local, after `make start`) ever runs them. The host allowlist here is the defence in depth
 * on top of that exclusion: the lifecycle deletes what it creates, and the pinned source
 * performs hard deletes, so the group must never point at anything but a throwaway
 * development source.
 */
abstract class AuthentikIntegrationTestCase extends TestCase
{
    /**
     * The loopback of a host-side run and the service name of the development stack, where the
     * source only ever holds test data.
     */
    private const array ALLOWED_HOSTS = [
        'localhost',
        '127.0.0.1',
        'authentik',
    ];

    protected AuthentikScimClient $client;

    /**
     * The SCIM base URL exactly as configured.
     */
    protected string $scimBaseUrl;

    /**
     * The API root (`…/api/v3`), derived from the base URL.
     */
    protected string $apiRoot;

    protected HttpClientInterface $httpClient;

    protected string $token;

    /**
     * A second credential for the admin API: the SCIM token's service account sees none of the
     * instance's users, so the safety snapshots in the subclasses need one. The blueprint pins
     * it to the same value `.env.local` carries (SCIM_AUTHENTIK_ADMIN_TOKEN).
     */
    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // The phpunit bootstrap loads `.env.test`, whose SCIM values are dummies. Real process
        // environment variables take precedence over `.env` files, which is how `make
        // test-authentik` hands over the real endpoint.
        $baseUrl = (string) ($_SERVER['SCIM_AUTHENTIK_BASE_URL'] ?? '');
        $token = (string) ($_SERVER['SCIM_AUTHENTIK_TOKEN'] ?? '');

        if ('' === $baseUrl) {
            self::fail(
                'SCIM_AUTHENTIK_BASE_URL is not set. This group is excluded from ordinary runs on purpose; '
                . 'start the development source (make start) and run make test-authentik.',
            );
        }

        $host = (string) parse_url(
            $baseUrl,
            PHP_URL_HOST,
        );
        if (
            !in_array(
                $host,
                self::ALLOWED_HOSTS,
                true,
            )
        ) {
            self::fail(sprintf(
                'Refusing to run the SCIM integration tests against "%s": the group may only point at a '
                . 'throwaway development source (localhost or the compose service name authentik), because it '
                . 'deletes what it creates.',
                $host,
            ));
        }

        if (
            '' === $token
            || 'test-token' === $token
        ) {
            self::fail(
                'SCIM_AUTHENTIK_TOKEN is not set (or is the committed test placeholder). Run make start, '
                . 'which reads the source token into .env.local, then make test-authentik.',
            );
        }

        $adminToken = (string) ($_SERVER['SCIM_AUTHENTIK_ADMIN_TOKEN'] ?? '');
        if ('' === $adminToken) {
            self::fail(
                'SCIM_AUTHENTIK_ADMIN_TOKEN is not set. Run make start, which creates it in Authentik and '
                . 'writes it into .env.local, then make test-authentik.',
            );
        }

        $this->scimBaseUrl = $baseUrl;
        $this->token = $token;
        $this->adminToken = $adminToken;
        $this->httpClient = HttpClient::create();
        $this->client = new AuthentikScimClient(
            $this->httpClient,
            $baseUrl,
            $token,
            true,
        );
        $this->apiRoot = sprintf(
            '%s://%s:%s/api/v3',
            (string) parse_url(
                $baseUrl,
                PHP_URL_SCHEME,
            ),
            $host,
            (string) (parse_url(
                $baseUrl,
                PHP_URL_PORT,
            ) ?? 80),
        );
    }

    /**
     * The user with the given external identifier, or null. The list answers everything the
     * client knows about a user, and walking it also exercises the pinned source's pagination.
     */
    protected function findUserByExternalId(string $externalId): ?ScimUser
    {
        foreach ($this->client->listUsers() as $user) {
            if ($externalId === $user->externalId) {
                return $user;
            }
        }

        return null;
    }

    /**
     * The group with the given external identifier, or null. Same list walk as for users.
     */
    protected function findGroupByExternalId(string $externalId): ?ScimGroup
    {
        foreach ($this->client->listGroups() as $group) {
            if ($externalId === $group->externalId) {
                return $group;
            }
        }

        return null;
    }

    /**
     * One call to the admin API with the `akadmin` credential, for reading the state the SCIM
     * endpoint cannot show (the bootstrap admin, the groups it belongs to).
     *
     * @return array<string, mixed>
     */
    protected function adminApi(
        string $path,
        string $method = 'GET',
    ): array {
        $response = $this->httpClient->request(
            $method,
            $this->apiRoot . $path,
            ['auth_bearer' => $this->adminToken],
        );
        self::assertSame(
            200,
            $response->getStatusCode(),
            sprintf(
                '%s %s should answer 200, got %s: %s',
                $method,
                $path,
                $response->getStatusCode(),
                $response->getContent(false),
            ),
        );

        $data = $response->toArray(false);
        self::assertIsArray($data);

        return $data;
    }
}
