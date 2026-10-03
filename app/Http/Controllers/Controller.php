<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * The 403 to return when the user's gym branch is suspended, cancelled or
     * expired, or null when the branch may be used.
     */
    protected function blockedGymResponse($user): ?JsonResponse
    {
        if (! $user || empty($user->gym_id)) {
            return null;
        }

        $gym = Gym::find($user->gym_id);
        if ($gym && $gym->allowsAccess()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'code' => 'gym_inactive',
            'message' => $gym?->accessDeniedMessage() ?? 'This gym branch no longer exists.',
        ], 403);
    }

    /**
     * The 422 to return when the branch's FitGym plan has no room for another
     * member or staff account ($resource = 'members' | 'staff'), or null.
     */
    protected function planLimitResponse(?Gym $gym, string $resource): ?JsonResponse
    {
        if (! $gym || $gym->hasRoomFor($resource)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'code' => 'plan_limit',
            'message' => $gym->limitReachedMessage($resource),
        ], 422);
    }

    /**
     * Public member sign-up: the chosen gym must be open and have room.
     */
    protected function registrationClosedResponse(?Gym $gym): ?JsonResponse
    {
        if ($gym && $gym->allowsAccess() && $gym->hasRoomFor('members')) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'This gym is not accepting new member sign-ups right now. Please contact the gym directly.',
        ], 422);
    }
}
