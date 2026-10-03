<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Notification;
use App\Services\SmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Daily job: SMS + in-app notification for members whose membership expires
 * in 3 days. Guarded by a per-member/per-day cache marker so a manual re-run
 * on the same day (e.g. while testing) doesn't double-send.
 */
class SendMembershipExpiryReminders extends Command
{
    protected $signature = 'members:send-expiry-reminders';

    protected $description = 'Send an SMS + in-app reminder to members whose membership expires in 3 days';

    public function handle(SmsService $sms): int
    {
        $targetDate = now()->addDays(3)->toDateString();
        $today = now()->toDateString();

        $members = Member::withoutGlobalScopes()
            ->where('is_archived', 0)
            ->whereDate('expiry_date', $targetDate)
            ->get();

        $sentCount = 0;

        foreach ($members as $member) {
            $marker = "expiry_reminder_sent:{$member->id}:{$today}";
            if (Cache::has($marker)) {
                continue;
            }

            $message = "Hi {$member->full_name}, your gym membership will expire on {$targetDate}. Please renew soon to keep your access active.";

            $sms->send($member->phone, $message, $member->gym_id);

            try {
                Notification::create([
                    'gym_id' => $member->gym_id,
                    'member_id' => $member->id,
                    'title' => 'Membership Expiring Soon',
                    'message' => $message,
                    'type' => 'yellow',
                    'is_read' => 0,
                    'read_at' => null,
                    'is_archived' => 0,
                ]);
            } catch (\Throwable $exception) {
                // Keep going even if the in-app notification write fails.
            }

            Cache::put($marker, true, now()->endOfDay());
            $sentCount++;
        }

        $this->info("Sent {$sentCount} expiry reminder(s) for members expiring on {$targetDate}.");

        return self::SUCCESS;
    }
}
