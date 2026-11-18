<?php

declare(strict_types=1);

namespace App\Entity\User\Enums;

/**
 * The kind of local object a {@see \App\Entity\User\ScimRemoteLink} tracks.
 */
enum ScimLocalTypes: string
{
    case User = 'user';
    case Group = 'group';
}
