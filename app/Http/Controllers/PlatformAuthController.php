<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Auth for the platform-level Super Admin, who manages gyms/subscriptions
 * rather than any single gym's data. Deliberately not exposed on the shared
 * fitgym/Portal.vue role-selector — reachable only via a direct URL.
 */
class PlatformAuthController extends Controller
{
    public function login(Request $request)
    {
        $normalizedEmail = strtolower(trim((string) $request->input('email', '')));
        $normalizedPassword = trim((string) $request->input('password', ''));
        $request->merge([
            'email' => $normalizedEmail,
            'password' => $normalizedPassword,
        ]);

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $credentials = ['email' => $normalizedEmail, 'password' => $normalizedPassword];
        $remember = $request->boolean('remember');

        if (Auth::attempt($credentials, $remember)) {
            $user = Auth::user();

            if (isset($user->is_archived) && $user->is_archived) {
                Auth::logout();
                return response()->json([
                    'success' => false,
                    'message' => 'Your account has been deactivated.'
                ], 403);
            }

            if (($user->role ?? null) !== 'SuperAdmin') {
                Auth::logout();
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Super Admin account required.'
                ], 403);
            }

            $request->session()->regenerate();

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid email or password.'
        ], 401);
    }

    public function user(Request $request)
    {
        if (! Auth::check()) {
            return response()->json(['success' => false, 'message' => 'Not authenticated'], 401);
        }

        $user = Auth::user();
        if (($user->role ?? null) !== 'SuperAdmin') {
            return response()->json(['success' => false, 'message' => 'Not a super admin account'], 403);
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]
        ]);
    }

    public function logout(Request $request)
    {
        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['success' => true, 'message' => 'Logged out successfully']);
    }
}
