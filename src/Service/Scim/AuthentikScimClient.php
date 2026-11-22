<?php

declare(strict_types=1);

namespace App\Service\Scim;

use App\Exception\Scim\ScimConflictException;
use App\Exception\Scim\ScimException;
use App\Exception\Scim\ScimNotFoundException;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function array_key_first;
use function array_map;
use function count;
use function floor;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function json_validate;
use function max;
use function mb_substr;
use function parse_url;
use function preg_match;
use function rawurlencode;
use function sprintf;
use function time;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;
use const PHP_URL_USER;

/**
 * The SCIM 2.0 client for Authentik's SCIM source. A method fails with the
 * {@see ScimException} of the request that failed; the create methods instead read the existing
 * object and update it. Retries are the Messenger layer's concern. The base URL already names
 * the source's endpoint, so the paths here are the SCIM resource paths.
 */
final readonly class AuthentikScimClient implements ScimClientInterface
{
    // The source ignores `count` and pages at its system setting (20 per `ServiceProviderConfig`);
    // 20 is the fallback page size until a response's `itemsPerPage` says otherwise.
    private const int LIST_PAGE_SIZE = 20;

    // A source that keeps returning full pages must end the walk in an error, not hang the
    // consumer and the nightly list scans.
    private const int LIST_MAX_PAGES = 10_000;

    // The host that may receive the bearer token over plain http: the in-network service name of
    // the development Authentik.
    private const array HTTP_HOSTS = [
        'authentik',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'SCIM_AUTHENTIK_BASE_URL')]
        private string $baseUrl,
        #[Autowire(env: 'SCIM_AUTHENTIK_TOKEN')]
        private string $token,
        #[Autowire('app.scim.enabled')]
        private bool $scimEnabled,
    ) {
        if (!$this->scimEnabled) {
            return;
        }

        $this->assertSafeBaseUrl();
    }

    /**
     * The bearer token travels to whatever host this URL names, so with the feature on the URL
     * must name a sane destination: https for any host, plain http only inside the development
     * stack, and never a URL that already carries credentials of its own.
     */
    private function assertSafeBaseUrl(): void
    {
        if ('' === $this->baseUrl) {
            // No endpoint configured: nothing goes out, and there is nothing to misuse.
            return;
        }

        $scheme = parse_url(
            $this->baseUrl,
            PHP_URL_SCHEME,
        );
        $host = parse_url(
            $this->baseUrl,
            PHP_URL_HOST,
        );
        $user = parse_url(
            $this->baseUrl,
            PHP_URL_USER,
        );

        if (
            null === $host
            || null !== $user
            || !in_array(
                $scheme,
                [
                    'http',
                    'https',
                ],
                true,
            )
        ) {
            throw new LogicException(sprintf(
                'SCIM_AUTHENTIK_BASE_URL is not a usable endpoint: %s',
                $this->baseUrl,
            ));
        }

        if (
            'http' === $scheme
            && !in_array(
                $host,
                self::HTTP_HOSTS,
                true,
            )
        ) {
            throw new LogicException(sprintf(
                'SCIM_AUTHENTIK_BASE_URL uses plain http for a host outside the development stack: %s',
                $this->baseUrl,
            ));
        }
    }

    public function createUser(ScimUser $user): ScimUser
    {
        try {
            $created = $this->toScimUser($this->request('POST', '/Users', $user->toArray()));
            if (null === $created->remoteId) {
                // The create response must carry the new id: without it the link table cannot
                // record the object, and the next sync would create it again.
                throw new ScimException(
                    '/Users',
                    null,
                    false,
                    'the create response carries no id',
                );
            }

            return $created;
        } catch (ScimConflictException $conflict) {
            // A duplicate userName means the source already manages a user with this name
            // (findings §7). The only recovery is to adopt it: put the full state on the existing
            // object.
            $existing = $this->findUserByName($user->userName);
            if (
                null === $existing
                || null === $existing->remoteId
            ) {
                throw $conflict;
            }

            return $this->updateUser(
                $existing->remoteId,
                $user,
            );
        }
    }

    public function updateUser(
        string $remoteId,
        ScimUser $user,
    ): ScimUser {
        return $this->toScimUser($this->request('PUT', $this->userPath($remoteId), $user->toArray()));
    }

    /**
     * @param list<ScimOperation> $operations
     */
    public function patchUser(
        string $remoteId,
        array $operations,
    ): ScimUser {
        if (0 === count($operations)) {
            // A patch without operations is a no-op; the current state is read instead of asking
            // the server to change nothing.
            return $this->toScimUser($this->request('GET', $this->userPath($remoteId)));
        }

        $data = $this->request(
            'PATCH',
            $this->userPath($remoteId),
            ['Operations' => $this->operationsToArray($operations)],
        );

        return $this->toScimUser($data);
    }

    public function deleteUser(string $remoteId): void
    {
        try {
            $this->request(
                'DELETE',
                $this->userPath($remoteId),
            );
        } catch (ScimNotFoundException) {
            // The remote user is already gone; deleting it again stays a no-op.
        }
    }

    /**
     * @return iterable<ScimUser>
     */
    public function listUsers(): iterable
    {
        yield from $this->listResources(
            '/Users',
            fn (array $data): ScimUser => $this->toScimUser($data),
        );
    }

    public function createGroup(ScimGroup $group): ScimGroup
    {
        try {
            $created = $this->toScimGroup($this->request('POST', '/Groups', $group->toArray()));
            if (null === $created->remoteId) {
                // The create response must carry the new id: without it the link table cannot
                // record the object, and the next sync would create it again.
                throw new ScimException(
                    '/Groups',
                    null,
                    false,
                    'the create response carries no id',
                );
            }

            return $created;
        } catch (ScimConflictException $conflict) {
            // Groups correlate by displayName, the same recovery as for users.
            $existing = $this->findGroupByName($group->displayName);
            if (
                null === $existing
                || null === $existing->remoteId
            ) {
                throw $conflict;
            }

            return $this->updateGroup(
                $existing->remoteId,
                $group,
            );
        }
    }

    public function updateGroup(
        string $remoteId,
        ScimGroup $group,
    ): ScimGroup {
        return $this->toScimGroup($this->request('PUT', $this->groupPath($remoteId), $group->toArray()));
    }

    /**
     * @param list<string> $addRemoteIds
     * @param list<string> $removeRemoteIds
     */
    public function patchGroupMembers(
        string $remoteId,
        array $addRemoteIds,
        array $removeRemoteIds,
    ): ScimGroup {
        $operations = [];
        if ([] !== $addRemoteIds) {
            $operations[] = ScimOperation::add(
                'members',
                ScimGroup::memberObjects($addRemoteIds),
            );
        }

        if ([] !== $removeRemoteIds) {
            $operations[] = ScimOperation::remove(
                'members',
                ScimGroup::memberObjects($removeRemoteIds),
            );
        }

        if ([] === $operations) {
            // No membership delta is a no-op; the current state is read instead.
            return $this->toScimGroup($this->request('GET', $this->groupPath($remoteId)));
        }

        $data = $this->request(
            'PATCH',
            $this->groupPath($remoteId),
            ['Operations' => $this->operationsToArray($operations)],
        );

        return $this->toScimGroup($data);
    }

    public function deleteGroup(string $remoteId): void
    {
        try {
            $this->request(
                'DELETE',
                $this->groupPath($remoteId),
            );
        } catch (ScimNotFoundException) {
            // The remote group is already gone; deleting it again stays a no-op.
        }
    }

    /**
     * @return iterable<ScimGroup>
     */
    public function listGroups(): iterable
    {
        yield from $this->listResources(
            '/Groups',
            fn (array $data): ScimGroup => $this->toScimGroup($data),
        );
    }

    /**
     * One request, a typed outcome. The exception message carries the path, the status and the
     * SCIM error detail only.
     *
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $payload = null,
    ): array {
        $options = [
            'auth_bearer' => $this->token,
            'headers' => ['Accept' => 'application/scim+json'],
        ];
        if (null !== $payload) {
            $options['body'] = (string) json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            $options['headers']['Content-Type'] = 'application/scim+json';
        }

        try {
            $response = $this->httpClient->request(
                $method,
                $this->baseUrl . $path,
                $options,
            );
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            // A network failure may be over before the message is delivered again, so it is the
            // one kind of failure worth retrying.
            throw new ScimException(
                $path,
                null,
                true,
                $exception->getMessage(),
            );
        }

        if (
            $status >= 200
            && $status < 300
        ) {
            if ('' === $body) {
                return [];
            }

            if (!json_validate($body)) {
                throw new ScimException(
                    $path,
                    $status,
                    false,
                    'the response body is not JSON',
                );
            }

            $decoded = json_decode(
                $body,
                true,
            );
            if (!is_array($decoded)) {
                throw new ScimException(
                    $path,
                    $status,
                    false,
                    'the response body is not a JSON object',
                );
            }

            return $decoded;
        }

        $detail = null;
        $jsonBody = null;
        if (
            '' !== $body
            && json_validate($body)
        ) {
            $decoded = json_decode(
                $body,
                true,
            );
            if (is_array($decoded)) {
                $jsonBody = $decoded;
                if (
                    isset($decoded['detail'])
                    && is_string($decoded['detail'])
                ) {
                    // The detail lands in the exception message, and that message is logged and
                    // stored on the failure transport: a long body must not reach it whole.
                    $detail = mb_substr(
                        $decoded['detail'],
                        0,
                        500,
                    );
                }
            }
        }

        if (404 === $status) {
            throw new ScimNotFoundException(
                $path,
                $detail,
            );
        }

        if (409 === $status) {
            throw new ScimConflictException(
                $path,
                $detail,
            );
        }

        if (429 === $status) {
            throw new ScimException(
                $path,
                $status,
                true,
                $detail,
                $this->retryAfterMs($response),
            );
        }

        if ($status >= 500) {
            // A JSON body is a SCIM error that may be transient; an HTML body is the server
            // crashing on a bad request (findings §5, §12), and the same request returns the
            // same crash, so it must not be retried.
            throw new ScimException(
                $path,
                $status,
                null !== $jsonBody,
                $detail,
            );
        }

        throw new ScimException(
            $path,
            $status,
            false,
            $detail,
        );
    }

    /**
     * Pages through a SCIM list until every resource is collected. The list returns its
     * resources under `Resources`, the SCIM 2.0 ListResponse key (see the M0 fixtures).
     *
     * @template T
     *
     * @param callable(array<string, mixed>): T $toValueObject
     *
     * @return iterable<T>
     */
    private function listResources(
        string $path,
        callable $toValueObject,
    ): iterable {
        $page = 1;
        $startIndex = 1;
        $collected = 0;
        $totalResults = null;
        $pageSize = self::LIST_PAGE_SIZE;
        $lastFirstId = null;

        while (true) {
            if ($page > self::LIST_MAX_PAGES) {
                throw new ScimException(
                    $path,
                    null,
                    false,
                    sprintf(
                        'the list returned %d full pages without advancing',
                        self::LIST_MAX_PAGES,
                    ),
                );
            }

            $data = $this->request(
                'GET',
                sprintf(
                    '%s?startIndex=%d',
                    $path,
                    $startIndex,
                ),
            );
            $resources = $data['Resources'] ?? [];
            if (
                !is_array($resources)
                || [] === $resources
            ) {
                break;
            }

            $first = $resources[array_key_first($resources)];
            $firstId = is_array($first) && is_string($first['id'] ?? null)
                ? $first['id']
                : null;
            if (
                null !== $firstId
                && null !== $lastFirstId
                && $firstId === $lastFirstId
            ) {
                if (
                    null !== $totalResults
                    && $collected < $totalResults
                ) {
                    // The list stopped advancing before every resource was returned. A partial
                    // list would make the reconciliation report healthy objects as gone, so a
                    // truncated list is an error rather than a shorter list.
                    throw new ScimException(
                        $path,
                        null,
                        false,
                        sprintf(
                            'the list returned the same page twice after %d of %d resources',
                            $collected,
                            $totalResults,
                        ),
                    );
                }

                break;
            }

            $lastFirstId = $firstId;

            foreach ($resources as $resource) {
                if (!is_array($resource)) {
                    continue;
                }

                yield $toValueObject($resource);
            }

            $collected += count($resources);
            if (isset($data['itemsPerPage'])) {
                $pageSize = (int) $data['itemsPerPage'];
            }

            if (isset($data['totalResults'])) {
                $totalResults = (int) $data['totalResults'];
            }

            if (
                null !== $totalResults
                && $collected >= $totalResults
            ) {
                break;
            }

            if (count($resources) < $pageSize) {
                break;
            }

            $page += 1;
            // The source's list view derives the page number from startIndex divided by the
            // page size (and ignores count), so page N is reached with startIndex N times the
            // page size, not the index of its first item: startIndex=21 would be page 1 again.
            $startIndex = $page * $pageSize;
        }
    }

    /**
     * @template T of object
     *
     * @param callable(array<string, mixed>): T $toValueObject
     *
     * @return T|null
     */
    private function firstResource(
        string $path,
        callable $toValueObject,
    ): ?object {
        $data = $this->request(
            'GET',
            $path,
        );
        $resources = $data['Resources'] ?? [];
        if (
            !is_array($resources)
            || [] === $resources
        ) {
            return null;
        }

        $first = $resources[array_key_first($resources)];

        return is_array($first)
            ? $toValueObject($first)
            : null;
    }

    private function findUserByName(string $userName): ?ScimUser
    {
        // Only userName is filterable on users (findings §5): the source answers 500 with an HTML
        // page for every other attribute.
        if ($this->filterSafe($userName)) {
            $filter = sprintf(
                'userName eq "%s"',
                $userName,
            );
            $path = '/Users?filter=' . rawurlencode($filter);

            return $this->firstResource(
                $path,
                fn (array $data): ScimUser => $this->toScimUser($data),
            );
        }

        // A value with a quote, a backslash or a control character cannot be embedded in the
        // filter expression: the grammar of the pinned version has no verified escape rules.
        // Match the full list instead. In practice userName is always `m` plus the member
        // number, so the scan runs only when the invariant changes.
        foreach ($this->listUsers() as $user) {
            if ($userName === $user->userName) {
                return $user;
            }
        }

        return null;
    }

    private function findGroupByName(string $displayName): ?ScimGroup
    {
        // displayName is the group attribute the source correlates on (findings §5, §11).
        if ($this->filterSafe($displayName)) {
            $filter = sprintf(
                'displayName eq "%s"',
                $displayName,
            );
            $path = '/Groups?filter=' . rawurlencode($filter);

            return $this->firstResource(
                $path,
                fn (array $data): ScimGroup => $this->toScimGroup($data),
            );
        }

        // The same escape concern as for users: match the full list instead.
        foreach ($this->listGroups() as $group) {
            if ($displayName === $group->displayName) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Whether the value can be embedded in a filter expression as `attr eq "value"`.
     */
    private function filterSafe(string $value): bool
    {
        return 0 === preg_match(
            '/[\x00-\x1f"\\\\]/',
            $value,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toScimUser(array $data): ScimUser
    {
        $emails = [];
        foreach ($data['emails'] ?? [] as $email) {
            if (
                !is_array($email)
                || !isset($email['value'])
                || !is_string($email['value'])
            ) {
                continue;
            }

            $emails[] = new ScimEmail(
                value: $email['value'],
                primary: (bool) ($email['primary'] ?? false),
                type: isset($email['type']) && is_string($email['type']) ? $email['type'] : null,
            );
        }

        $name = is_array($data['name'] ?? null)
            ? $data['name']
            : [];

        return new ScimUser(
            userName: is_string($data['userName'] ?? null) ? $data['userName'] : '',
            externalId: is_string($data['externalId'] ?? null) ? $data['externalId'] : null,
            givenName: is_string($name['givenName'] ?? null) ? $name['givenName'] : null,
            familyName: is_string($name['familyName'] ?? null) ? $name['familyName'] : null,
            formattedName: is_string($name['formatted'] ?? null) ? $name['formatted'] : null,
            emails: $emails,
            active: (bool) ($data['active'] ?? true),
            remoteId: is_string($data['id'] ?? null) ? $data['id'] : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toScimGroup(array $data): ScimGroup
    {
        $members = [];
        foreach ($data['members'] ?? [] as $member) {
            if (
                !is_array($member)
                || !isset($member['value'])
                || !is_string($member['value'])
            ) {
                continue;
            }

            $members[] = $member['value'];
        }

        return new ScimGroup(
            displayName: is_string($data['displayName'] ?? null) ? $data['displayName'] : '',
            externalId: is_string($data['externalId'] ?? null) ? $data['externalId'] : null,
            memberRemoteIds: $members,
            remoteId: is_string($data['id'] ?? null) ? $data['id'] : null,
        );
    }

    /**
     * @param list<ScimOperation> $operations
     *
     * @return list<array<string, mixed>>
     */
    private function operationsToArray(array $operations): array
    {
        return array_map(
            static fn (ScimOperation $operation): array => $operation->toArray(),
            $operations,
        );
    }

    private function userPath(string $remoteId): string
    {
        return '/Users/' . rawurlencode($remoteId);
    }

    private function groupPath(string $remoteId): string
    {
        return '/Groups/' . rawurlencode($remoteId);
    }

    private function retryAfterMs(ResponseInterface $response): ?int
    {
        $header = $response->getHeaders()['retry-after'][0] ?? '';
        if ('' === $header) {
            return null;
        }

        if (is_numeric($header)) {
            return (int) floor((float) $header * 1000);
        }

        $date = DateTimeImmutable::createFromFormat(
            DateTimeImmutable::RFC2822,
            $header,
        );
        if (false === $date) {
            return null;
        }

        return max(
            0,
            $date->getTimestamp() - time(),
        ) * 1000;
    }
}
