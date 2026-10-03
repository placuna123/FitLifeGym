<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * For accounts someone else created (branch Admin, Staff): links to setting
 * their own password instead of emailing a password.
 */
class AccountInvite extends FitGymEmail
{
    public function __construct(
        private string $roleLabel,
        private string $gymName,
        private string $setPasswordUrl,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your FitGym {$this->roleLabel} account for {$this->gymName}")
            ->greeting('Hello' . (isset($notifiable->name) ? " {$notifiable->name}" : '') . '!')
            ->line("A FitGym **{$this->roleLabel}** account was created for you at **{$this->gymName}**.")
            ->line('Set your own password to start using it:')
            ->action('Set my password', $this->setPasswordUrl)
            ->line('This link expires in 60 minutes. If it has expired, use "Forgot Password" on the sign-in page with this email address.')
            ->line("Sign in at {$this->signInUrl()} and choose **{$this->roleLabel}**.");
    }
}
