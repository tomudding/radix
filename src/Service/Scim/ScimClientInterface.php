<?php

declare(strict_types=1);

namespace App\Service\Scim;

/**
 * The contract an outbound SCIM 2.0 client fulfils. A method fails with the {@see ScimException}
 * of the request that failed; delivery, retries and the failure transport are the concern of the
 * Messenger layer above. The remote identifiers are the SCIM source's own ids.
 */
interface ScimClientInterface
{
    public function createUser(ScimUser $user): ScimUser;

    public function updateUser(
        string $remoteId,
        ScimUser $user,
    ): ScimUser;

    /**
     * @param list<ScimOperation> $operations
     */
    public function patchUser(
        string $remoteId,
        array $operations,
    ): ScimUser;

    public function deleteUser(string $remoteId): void;

    /**
     * @return iterable<ScimUser>
     */
    public function listUsers(): iterable;

    public function createGroup(ScimGroup $group): ScimGroup;

    public function updateGroup(
        string $remoteId,
        ScimGroup $group,
    ): ScimGroup;

    /**
     * @param list<string> $addRemoteIds
     * @param list<string> $removeRemoteIds
     */
    public function patchGroupMembers(
        string $remoteId,
        array $addRemoteIds,
        array $removeRemoteIds,
    ): ScimGroup;

    public function deleteGroup(string $remoteId): void;

    /**
     * @return iterable<ScimGroup>
     */
    public function listGroups(): iterable;
}
