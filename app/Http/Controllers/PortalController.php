<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Backs the unified FitGym entry page (Admin / Trainer / Member in one door).
 *
 * The per-role login endpoints stay where they are; this controller only
 * answers "who is already signed in, and which portal do they belong to?".
 */
class PortalController extends Controller
{
    /**
     * Role => portal path. Keep in sync with the role list in fitgym/Portal.vue.
     */
    public const PORTALS = [
        'Admin' => '/fitgym/admin',
        'Staff' => '/fitgym/staff',
        'Member' => '/fitgym/member',
    ];

    /**
     * Always answers 200 so the axios 401 handler never bounces the entry page
     * to Fortify's /login. "Not signed in" is a normal answer here, not an error.
     */
    public function session(Request $request)
    {
        if (! Auth::check()) {
            return response()->json(['success' => true, 'authenticated' => false]);
        }

        $user = Auth::user();

        if (isset($user->is_archived) && $user->is_archived) {
            $this->forgetSession($request);

            return response()->json([
                'success' => true,
                'authenticated' => false,
                'message' => 'Your account has been deactivated. Please contact support.',
            ]);
        }

        $role = $user->role ?? null;

        if ($role === null || ! isset(self::PORTALS[$role])) {
            $this->forgetSession($request);

            return response()->json(['success' => true, 'authenticated' => false]);
        }

        $gym = $user->gym_id ? Gym::find($user->gym_id) : null;

        if ($gym && ! $gym->allowsAccess()) {
            $this->forgetSession($request);

            return response()->json([
                'success' => true,
                'authenticated' => false,
                'message' => $gym->accessDeniedMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role,
            ],
            'gym' => $gym ? ['name' => $gym->name, 'slug' => $gym->slug] : null,
            'redirect' => self::PORTALS[$role],
        ]);
    }

    /**
     * Sign out from the entry page so another role can take over the browser.
     */
    public function logout(Request $request)
    {
        $this->forgetSession($request);

        return response()->json([
            'success' => true,
            'message' => 'Signed out.',
            'csrf_token' => csrf_token(),
        ]);
    }

    private function forgetSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
