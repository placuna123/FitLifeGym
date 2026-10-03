<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Public "start your gym's free trial" flow — the SaaS onboarding path that
 * didn't exist at all before multi-tenancy: it creates a brand-new Gym
 * tenant plus its first Admin user, atomically.
 */
class GymSignupController extends Controller
{
    /**
     * Non-suspended gyms, for the Member self-registration gym picker.
     * (Member registration has no "current gym" yet — the registrant has to
     * say which gym they're joining.)
     */
    public function publicList()
    {
        $gyms = Gym::where('is_archived', 0)
            ->whereNotIn('subscription_status', ['suspended', 'cancelled'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Gym $gym) => $gym->allowsAccess())
            ->map(fn (Gym $gym) => ['id' => $gym->id, 'name' => $gym->name, 'slug' => $gym->slug])
            ->values();

        return response()->json([
            'success' => true,
            'gyms' => $gyms,
        ]);
    }

    public function store(Request $request)
    {
        $normalizedEmail = strtolower(trim((string) $request->input('admin_email', '')));
        $normalizedPassword = trim((string) $request->input('admin_password', ''));
        $request->merge([
            'admin_email' => $normalizedEmail,
            'admin_password' => $normalizedPassword,
        ]);

        $validator = Validator::make($request->all(), [
            'gym_name' => 'required|string|max:255',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $normalizedEmail, $normalizedPassword) {
                $slug = $this->generateUniqueSlug($request->gym_name);

                $gym = Gym::create([
                    'name' => $request->gym_name,
                    'slug' => $slug,
                    'subscription_plan' => 'trial',
                    'subscription_status' => 'trial',
                    'subscription_expires_at' => now()->addDays((int) config('fitgym.trial_days', 14))->toDateString(),
                    'is_archived' => 0,
                ]);

                $user = User::create([
                    'name' => $request->admin_name,
                    'email' => $normalizedEmail,
                    'password' => Hash::make($normalizedPassword),
                    'role' => 'Admin',
                    'gym_id' => $gym->id,
                    'is_archived' => 0,
                ]);

                $gym->update(['owner_user_id' => $user->id]);

                // No one is authenticated yet at signup time, so log this
                // explicitly rather than via AuditLog::record()'s auth()->user().
                \App\Models\AuditLog::create([
                    'gym_id' => $gym->id,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'role' => 'Admin',
                    'action' => 'gym.signed_up',
                    'subject_type' => 'Gym',
                    'subject_id' => $gym->id,
                    'description' => "{$user->name} self-registered gym {$gym->name}",
                    'ip_address' => $request->ip(),
                ]);

                return $gym;
            });

            return response()->json([
                'success' => true,
                'message' => 'Your gym is ready! Your free trial runs until ' . $result->subscription_expires_at?->format('M d, Y') . '. You can now sign in as its Admin.',
                'gym' => [
                    'id' => $result->id,
                    'name' => $result->name,
                    'slug' => $result->slug,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create gym: ' . $e->getMessage()
            ], 500);
        }
    }

    private function generateUniqueSlug(string $gymName): string
    {
        $base = Str::slug($gymName) ?: 'gym';
        $slug = $base;
        $suffix = 1;

        while (Gym::where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
