<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scim;

use App\Service\Scim\ScimEmail;
use App\Service\Scim\ScimGroup;
use App\Service\Scim\ScimOperation;
use App\Service\Scim\ScimUser;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bridge\PhpUnit\Attribute\DnsSensitive;

use function array_map;
use function array_values;
use function is_array;

/**
 * The full SCIM lifecycle against a real, throwaway source: create, update, group membership
 * both ways, disable and delete — plus adoption on a duplicate create and the safety baseline
 * around the source's own admin account.
 *
 * `DnsSensitive` lifts the bridge's DNS mock for the `App` namespace, because these tests make
 * real requests against the development source.
 */
#[Group('authentik')]
#[DnsSensitive]
final class AuthentikLifecycleTest extends AuthentikIntegrationTestCase
{
    public function testTheFullLifecycle(): void
    {
        $created = $this->client->createUser(
            new ScimUser(
                userName: 'm99001',
                externalId: '99001',
                givenName: 'Contract',
                familyName: 'Lifecycle',
                emails: [
                    new ScimEmail(
                        'lifecycle@example.com',
                        true,
                        'work',
                    ),
                ],
            ),
        );
        self::assertNotNull($created->remoteId);

        $updated = $this->client->updateUser(
            $created->remoteId,
            new ScimUser(
                userName: 'm99001',
                externalId: '99001',
                givenName: 'Contract',
                familyName: 'Updated',
                emails: [
                    new ScimEmail(
                        'lifecycle-updated@example.com',
                        true,
                        'work',
                    ),
                ],
            ),
        );
        self::assertSame(
            $created->remoteId,
            $updated->remoteId,
        );

        $readBack = $this->findUserByExternalId('99001');
        self::assertNotNull($readBack);
        self::assertSame(
            'Updated',
            $readBack->familyName,
        );
        self::assertCount(
            1,
            $readBack->emails,
        );
        self::assertSame(
            'lifecycle-updated@example.com',
            $readBack->emails[0]->value,
        );

        $group = $this->client->createGroup(
            new ScimGroup(
                displayName: 'SCIM CI Group',
                externalId: 'ci-integration-group',
            ),
        );
        self::assertNotNull($group->remoteId);

        $joined = $this->client->patchGroupMembers(
            $group->remoteId,
            [$created->remoteId],
            [],
        );
        self::assertSame(
            [$created->remoteId],
            $joined->memberRemoteIds,
        );

        $left = $this->client->patchGroupMembers(
            $group->remoteId,
            [],
            [$created->remoteId],
        );
        self::assertSame(
            [],
            $left->memberRemoteIds,
        );

        $this->client->patchUser(
            $created->remoteId,
            [
                ScimOperation::replace(
                    'active',
                    false,
                ),
            ],
        );
        $disabled = $this->findUserByExternalId('99001');
        self::assertNotNull($disabled);
        self::assertFalse($disabled->active);

        $this->client->deleteUser($created->remoteId);
        self::assertNull($this->findUserByExternalId('99001'));

        $this->client->deleteGroup($group->remoteId);
        self::assertNull($this->findGroupByExternalId('ci-integration-group'));
    }

    public function testACreateWithTheSameUserNameAdoptsTheExistingUser(): void
    {
        $first = $this->client->createUser(
            new ScimUser(
                userName: 'm99002',
                externalId: '99002',
                givenName: 'Contract',
                familyName: 'Adoption',
                emails: [
                    new ScimEmail(
                        'adoption@example.com',
                        true,
                        'work',
                    ),
                ],
            ),
        );
        $again = $this->client->createUser(
            new ScimUser(
                userName: 'm99002',
                externalId: '99002',
                givenName: 'Contract',
                familyName: 'Adoption',
                emails: [
                    new ScimEmail(
                        'adoption-2@example.com',
                        true,
                        'work',
                    ),
                ],
            ),
        );

        self::assertSame(
            $first->remoteId,
            $again->remoteId,
        );

        $readBack = $this->findUserByExternalId('99002');
        self::assertNotNull($readBack);
        self::assertCount(
            1,
            $readBack->emails,
        );
        self::assertSame(
            'adoption-2@example.com',
            $readBack->emails[0]->value,
        );

        $this->client->deleteUser((string) $first->remoteId);
    }

    public function testTheBootstrapAdminAndItsGroupAreUntouched(): void
    {
        $adminBefore = $this->adminSnapshot();
        $adminsBefore = $this->adminsGroupMembers();

        // A name that imitates the admin account. The mapper's scheme never produces it, and
        // the source must keep it apart from the real admin through both the create and the
        // delete.
        $probe = $this->client->createUser(
            new ScimUser(
                userName: 'm-probe-admin',
                givenName: 'Probe',
                familyName: 'Admin',
                emails: [
                    new ScimEmail(
                        'probe-admin@example.com',
                        true,
                        'work',
                    ),
                ],
            ),
        );
        $this->client->deleteUser((string) $probe->remoteId);

        self::assertSame(
            $adminBefore,
            $this->adminSnapshot(),
        );
        self::assertSame(
            $adminsBefore,
            $this->adminsGroupMembers(),
        );
    }

    /**
     * The bootstrap admin's fields a collision must never change.
     *
     * @return array{uuid: string, username: string, is_active: bool}
     */
    private function adminSnapshot(): array
    {
        $data = $this->adminApi('/core/users/?username=akadmin');
        $results = is_array($data['results'] ?? null)
            ? $data['results']
            : [];
        self::assertCount(
            1,
            $results,
        );

        return [
            'uuid' => (string) $results[0]['uuid'],
            'username' => (string) $results[0]['username'],
            'is_active' => (bool) $results[0]['is_active'],
        ];
    }

    /**
     * The members of the authentik Admins group, by id.
     *
     * @return list<string>
     */
    private function adminsGroupMembers(): array
    {
        $data = $this->adminApi('/core/groups/?name=authentik+Admins');
        $results = is_array($data['results'] ?? null)
            ? $data['results']
            : [];
        self::assertCount(
            1,
            $results,
        );

        $users = is_array($results[0]['users'] ?? null)
            ? $results[0]['users']
            : [];

        return array_values(
            array_map(
                static fn (mixed $member): string => (string) $member,
                $users,
            ),
        );
    }
}
