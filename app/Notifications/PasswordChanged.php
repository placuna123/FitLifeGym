<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Security notice after any password change or reset.
 */
class PasswordChanged extends FitGymEmail
{
    public function __construct(private string $changedAt) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your FitGym password was changed')
            ->greeting('Hello' . (isset($notifiable->name) ? " {$notifiable->name}" : '') . ',')
            ->line("The password for your FitGym account ({$notifiable->email}) was changed on **{$this->changedAt}**.")
            ->line('If you made this change, no action is needed.')
            ->line('**If you did not change your password**, reset it right away and contact your gym administrator:')
            ->action('Reset my password', url('/forgot-password'));
    }
}
