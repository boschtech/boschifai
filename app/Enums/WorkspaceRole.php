<?php

namespace App\Enums;

/**
 * A user's role in one workspace. Members do the day-to-day work (submit, review, approve,
 * retry, cancel runs); admins also manage the workspace's repositories and GitHub connections
 * and can delete runs; owners can do everything admins can (ownership-only actions such as
 * deleting the workspace come later).
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function canManageWorkspace(): bool
    {
        return $this !== self::Member;
    }
}
