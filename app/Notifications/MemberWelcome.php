<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class MemberWelcome extends FitGymEmail
{
    public function __construct(private string $gymName) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Welcome to {$this->gymName}!")
            ->greeting('Welcome!')
            ->line("Your member account at **{$this->gymName}** has been created.")
            ->line('You can sign in now to view your account and pay your membership through GCash. The gym staff will approve your account before you can check in at the gym.')
            ->action('Sign in as Member', $this->signInUrl())
            ->line('If you did not sign up, please contact the gym.');
    }
}
