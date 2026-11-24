<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Scim;

use App\Service\Scim\ScimClientInterface;
use App\Service\Scim\ScimGroup;
use App\Service\Scim\ScimOperation;
use App\Service\Scim\ScimUser;
use Throwable;

/**
 * An in-memory stand-in for the SCIM source for handler tests.
 *
 * It records every call; each call's outcome is configurable, so a test can force the
 * not-found or failure path.
 */
final class FakeScimClient implements ScimClientInterface
{
    /** @var list<string> */
    public array $calls = [];

    public ?string $nextUserId = 'user-remote-1';
    public ?string $nextGroupId = 'group-remote-1';

    public ?Throwable $createUserFailure = null;
    public ?Throwable $updateUserFailure = null;
    public ?Throwable $createGroupFailure = null;
    public ?Throwable $updateGroupFailure = null;
    public ?Throwable $patchGroupMembersFailure = null;

    /**
     * What the source reports as a group's current membership on a read.
     *
     * @var list<string>
     */
    public array $reportedMembership = [];

    /** @var list<ScimUser> */
    public array $createdUsers = [];

    /** @var list<ScimGroup> */
    public array $createdGroups = [];

    /**
     * The membership patches applied, in order.
     *
     * @var list<array{0: list<string>, 1: list<string>}>
     */
    public array $patches = [];

    /**
     * What the source reports when its user list is read.
     *
     * @var list<ScimUser>
     */
    public array $listedUsers = [];

    /**
     * What the source reports when its group list is read.
     *
     * @var list<ScimGroup>
     */
    public array $listedGroups = [];

    /**
     * The PATCHes applied to users, in order.
     *
     * @var list<array{0: string, 1: list<ScimOperation>}>
     */
    public array $userPatches = [];

    /**
     * The remote ids passed to the deletes, in order.
     *
     * @var list<string>
     */
    public array $deletedUsers = [];

    /**
     * The remote ids passed to the deletes, in order.
     *
     * @var list<string>
     */
    public array $deletedGroups = [];

    public function createUser(ScimUser $user): ScimUser
    {
        $this->calls[] = 'createUser';
        if (null !== $this->createUserFailure) {
            throw $this->createUserFailure;
        }

        $this->createdUsers[] = $user;

        return new ScimUser(
            userName: $user->userName,
            externalId: $user->externalId,
            givenName: $user->givenName,
            familyName: $user->familyName,
            formattedName: $user->formattedName,
            emails: $user->emails,
            active: $user->active,
            remoteId: $this->nextUserId,
        );
    }

    public function updateUser(
        string $remoteId,
        ScimUser $user,
    ): ScimUser {
        $this->calls[] = 'updateUser';
        if (null !== $this->updateUserFailure) {
            throw $this->updateUserFailure;
        }

        return new ScimUser(
            userName: $user->userName,
            externalId: $user->externalId,
            givenName: $user->givenName,
            familyName: $user->familyName,
            formattedName: $user->formattedName,
            emails: $user->emails,
            active: $user->active,
            remoteId: $remoteId,
        );
    }

    /**
     * @param list<ScimOperation> $operations
     */
    public function patchUser(
        string $remoteId,
        array $operations,
    ): ScimUser {
        $this->calls[] = 'patchUser';
        $this->userPatches[] = [
            $remoteId,
            $operations,
        ];

        return new ScimUser(
            userName: '',
            active: false,
            remoteId: $remoteId,
        );
    }

    public function deleteUser(string $remoteId): void
    {
        $this->deletedUsers[] = $remoteId;
    }

    /**
     * @return iterable<ScimUser>
     */
    public function listUsers(): iterable
    {
        $this->calls[] = 'listUsers';

        return $this->listedUsers;
    }

    public function createGroup(ScimGroup $group): ScimGroup
    {
        $this->calls[] = 'createGroup';
        if (null !== $this->createGroupFailure) {
            throw $this->createGroupFailure;
        }

        $this->createdGroups[] = $group;

        return new ScimGroup(
            displayName: $group->displayName,
            externalId: $group->externalId,
            memberRemoteIds: $group->memberRemoteIds,
            remoteId: $this->nextGroupId,
        );
    }

    public function updateGroup(
        string $remoteId,
        ScimGroup $group,
    ): ScimGroup {
        $this->calls[] = 'updateGroup';
        if (null !== $this->updateGroupFailure) {
            throw $this->updateGroupFailure;
        }

        return new ScimGroup(
            displayName: $group->displayName,
            externalId: $group->externalId,
            memberRemoteIds: $group->memberRemoteIds,
            remoteId: $remoteId,
        );
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
        $this->calls[] = 'patchGroupMembers';
        if (null !== $this->patchGroupMembersFailure) {
            throw $this->patchGroupMembersFailure;
        }

        if (
            [] !== $addRemoteIds
            || [] !== $removeRemoteIds
        ) {
            $this->patches[] = [
                $addRemoteIds,
                $removeRemoteIds,
            ];
        }

        return new ScimGroup(
            displayName: '',
            memberRemoteIds: $this->reportedMembership,
        );
    }

    public function deleteGroup(string $remoteId): void
    {
        $this->deletedGroups[] = $remoteId;
    }

    /**
     * @return iterable<ScimGroup>
     */
    public function listGroups(): iterable
    {
        $this->calls[] = 'listGroups';

        return $this->listedGroups;
    }
}
