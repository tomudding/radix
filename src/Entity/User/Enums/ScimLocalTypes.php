<?php

declare(strict_types=1);

namespace App\Entity\User\Enums;

/**
 * The kind of local object a {@see \App\Entity\User\ScimRemoteLink} tracks.
 *
 * Bodies, boards and statuses are separate kinds although all three map to SCIM groups: a body
 * group is one body (keyed by foundation hash), a board group is a fixed drop like
 * BOARD_Current, and a status group is one membership type or the classes together, so the link
 * table must tell them apart to reconcile all of them.
 */
enum ScimLocalTypes: string
{
    case User = 'user';
    case Body = 'body';
    case Board = 'board';
    case Status = 'status';
}
