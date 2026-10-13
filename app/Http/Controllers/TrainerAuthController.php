<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TrainerAuthController extends Controller
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
        $credentials = [
            'email' => $normalizedEmail,
            'password' => $normalizedPassword,
        ];
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
            // Require role to be exactly "Staff".
            // Don't allow "null" or missing role to pass.
            if (($user->role ?? null) !== 'Staff') {
                Auth::logout();
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Staff account required.'
                ], 403);
            }
            if ($blocked = $this->blockedGymResponse($user)) {
                Auth::logout();
                return $blocked;
            }
            $request->session()->regenerate();
            \App\Models\AuditLog::record('auth.login', "Staff {$user->name} logged in");
            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role ?? 'Staff',
                    'photo_url' => $user->profilePhotoUrl(),
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
        if (!Auth::check()) {
            return response()->json(['success' => false, 'message' => 'Not authenticated'], 401);
        }
        $user = Auth::user();
        // Require role to be exactly "Staff" for the portal.
        if (($user->role ?? null) !== 'Staff') {
            return response()->json(['success' => false, 'message' => 'Not a staff account'], 403);
        }
        if ($blocked = $this->blockedGymResponse($user)) {
            Auth::logout();
            return $blocked;
        }
        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'Staff',
                'photo_url' => $user->profilePhotoUrl(),
            ]
        ]);
    }

    public function logout(Request $request)
    {
        try {
            if (Auth::check()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            return response()->json(['success' => true, 'message' => 'Logged out successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => true, 'message' => 'Logged out successfully']);
        }
    }
}
