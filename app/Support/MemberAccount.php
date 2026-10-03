<?php

namespace App\Support;

use App\Models\Gym;
use App\Models\User;
use App\Notifications\AccountInvite;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Login accounts for members added by Admin/Staff (self-registered members
 * already create their own). Without one, such members could never sign in.
 */
class MemberAccount
{
    /** Why this email can't be used for a member of $gymId, or null if it can. */
    public static function emailConflict(string $email, int $gymId): ?string
    {
        $user = User::where('email', self::normalize($email))->first();

        if (! $user || ($user->role === 'Member' && (int) $user->gym_id === $gymId)) {
            return null;
        }

        return 'This email is already used by another FitGym account. Please use a different email.';
    }

    /** Creates the member's login if it doesn't exist yet; returns it only when newly created. */
    public static function createLogin(string $name, string $email, int $gymId): ?User
    {
        if (User::where('email', self::normalize($email))->exists()) {
            return null;
        }

        return User::create([
            'name' => $name,
            'email' => self::normalize($email),
            // Unusable until they set their own through the emailed link.
            'password' => Hash::make(Str::random(40)),
            'role' => 'Member',
            'gym_id' => $gymId,
            'is_archived' => 0,
        ]);
    }

    /** Emails a newly created member login its "set your password" link. */
    public static function invite(?User $user, int $gymId): void
    {
        if (! $user) {
            return;
        }

        Email::send($user, new AccountInvite('Member', Gym::find($gymId)?->name ?? 'your gym', Email::setPasswordUrl($user)));
    }

    private static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
