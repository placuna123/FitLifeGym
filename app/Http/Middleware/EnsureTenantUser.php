<?php

namespace App\Http\Middleware;

use App\Models\Gym;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for branch (gym) data routes: the signed-in user must belong to a gym.
 *
 * Without this, a SuperAdmin session (gym_id null, unscoped by GymScope) that
 * replaced a branch admin's session in another tab of the same browser could
 * read every branch's data through the branch portal, and create records with
 * no gym_id at all.
 */
class EnsureTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || empty($user->gym_id)) {
            return response()->json([
                'success' => false,
                'code' => 'no_gym_session',
                'message' => 'This page needs a gym branch account. Please sign in again with your branch account.',
            ], 403);
        }

        // A suspended, cancelled or expired branch loses access to its data.
        $gym = Gym::find($user->gym_id);
        if (! $gym || ! $gym->allowsAccess()) {
            return response()->json([
                'success' => false,
                'code' => 'gym_inactive',
                'message' => $gym?->accessDeniedMessage() ?? 'This gym branch no longer exists.',
            ], 403);
        }

        return $next($request);
    }
}
