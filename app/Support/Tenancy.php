<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Small static helper for the few places that need "which gym is this?"
 * outside of Eloquent's automatic GymScope (e.g. raw DB::table() queries,
 * or a BelongsToGym model's creating() hook filling in gym_id).
 */
class Tenancy
{
    public static function gymId(): ?int
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        return $user->gym_id ?? null;
    }

    public static function isSuperAdmin(): bool
    {
        $user = Auth::user();

        return (bool) $user && ($user->role ?? null) === 'SuperAdmin';
    }
}
