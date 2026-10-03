<?php

namespace App\Services;

use App\Models\Gym;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around TextBee (textbee.dev) — relays SMS through a
 * registered Android phone's own SIM rather than a paid SMS API, so sends
 * are always best-effort: a failed/misconfigured send (phone offline, no
 * credits, etc.) must never break the caller's flow (registration, booking,
 * payment, etc.), so every failure is caught and logged rather than thrown.
 *
 * Each gym can register its own sending device (own phone/SIM), so a
 * member's SMS comes from their own gym's number rather than one shared
 * platform-wide number. $gymId resolves that gym's device; when the gym
 * hasn't configured one, the platform default from services.textbee is used.
 */
class SmsService
{
    public function send(string $phone, string $message, ?int $gymId = null): bool
    {
        $number = $this->normalizePhone($phone);
        if ($number === null) {
            Log::warning('SmsService: skipped send, unrecognized phone format', ['phone' => $phone]);
            return false;
        }

        $apiKey = config('services.textbee.api_key');
        if (! $apiKey) {
            Log::warning('SmsService: skipped send, TEXTBEE_API_KEY not configured');
            return false;
        }

        $gym = $gymId ? Gym::find($gymId) : null;
        $deviceId = $gym?->textbee_device_id ?: config('services.textbee.device_id');
        $simSubscriptionId = $gym?->textbee_sim_subscription_id ?: config('services.textbee.sim_subscription_id');

        try {
            $payload = [
                'recipients' => [$number],
                'message' => $message,
            ];
            if ($deviceId) {
                $payload['deviceId'] = $deviceId;
            }
            if ($simSubscriptionId) {
                $payload['simSubscriptionId'] = (int) $simSubscriptionId;
            }

            $response = Http::withHeaders(['x-api-key' => $apiKey])
                ->timeout(10)
                ->post('https://api.textbee.dev/api/v1/gateway/send-sms', $payload);

            if (! $response->successful()) {
                Log::warning('SmsService: TextBee API returned an error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('SmsService: send failed', ['error' => $exception->getMessage()]);
            return false;
        }
    }

    /**
     * TextBee expects international format. Accepts 09XXXXXXXXX,
     * +639XXXXXXXXX, or 639XXXXXXXXX and normalizes to +639XXXXXXXXX;
     * returns null if the number doesn't match any of those.
     */
    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/[^\d]/', '', $phone);

        if (preg_match('/^09\d{9}$/', $digits)) {
            return '+63' . substr($digits, 1);
        }
        if (preg_match('/^639\d{9}$/', $digits)) {
            return '+' . $digits;
        }

        return null;
    }
}
