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
