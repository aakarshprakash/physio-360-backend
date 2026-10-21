<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signs a staff member out everywhere: mobile tokens, database web sessions
 * and "remember me" cookies. Session rows are keyed by the globally unique
 * user id, so the raw table access needs no tenant filter.
 */
final class SessionRevoker
{
    public static function everywhere(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->setRememberToken(Str::random(60));
        $user->saveQuietly();
    }
}
