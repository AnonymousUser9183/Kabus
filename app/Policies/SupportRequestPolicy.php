<?php

/*
 | *--------------------------------------------------------------------------
 | Copyright Notice
 |--------------------------------------------------------------------------
 | Updated for Laravel 13.4.0 by AnonymousUser9183 / The Erebus Development Team.
 | Original Kabus Marketplace Script created by Sukunetsiz.
 |--------------------------------------------------------------------------
 */

namespace App\Policies;

use App\Models\SupportRequest;
use App\Models\User;

class SupportRequestPolicy
{
    public function view(User $user, SupportRequest $supportRequest): bool
    {
        return $user->id === $supportRequest->user_id || $user->isAdmin();
    }

    public function reply(User $user, SupportRequest $supportRequest): bool
    {
        return $user->id === $supportRequest->user_id || $user->isAdmin();
    }
}
