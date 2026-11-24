<?php

declare(strict_types=1);

namespace App\Entity\User\Enums;

/**
 * The kind of local object a {@see \App\Entity\User\ScimRemoteLink} tracks.
 *
 * Bodies, boards, statuses and body functions are separate kinds although all map to SCIM groups:
 * a body group is one body (keyed by its foundation hash, with the `:inactive` variant for a
 * fraternity's inactive members), a board group is a fixed drop like BOARD_Current, a status
 * group is one membership type or the classes together, and a body function group is one
 * officeholder function within a body. The link table must tell them apart to reconcile all of
 * them.
 */
enum ScimLocalTypes: string
{
    case User = 'user';
    case Body = 'body';
    case Board = 'board';
    case Status = 'status';
    case BodyFunction = 'body_function';
}
