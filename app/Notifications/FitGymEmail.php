<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for FitGym emails. Sent immediately (not queued) so they work without a
 * queue worker running.
 */
abstract class FitGymEmail extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    abstract public function toMail(object $notifiable): MailMessage;

    protected function signInUrl(): string
    {
        return url('/');
    }
}
