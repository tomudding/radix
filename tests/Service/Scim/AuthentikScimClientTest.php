<?php

declare(strict_types=1);

namespace App\Tests\Service\Scim;

use App\Exception\Scim\ScimConflictException;
use App\Exception\Scim\ScimException;
use App\Exception\Scim\ScimNotFoundException;
use App\Service\Scim\AuthentikScimClient;
use App\Service\Scim\GroupScimMapper;
use App\Service\Scim\GroupScimSource;
use App\Service\Scim\ScimGroup;
use App\Service\Scim\ScimOperation;
use App\Service\Scim\ScimUser;
use App\Service\Scim\UserScimMapper;
use App\Service\Scim\UserScimSource;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function array_map;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_string;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function str_repeat;
use function strlen;

use const JSON_UNESCAPED_UNICODE;
use const PHP_URL_QUERY;

/**
 * The client is tested against the pairs the M0 probes captured from the pinned Authentik
 * (`tests/fixtures/scim/`): those responses are the behavioral contract, and the request side
 * is asserted as strictly as the SCIM spec allows.
 */
final class AuthentikScimClientTest extends TestCase
{
    private const string BASE_URL = 'http://scim.test/v2';
    private const string TOKEN = 'test-secret-token';
    private const string PROBE_USER_ID = 'dae85780-363a-4e16-9cba-49ddc95621c0';
    private const string PROBE_GROUP_ID = '24dc6324-5296-4bd1-8c47-0671978126d0';

    public function testCreatingSendsTheScimPayloadAndReturnsTheRemoteId(): void
    {
        $create = new MockResponse(
            self::fixtureBody('user-create.json'),
            ['http_code' => 201],
        );
        $created = $this->client([$create])->createUser(self::probeUser());

        $expected = self::fixture('user-create.json')['response']['body'];
        self::assertSame(
            $expected['id'],
            $created->remoteId,
        );
        self::assertSame(
            'm90001',
            $created->userName,
        );
        self::assertSame(
            '90001',
            $created->externalId,
        );
        self::assertTrue($created->active);
        self::assertCount(
            1,
            $created->emails,
        );
        self::assertSame(
            'm90001@example.org',
            $created->emails[0]->value,
        );
        self::assertTrue($created->emails[0]->primary);

        self::assertSame(
            'POST',
            $create->getRequestMethod(),
        );
        self::assertSame(
            self::BASE_URL . '/Users',
            $create->getRequestUrl(),
        );
        $options = $create->getRequestOptions();
        self::assertContains(
            'Authorization: Bearer ' . self::TOKEN,
            $options['headers'],
        );
        self::assertContains(
            'Accept: application/scim+json',
            $options['headers'],
        );
        self::assertContains(
            'Content-Type: application/scim+json',
            $options['headers'],
        );
        $payload = json_decode(
            (string) $options['body'],
            true,
        );
        self::assertSame(
            ['urn:ietf:params:scim:schemas:core:2.0:User'],
            $payload['schemas'],
        );
        self::assertSame(
            'm90001',
            $payload['userName'],
        );
        self::assertSame(
            '90001',
            $payload['externalId'],
        );
        self::assertSame(
            [
                'formatted' => 'SCIM Probe',
                'givenName' => 'SCIM',
                'familyName' => 'Probe',
            ],
            $payload['name'],
        );
        self::assertSame(
            [
                [
                    'value' => 'm90001@example.org',
                    'type' => 'work',
                    'primary' => true,
                ],
            ],
            $payload['emails'],
        );
        self::assertTrue($payload['active']);
    }

    public function testCreateAdoptsAnExistingUserOnConflict(): void
    {
        $conflict = new MockResponse(
            self::fixtureBody('user-duplicate-username.json'),
            ['http_code' => 409],
        );
        $lookup = new MockResponse(
            self::fixtureBody('user-filter-username-eq.json'),
            ['http_code' => 200],
        );
        $update = new MockResponse(
            self::fixtureBody('user-get-by-id.json'),
            ['http_code' => 200],
        );
        $created = $this->client([$conflict, $lookup, $update])->createUser(self::probeUser());

        $existing = self::fixture('user-filter-username-eq.json')['response']['body']['Resources'][0];
        self::assertSame(
            'PUT',
            $update->getRequestMethod(),
        );
        self::assertSame(
            self::BASE_URL . '/Users/' . $existing['id'],
            $update->getRequestUrl(),
        );
        parse_str(
            (string) parse_url(
                $lookup->getRequestUrl(),
                PHP_URL_QUERY,
            ),
            $query,
        );
        self::assertSame(
            'userName eq "m90001"',
            $query['filter'],
        );
        self::assertSame(
            $existing['id'],
            $created->remoteId,
        );
    }

    public function testCreateThrowsTheConflictWhenNoExistingUserIsFound(): void
    {
        $conflict = new MockResponse(
            self::fixtureBody('user-duplicate-username.json'),
            ['http_code' => 409],
        );
        $empty = new MockResponse(
            (string) json_encode(['totalResults' => 0, 'Resources' => []]),
            ['http_code' => 200],
        );

        $this->expectException(ScimConflictException::class);
        $this->client([$conflict, $empty])->createUser(self::probeUser());
    }

    public function testUpdatePutsTheFullPayload(): void
    {
        $put = new MockResponse(
            self::fixtureBody('user-get-by-id.json'),
            ['http_code' => 200],
        );
        $updated = $this->client([$put])->updateUser(
            self::PROBE_USER_ID,
            self::probeUser(),
        );

        self::assertSame(
            'PUT',
            $put->getRequestMethod(),
        );
        self::assertSame(
            self::BASE_URL . '/Users/' . self::PROBE_USER_ID,
            $put->getRequestUrl(),
        );
        self::assertSame(
            self::PROBE_USER_ID,
            $updated->remoteId,
        );
        $payload = json_decode(
            (string) $put->getRequestOptions()['body'],
            true,
        );
        self::assertSame(
            'm90001',
            $payload['userName'],
        );
        self::assertSame(
            '90001',
            $payload['externalId'],
        );
    }

    public function testUpdateSurfacesAVanishedUserAsNotFound(): void
    {
        $notFound = new MockResponse(
            self::fixtureBody('user-unknown-id-put.json'),
            ['http_code' => 404],
        );
        try {
            $this->client([$notFound])->updateUser(
                '00000000-0000-4000-8000-000000000000',
                self::probeUser(),
            );
            self::fail('The 404 must surface as ScimNotFoundException.');
        } catch (ScimNotFoundException $exception) {
            self::assertSame(
                404,
                $exception->status,
            );
            self::assertFalse($exception->retryable);
            self::assertSame(
                'User not found.',
                $exception->detail,
            );
        }
    }

    public function testDeleteIsANoOpOnceTheRemoteUserIsGone(): void
    {
        $deleted = new MockResponse(
            self::fixtureBody('user-delete.json'),
            ['http_code' => 204],
        );
        $gone = new MockResponse(
            self::fixtureBody('user-unknown-id-delete.json'),
            ['http_code' => 404],
        );
        $client = $this->client([$deleted, $gone]);

        $client->deleteUser(self::PROBE_USER_ID);
        $client->deleteUser(self::PROBE_USER_ID);

        self::assertSame(
            'DELETE',
            $deleted->getRequestMethod(),
        );
        self::assertSame(
            self::BASE_URL . '/Users/' . self::PROBE_USER_ID,
            $deleted->getRequestUrl(),
        );
    }

    public function testPatchSendsReplaceOperationsWithNativeBooleans(): void
    {
        $patch = new MockResponse(
            self::fixtureBody('user-patch-active-false-bool.json'),
            ['http_code' => 200],
        );
        $patched = $this->client([$patch])->patchUser(
            self::PROBE_USER_ID,
            [
                ScimOperation::replace(
                    'active',
                    false,
                ),
            ],
        );

        $payload = json_decode(
            (string) $patch->getRequestOptions()['body'],
            true,
        );
        self::assertSame(
            [
                [
                    'op' => 'replace',
                    'path' => 'active',
                    'value' => false,
                ],
            ],
            $payload['Operations'],
        );
        self::assertFalse($patched->active);
    }

    public function testPatchWithoutOperationsReadsTheCurrentState(): void
    {
        $read = new MockResponse(
            self::fixtureBody('user-get-by-id.json'),
            ['http_code' => 200],
        );
        $current = $this->client([$read])->patchUser(
            self::PROBE_USER_ID,
            [],
        );

        self::assertSame(
            'GET',
            $read->getRequestMethod(),
        );
        self::assertTrue($current->active);
    }

    public function testGroupMembershipIsPatchedWithAddAndRemoveOperations(): void
    {
        $patch = new MockResponse(
            self::fixtureBody('group-get-after-members.json'),
            ['http_code' => 200],
        );
        $group = $this->client([$patch])->patchGroupMembers(
            self::PROBE_GROUP_ID,
            [
                'member-1',
                'member-2',
            ],
            ['member-3'],
        );

        $payload = json_decode(
            (string) $patch->getRequestOptions()['body'],
            true,
        );
        self::assertSame(
            [
                [
                    'op' => 'add',
                    'path' => 'members',
                    'value' => [
                        ['value' => 'member-1'],
                        ['value' => 'member-2'],
                    ],
                ],
                [
                    'op' => 'remove',
                    'path' => 'members',
                    'value' => [['value' => 'member-3']],
                ],
            ],
            $payload['Operations'],
        );

        $expectedMembers = [];
        foreach (self::fixture('group-get-after-members.json')['response']['body']['members'] as $member) {
            if (
                !is_array($member)
                || !is_string($member['value'] ?? null)
            ) {
                continue;
            }

            $expectedMembers[] = $member['value'];
        }

        self::assertSame(
            $expectedMembers,
            $group->memberRemoteIds,
        );
    }

    public function testGroupCreateAdoptsAnExistingGroupOnConflict(): void
    {
        $conflict = new MockResponse(
            (string) json_encode([
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
                'detail' => 'Group with ID exists already.',
                'status' => 409,
                'scimType' => 'uniqueness',
            ]),
            ['http_code' => 409],
        );
        $lookup = new MockResponse(
            self::fixtureBody('group-filter-displayname.json'),
            ['http_code' => 200],
        );
        $update = new MockResponse(
            self::fixtureBody('group-create.json'),
            ['http_code' => 200],
        );
        $group = $this->client([$conflict, $lookup, $update])->createGroup(self::probeGroup());

        $existing = self::fixture('group-filter-displayname.json')['response']['body']['Resources'][0];
        self::assertSame(
            'PUT',
            $update->getRequestMethod(),
        );
        self::assertSame(
            self::BASE_URL . '/Groups/' . $existing['id'],
            $update->getRequestUrl(),
        );
        parse_str(
            (string) parse_url(
                $lookup->getRequestUrl(),
                PHP_URL_QUERY,
            ),
            $query,
        );
        self::assertSame(
            'displayName eq "SCIM Probe Group"',
            $query['filter'],
        );
        self::assertSame(
            $existing['id'],
            $group->remoteId,
        );
    }

    public function testListUsersPaginatesUntilEveryResourceIsCollected(): void
    {
        $firstPage = new MockResponse(
            (string) json_encode([
                'totalResults' => 3,
                'itemsPerPage' => 2,
                'startIndex' => 1,
                'Resources' => [
                    self::userResource(
                        'user-1',
                        'm1',
                        '1',
                    ),
                    self::userResource(
                        'user-2',
                        'm2',
                        '2',
                    ),
                ],
            ]),
            ['http_code' => 200],
        );
        $secondPage = new MockResponse(
            (string) json_encode([
                'totalResults' => 3,
                'itemsPerPage' => 2,
                'startIndex' => 4,
                'Resources' => [
                    self::userResource(
                        'user-3',
                        'm3',
                        '3',
                    ),
                ],
            ]),
            ['http_code' => 200],
        );
        $users = iterator_to_array(
            $this->client([$firstPage, $secondPage])->listUsers(),
            false,
        );

        self::assertCount(
            3,
            $users,
        );
        self::assertSame(
            [
                'user-1',
                'user-2',
                'user-3',
            ],
            array_map(
                static fn (ScimUser $user): string => (string) $user->remoteId,
                $users,
            ),
        );
        // The source derives the page number from startIndex divided by the page size, so the
        // second page of a page size 2 is reached with startIndex 4, not with the index of its
        // first item.
        self::assertStringContainsString(
            'startIndex=1',
            $firstPage->getRequestUrl(),
        );
        self::assertStringContainsString(
            'startIndex=4',
            $secondPage->getRequestUrl(),
        );
        self::assertStringNotContainsString(
            'count=',
            $firstPage->getRequestUrl(),
        );
    }

    public function testListingThrowsWhenTheServerRepeatsAFullPage(): void
    {
        // The server keeps answering the first page for every startIndex: a full page that does
        // not advance while totalResults promises more is a truncated list, and a truncated
        // list must fail rather than be read as a shorter one.
        $page = (string) json_encode([
            'totalResults' => 42,
            'itemsPerPage' => 2,
            'Resources' => [
                self::userResource(
                    'user-1',
                    'm1',
                    '1',
                ),
                self::userResource(
                    'user-2',
                    'm2',
                    '2',
                ),
            ],
        ]);
        $client = $this->client([
            new MockResponse(
                $page,
                ['http_code' => 200],
            ),
            new MockResponse(
                $page,
                ['http_code' => 200],
            ),
        ]);

        self::expectException(ScimException::class);
        iterator_to_array(
            $client->listUsers(),
            false,
        );
    }

    public function testListingFailsAtThePageCap(): void
    {
        // A source that keeps answering full pages of fresh ids would run the walk forever and
        // hang the consumer and the nightly list scans, so the walk ends in an error at the
        // cap instead.
        $mock = new MockHttpClient(static function (string $method, string $url): ResponseInterface {
            parse_str(
                (string) parse_url(
                    $url,
                    PHP_URL_QUERY,
                ),
                $query,
            );
            $start = (int) ($query['startIndex'] ?? 1);
            $resources = [];
            for ($i = 0; $i < 20; ++$i) {
                $resources[] = self::userResource(
                    'user-' . $start . '-' . $i,
                    'm' . $start . $i,
                    (string) ($start + $i),
                );
            }

            return new MockResponse(
                (string) json_encode([
                    'itemsPerPage' => 20,
                    'Resources' => $resources,
                ]),
                ['http_code' => 200],
            );
        });
        $client = new AuthentikScimClient(
            $mock,
            self::BASE_URL,
            self::TOKEN,
            false,
        );

        try {
            iterator_to_array(
                $client->listUsers(),
                false,
            );
            self::fail('expected a ScimException');
        } catch (ScimException $exception) {
            self::assertStringContainsString(
                'the list returned 10000 full pages without advancing',
                $exception->getMessage(),
            );
        }
    }

    public function testListingStopsOnAShortPage(): void
    {
        // A page shorter than the page size is the last one, whatever the server says or omits.
        $mock = new MockHttpClient([
            new MockResponse(
                (string) json_encode([
                    'totalResults' => 20,
                    'itemsPerPage' => 20,
                    'Resources' => [
                        self::userResource(
                            'user-1',
                            'm1',
                            '1',
                        ),
                    ],
                ]),
                ['http_code' => 200],
            ),
        ]);
        $client = new AuthentikScimClient(
            $mock,
            self::BASE_URL,
            self::TOKEN,
            false,
        );
        $users = iterator_to_array(
            $client->listUsers(),
            false,
        );

        self::assertCount(
            1,
            $users,
        );
        self::assertSame(
            1,
            $mock->getRequestsCount(),
        );
    }

    public function testGroupAdoptionScansTheListWhenTheNameNeedsEscaping(): void
    {
        // The displayName carries a double quote, which cannot be embedded in the filter
        // expression: the adoption looks the group up in the full list instead.
        $name = 'Vereeniging "Radix"';
        $conflict = new MockResponse(
            (string) json_encode([
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
                'detail' => 'Group with ID exists already.',
                'status' => 409,
                'scimType' => 'uniqueness',
            ]),
            ['http_code' => 409],
        );
        $list = new MockResponse(
            (string) json_encode([
                'totalResults' => 1,
                'Resources' => [
                    [
                        'id' => 'group-1',
                        'displayName' => $name,
                        'externalId' => 'radix-avw:radix',
                        'members' => [],
                    ],
                ],
            ]),
            ['http_code' => 200],
        );
        $update = new MockResponse(
            (string) json_encode([
                'id' => 'group-1',
                'displayName' => $name,
                'externalId' => 'radix-avw:radix',
                'members' => [],
            ]),
            ['http_code' => 200],
        );
        $group = $this->client([$conflict, $list, $update])->createGroup(
            new ScimGroup(
                displayName: $name,
                externalId: 'radix-avw:radix',
                memberRemoteIds: [],
            ),
        );

        self::assertStringNotContainsString(
            'filter=',
            $list->getRequestUrl(),
        );
        self::assertSame(
            'PUT',
            $update->getRequestMethod(),
        );
        self::assertSame(
            self::BASE_URL . '/Groups/group-1',
            $update->getRequestUrl(),
        );
        self::assertSame(
            'group-1',
            $group->remoteId,
        );
    }

    public function testAnErrorDetailIsTruncated(): void
    {
        $response = new MockResponse(
            (string) json_encode([
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
                'detail' => str_repeat(
                    'a',
                    600,
                ),
                'status' => 500,
            ]),
            ['http_code' => 500],
        );

        try {
            iterator_to_array(
                $this->client([$response])->listUsers(),
                false,
            );
            self::fail('expected a ScimException');
        } catch (ScimException $exception) {
            self::assertNotNull($exception->detail);
            self::assertSame(
                500,
                strlen($exception->detail),
            );
        }
    }

    public function testACreateUserWithoutAnIdFails(): void
    {
        $response = new MockResponse(
            (string) json_encode(
                self::withoutId(self::userResource('user-1', 'm1', '1')),
            ),
            ['http_code' => 201],
        );

        try {
            $this->client([$response])->createUser(self::probeUser());
            self::fail('expected a ScimException');
        } catch (ScimException $exception) {
            self::assertFalse($exception->retryable);
            self::assertStringContainsString(
                'the create response carries no id',
                $exception->getMessage(),
            );
        }
    }

    public function testACreateGroupWithoutAnIdFails(): void
    {
        $response = new MockResponse(
            (string) json_encode([
                'displayName' => 'SCIM Probe Group',
                'externalId' => 'committee:probe',
                'members' => [],
            ]),
            ['http_code' => 201],
        );

        try {
            $this->client([$response])->createGroup(self::probeGroup());
            self::fail('expected a ScimException');
        } catch (ScimException $exception) {
            self::assertFalse($exception->retryable);
            self::assertStringContainsString(
                'the create response carries no id',
                $exception->getMessage(),
            );
        }
    }

    public function testATransportFailureIsRetryableAndKeepsTheCause(): void
    {
        $failed = new MockResponse(
            '',
            ['error' => 'Connection refused'],
        );
        try {
            $this->client([$failed])->createUser(self::probeUser());
            self::fail('A transport failure must surface as ScimException.');
        } catch (ScimException $exception) {
            self::assertNull($exception->status);
            self::assertTrue($exception->retryable);
            self::assertStringContainsString(
                'Connection refused',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                self::TOKEN,
                $exception->getMessage(),
            );
        }
    }

    public function testAServerCrashOnABadRequestIsNotRetryable(): void
    {
        // A duplicate externalId with a different userName crashes the source with an HTML page
        // (findings §7); the request is a client error, not a transient one.
        $crash = new MockResponse(
            self::fixtureBody('user-duplicate-externalid.json'),
            ['http_code' => 500],
        );
        try {
            $this->client([$crash])->createUser(self::probeUser());
            self::fail('The crash must surface as ScimException.');
        } catch (ScimException $exception) {
            self::assertSame(
                500,
                $exception->status,
            );
            self::assertFalse($exception->retryable);
            self::assertStringNotContainsString(
                self::TOKEN,
                $exception->getMessage(),
            );
        }
    }

    public function testA500WithAScimErrorBodyIsRetryable(): void
    {
        $broken = new MockResponse(
            (string) json_encode([
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
                'detail' => 'Something broke.',
                'status' => 500,
            ]),
            ['http_code' => 500],
        );
        try {
            $this->client([$broken])->updateUser(
                self::PROBE_USER_ID,
                self::probeUser(),
            );
            self::fail('The 500 must surface as ScimException.');
        } catch (ScimException $exception) {
            self::assertSame(
                500,
                $exception->status,
            );
            self::assertTrue($exception->retryable);
            self::assertSame(
                'Something broke.',
                $exception->detail,
            );
        }
    }

    public function testAnAuthFailureKeepsTheDetailAndNeverTheToken(): void
    {
        $refused = new MockResponse(
            (string) json_encode([
                'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
                'detail' => 'Invalid token',
                'status' => 401,
            ]),
            ['http_code' => 401],
        );
        try {
            $this->client([$refused])->updateUser(
                self::PROBE_USER_ID,
                self::probeUser(),
            );
            self::fail('The 401 must surface as ScimException.');
        } catch (ScimException $exception) {
            self::assertSame(
                401,
                $exception->status,
            );
            self::assertFalse($exception->retryable);
            self::assertSame(
                'Invalid token',
                $exception->detail,
            );
            self::assertStringContainsString(
                'Invalid token',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                self::TOKEN,
                $exception->getMessage(),
            );
        }
    }

    public function testCreatingSendsUnicodeUnescaped(): void
    {
        $created = new MockResponse(
            self::fixtureBody('user-create-unicode.json'),
            ['http_code' => 201],
        );
        $user = new UserScimMapper()->map(new UserScimSource('90040', 'Zoë', 'Müller-Schäfer 田中太郎'));
        $this->client([$created])->createUser($user);

        $body = (string) $created->getRequestOptions()['body'];
        self::assertStringContainsString(
            'Zoë',
            $body,
        );
        self::assertStringContainsString(
            'Müller-Schäfer 田中太郎',
            $body,
        );
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): AuthentikScimClient
    {
        return new AuthentikScimClient(
            new MockHttpClient($responses),
            self::BASE_URL,
            self::TOKEN,
            false,
        );
    }

    public function testItRefusesAPlainHttpEndpointOutsideTheDevelopmentStack(): void
    {
        try {
            new AuthentikScimClient(
                new MockHttpClient([]),
                'http://example.com/v2',
                self::TOKEN,
                true,
            );
            self::fail('expected a LogicException');
        } catch (LogicException $exception) {
            self::assertStringContainsString(
                'plain http for a host outside the development stack',
                $exception->getMessage(),
            );
        }
    }

    public function testItRefusesAnEndpointWithEmbeddedCredentials(): void
    {
        try {
            new AuthentikScimClient(
                new MockHttpClient([]),
                'https://user:pass@example.com/v2',
                self::TOKEN,
                true,
            );
            self::fail('expected a LogicException');
        } catch (LogicException $exception) {
            self::assertStringContainsString(
                'not a usable endpoint',
                $exception->getMessage(),
            );
        }
    }

    public function testItRefusesPlainHttpOutsideTheDevelopmentStackWhenEnabled(): void
    {
        try {
            new AuthentikScimClient(
                new MockHttpClient([]),
                'http://127.0.0.1:9100/v2',
                self::TOKEN,
                true,
            );
            self::fail('expected a LogicException');
        } catch (LogicException $exception) {
            self::assertStringContainsString(
                'plain http for a host outside the development stack',
                $exception->getMessage(),
            );
        }
    }

    public function testItAcceptsTheDevelopmentAndHttpsEndpointsWhenEnabled(): void
    {
        new AuthentikScimClient(
            new MockHttpClient([]),
            'http://authentik:9000/source/scim/radix/v2',
            self::TOKEN,
            true,
        );
        new AuthentikScimClient(
            new MockHttpClient([]),
            'https://id.gewis.net/source/scim/radix/v2',
            self::TOKEN,
            true,
        );
        // With the feature off the endpoint is never used, and no configuration may fail a boot.
        new AuthentikScimClient(
            new MockHttpClient([]),
            'http://example.com/v2',
            self::TOKEN,
            false,
        );
    }

    private static function probeUser(): ScimUser
    {
        return new UserScimMapper()->map(new UserScimSource(
            lidnr: '90001',
            firstName: 'SCIM',
            lastName: 'Probe',
            email: 'm90001@example.org',
            active: true,
        ));
    }

    private static function probeGroup(): ScimGroup
    {
        return new GroupScimMapper()->map(new GroupScimSource(
            displayName: 'SCIM Probe Group',
            externalId: 'committee:probe',
            memberRemoteIds: [],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function userResource(
        string $id,
        string $userName,
        string $externalId,
    ): array {
        return [
            'id' => $id,
            'userName' => $userName,
            'externalId' => $externalId,
            'name' => ['formatted' => $userName],
            'emails' => [],
            'active' => true,
        ];
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private static function withoutId(array $resource): array
    {
        unset($resource['id']);

        return $resource;
    }

    /**
     * The captured probe, decoded.
     *
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        return (array) json_decode(
            (string) file_get_contents(self::fixtureDir() . '/' . $name),
            true,
        );
    }

    private static function fixtureBody(string $name): string
    {
        $body = self::fixture($name)['response']['body'] ?? null;
        if (null === $body) {
            return '';
        }

        return is_string($body)
            ? $body
            : (string) json_encode(
                $body,
                JSON_UNESCAPED_UNICODE,
            );
    }

    private static function fixtureDir(): string
    {
        return dirname(
            __DIR__,
            2,
        ) . '/fixtures/scim';
    }
}
