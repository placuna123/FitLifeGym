<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
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
        // gyms whose owner has not confirmed their email yet are not open to members
        $unconfirmedOwners = User::whereNull('email_verified_at')->where('role', 'Admin')->pluck('id');

        $gyms = Gym::where('is_archived', 0)
            ->whereNotIn('subscription_status', ['suspended', 'cancelled'])
            ->where(fn ($q) => $q->whereNull('owner_user_id')->orWhereNotIn('owner_user_id', $unconfirmedOwners))
            ->orderBy('name')
            ->get()
            ->filter(fn (Gym $gym) => $gym->allowsAccess())
            ->values();

        // What a would-be member sees before picking a branch. Plain queries, not the
        // gym-scoped models: nobody is signed in here, so there is no current gym.
        $ids = $gyms->pluck('id');
        $plans = DB::table('membership_plans')
            ->whereIn('gym_id', $ids)
            ->where('is_deactivated', 0)
            ->where('is_archived', 0)
            ->orderBy('price')
            ->get(['gym_id', 'name', 'price', 'period'])
            ->groupBy('gym_id');
        $staffCounts = DB::table('trainers')
            ->whereIn('gym_id', $ids)
            ->where('is_archived', 0)
            ->selectRaw('gym_id, count(*) as total')
            ->groupBy('gym_id')
            ->pluck('total', 'gym_id');
        $classes = DB::table('gym_classes')
            ->whereIn('gym_id', $ids)
            ->where('is_archived', 0)
            ->get(['gym_id', 'name'])
            ->groupBy('gym_id');

        $gyms = $gyms->map(fn (Gym $gym) => [
            'id' => $gym->id,
            'name' => $gym->name,
            'slug' => $gym->slug,
            'description' => $gym->description ?? '',
            'cover_photo_url' => $gym->publicFileUrl($gym->cover_photo_path),
            'address' => $gym->address ?? '',
            'phone' => $gym->phone ?? '',
            'email' => $gym->email ?? '',
            'operating_hours' => $gym->operating_hours ?: [],
            'plans' => ($plans[$gym->id] ?? collect())->map(fn ($p) => [
                'name' => $p->name,
                'price' => (float) $p->price,
                'period' => $p->period,
            ])->values(),
            'staff_count' => (int) ($staffCounts[$gym->id] ?? 0),
            'classes' => ($classes[$gym->id] ?? collect())->pluck('name')->unique()->values(),
        ]);

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

            // The owner proves the email is theirs before the gym opens; the welcome
            // email, the trial and the Super Admin notice all wait for that.
            $owner = User::find($result->owner_user_id);
            $sent = $this->sendVerificationEmail($owner, $result);

            return response()->json([
                'success' => true,
                'needs_verification' => true,
                'email_sent' => $sent,
                'message' => $sent
                    ? "Almost done! We sent a confirmation link to {$normalizedEmail}. Open it to confirm your email, then sign in as Admin."
                    : "Your gym was created, but the confirmation email could not be sent to {$normalizedEmail}. Use \"Resend confirmation email\" below.",
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

    /** How long a confirmation link works. */
    private const VERIFY_LINK_HOURS = 48;

    /**
     * The link in the confirmation email. Signed, so it cannot be guessed or
     * changed, and it carries a hash of the email so it stops working if the
     * address is changed.
     */
    private function sendVerificationEmail(?User $owner, Gym $gym): bool
    {
        if (! $owner) {
            return false;
        }

        $url = URL::temporarySignedRoute(
            'gyms.verify-email',
            now()->addHours(self::VERIFY_LINK_HOURS),
            ['id' => $owner->id, 'hash' => sha1(strtolower($owner->email))],
        );

        return \App\Support\Email::send($owner, new \App\Notifications\VerifyGymEmail($gym->name, $url, self::VERIFY_LINK_HOURS));
    }

    /**
     * The owner opened the link: mark the email as confirmed, start the free
     * trial from today, send the welcome email and tell the Super Admin.
     * Then back to the sign-in page with a message.
     */
    public function verifyEmail(Request $request, int $id, string $hash)
    {
        // checked here, not by the "signed" middleware, so an old link gets a
        // clear message on the sign-in page instead of a bare error page
        if (! $request->hasValidSignature()) {
            return redirect('/?role=admin&reason=verify-expired');
        }

        $user = User::find($id);

        if (! $user || ! hash_equals(sha1(strtolower($user->email)), $hash)) {
            return redirect('/?role=admin&reason=verify-invalid');
        }

        if ($user->email_verified_at) {
            return redirect('/?role=admin&reason=verified');
        }

        $gym = $user->gym_id ? Gym::find($user->gym_id) : null;

        DB::transaction(function () use ($user, $gym) {
            $user->forceFill(['email_verified_at' => now()])->save();

            // the trial counts from the day the gym actually opens
            if ($gym && $gym->subscription_status === 'trial') {
                $gym->update(['subscription_expires_at' => now()->addDays((int) config('fitgym.trial_days', 14))->toDateString()]);
            }
        });

        if ($gym) {
            $gym->refresh();

            \App\Models\AuditLog::create([
                'gym_id' => $gym->id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'role' => 'Admin',
                'action' => 'gym.email_verified',
                'subject_type' => 'Gym',
                'subject_id' => $gym->id,
                'description' => "{$user->name} confirmed their email for {$gym->name}",
                'ip_address' => $request->ip(),
            ]);

            \App\Support\Email::send($user, new \App\Notifications\GymWelcome($gym->name, $gym->subscription_expires_at?->format('M d, Y')));

            \App\Models\PlatformNotification::notify(
                'signup',
                "New branch: {$gym->name}",
                "{$user->name} ({$user->email}) signed up {$gym->name} and confirmed their email. Its free trial runs until " . $gym->subscription_expires_at?->format('M d, Y') . '.',
                $gym->id,
                'branches',
                "signup:{$gym->id}",
            );
        }

        return redirect('/?role=admin&reason=verified');
    }

    /**
     * Send the confirmation email again. Always answers the same way, so it
     * cannot be used to find out which emails have an account.
     */
    public function resendVerification(Request $request)
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $answer = response()->json([
            'success' => true,
            'message' => "If {$email} belongs to a gym that is waiting for confirmation, a new link is on its way. Check your inbox and spam folder.",
        ]);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['success' => false, 'message' => 'Enter the email you signed up with.'], 422);
        }

        $user = User::where('email', $email)->where('role', 'Admin')->whereNull('email_verified_at')->first();
        $gym = $user?->gym_id ? Gym::find($user->gym_id) : null;
        if ($user && $gym) {
            $this->sendVerificationEmail($user, $gym);
        }

        return $answer;
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
