<?php

namespace App\Enums;

enum UserRoleEnum: string
{
    case Admin = 'admin';
    case Viewer = 'viewer';

    public function canManage(): bool
    {
        return $this === self::Admin;
    }
}
