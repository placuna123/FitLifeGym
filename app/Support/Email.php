<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\PasswordChanged;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Password;

/**
 * Sends FitGym emails without ever breaking the action that triggered them:
 * like SMS, a failed email (bad address, mail server down) is logged, not thrown.
 */
class Email
{
    public static function send(?object $notifiable, Notification $notification): bool
    {
        if (! $notifiable) {
            return false;
        }

        try {
            $notifiable->notify($notification);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Email: send failed', [
                'to' => $notifiable->email ?? null,
                'email' => class_basename($notification),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /** A one-time "set your password" link (the standard password reset link). */
    public static function setPasswordUrl(User $user): string
    {
        return route('password.reset', [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
        ]);
    }

    /** Tell the owner their password just changed. */
    public static function passwordChanged(?User $user): bool
    {
        return self::send($user, new PasswordChanged(now()->format('M d, Y g:i A')));
    }

    /** For people who may not have a login (e.g. members), by address. */
    public static function to(?string $address, Notification $notification): bool
    {
        if (! $address || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return self::send(NotificationFacade::route('mail', $address), $notification);
    }
}
