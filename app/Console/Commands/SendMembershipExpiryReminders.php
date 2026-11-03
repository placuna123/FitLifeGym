<?php

namespace App\Console\Commands;

use App\Models\Gym;
use App\Models\Member;
use App\Models\Notification;
use App\Notifications\MembershipExpired;
use App\Notifications\MembershipExpiring;
use App\Services\SmsService;
use App\Support\Email;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Daily job: tells members (SMS + email + in-app) and their gym's admin (in-app)
 * about memberships that are about to end or just ended:
 *   - "soon":    ends within the next 3 days
 *   - "today":   ends today
 *   - "expired": ended in the last 3 days and was not renewed
 * Each reminder goes out once per member per expiry date, so a missed day is
 * caught up the next day and a re-run never double-sends. Dates are Philippine time.
 */
class SendMembershipExpiryReminders extends Command
{
    protected $signature = 'members:send-expiry-reminders {--dry-run : List who would be reminded without sending anything}';

    protected $description = 'Remind members (and their admin) about memberships ending soon, today, or just expired';

    public function handle(SmsService $sms): int
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();
        $dryRun = (bool) $this->option('dry-run');

        $members = Member::withoutGlobalScopes()
            ->where('is_archived', 0)
            ->where('status', 'Active')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$today->copy()->subDays(3)->toDateString(), $today->copy()->addDays(3)->toDateString()])
            ->get();

        $sent = 0;
        foreach ($members as $member) {
            $expiry = Carbon::parse($member->expiry_date->toDateString(), 'Asia/Manila')->startOfDay();
            $daysLeft = (int) $today->diffInDays($expiry, false);
            $kind = $daysLeft > 0 ? 'soon' : ($daysLeft === 0 ? 'today' : 'expired');

            $marker = "membership_reminder:{$member->id}:{$expiry->toDateString()}:{$kind}";
            if (Cache::has($marker)) {
                continue;
            }

            $gymName = Gym::find($member->gym_id)?->name ?? 'your gym';
            $date = $expiry->format('M j, Y');
            [$title, $memberMessage, $adminMessage] = match ($kind) {
                'soon' => [
                    'Membership Expiring Soon',
                    "Hi {$member->full_name}, your {$gymName} membership ends on {$date} (in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . '). Renew on the Payments page or at the front desk to keep checking in.',
                    "{$member->full_name}'s membership ends on {$date} (in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . ').',
                ],
                'today' => [
                    'Membership Ends Today',
                    "Hi {$member->full_name}, your {$gymName} membership ends today ({$date}). Renew today to keep checking in.",
                    "{$member->full_name}'s membership ends today ({$date}).",
                ],
                default => [
                    'Membership Expired',
                    "Hi {$member->full_name}, your {$gymName} membership expired on {$date}. Renew on the Payments page or at the front desk to check in again.",
                    "{$member->full_name}'s membership expired on {$date}. They can't check in until they renew.",
                ],
            };

            $this->line(($dryRun ? '[dry run] ' : '') . "{$kind}: {$member->full_name} ({$date})");
            if ($dryRun) {
                $sent++;
                continue;
            }

            $sms->send($member->phone, $memberMessage, $member->gym_id);

            Email::to($member->email, $kind === 'expired'
                ? new MembershipExpired($gymName, $date)
                : new MembershipExpiring($gymName, $date));

            try {
                foreach ([[$member->id, $memberMessage], [null, $adminMessage]] as [$memberId, $message]) {
                    Notification::create([
                        'gym_id' => $member->gym_id,
                        'member_id' => $memberId, // null = the gym's admin notification center
                        'title' => $title,
                        'message' => $message,
                        'type' => $kind === 'expired' ? 'red' : 'yellow',
                        'is_read' => 0,
                        'read_at' => null,
                        'is_archived' => 0,
                    ]);
                }
            } catch (\Throwable $exception) {
                // Keep going even if the in-app notification write fails.
            }

            // Long enough to outlive the 3-day window on both sides of the expiry date.
            Cache::put($marker, true, now()->addDays(10));
            $sent++;
        }

        $this->info(($dryRun ? 'Dry run: ' : 'Sent ') . "{$sent} reminder(s).");

        return self::SUCCESS;
    }
}
