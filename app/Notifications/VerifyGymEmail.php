<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/** Sent when someone signs up their own gym: proves the email address is theirs. */
class VerifyGymEmail extends FitGymEmail
{
    public function __construct(
        private string $gymName,
        private string $verifyUrl,
        private int $validHours,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Confirm your email to open {$this->gymName} on FitGym")
            ->greeting('One last step')
            ->line("You signed up **{$this->gymName}** on FitGym. Confirm that this email address is yours to open your gym and start your free trial.")
            ->action('Confirm my email', $this->verifyUrl)
            ->line("This link works for {$this->validHours} hours. You cannot sign in as Admin until you confirm.")
            ->line('If you did not sign up a gym on FitGym, ignore this email and nothing will happen.');
    }
}
