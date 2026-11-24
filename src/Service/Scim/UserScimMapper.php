<?php

declare(strict_types=1);

namespace App\Service\Scim;

use function trim;

/**
 * Maps a local member to the SCIM user it becomes. Pure: the same input always yields the same
 * user, and nothing here touches an entity manager.
 */
final readonly class UserScimMapper
{
    /**
     * The payload a member who is no longer in the register is left with: the account is
     * deactivated, never deleted, so a link can be kept and a later re-creation adopts it.
     */
    public function deactivated(int $lidnr): ScimUser
    {
        return new ScimUser(
            userName: 'm' . $lidnr,
            externalId: (string) $lidnr,
            active: false,
        );
    }

    public function map(UserScimSource $source): ScimUser
    {
        $emails = [];
        if (
            null !== $source->email
            && '' !== $source->email
        ) {
            $emails = [
                new ScimEmail(
                    $source->email,
                    primary: true,
                    type: 'work',
                ),
            ];
        }

        return new ScimUser(
            // The `m` prefix keeps every pushed name clear of the Authentik's own users, which
            // the source would otherwise adopt and rewrite (findings §12, §14).
            userName: 'm' . $source->lidnr,
            externalId: $source->lidnr,
            givenName: $source->firstName,
            familyName: $source->lastName,
            formattedName: trim($source->firstName . ' ' . $source->lastName),
            emails: $emails,
            active: $source->active,
        );
    }
}
