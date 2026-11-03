<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the membership expiry reminders once a day on the first visit, after the
 * response has gone out. The 8:00 AM schedule (routes/console.php) only fires when
 * a scheduler is running on the machine; this makes the reminders go out on any
 * day someone uses the system, scheduler or not. The command itself never
 * sends the same reminder twice.
 */
class RunDailyMemberReminders
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $key = 'member_reminders_ran:' . Carbon::now('Asia/Manila')->toDateString();

        // add() only succeeds for the first request of the day
        if (! Cache::add($key, true, now()->addDay())) {
            return;
        }

        try {
            Artisan::call('members:send-expiry-reminders');
        } catch (\Throwable $exception) {
            Log::warning('Daily member reminders failed', ['error' => $exception->getMessage()]);
        }
    }
}
