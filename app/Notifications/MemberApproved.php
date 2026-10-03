<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class MemberApproved extends FitGymEmail
{
    public function __construct(private string $gymName) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your {$this->gymName} membership is approved")
            ->greeting('Good news!')
            ->line("Your member account at **{$this->gymName}** has been approved.")
            ->line('You can now get your attendance QR code in the Member portal and check in at the gym.')
            ->action('Open Member portal', $this->signInUrl());
    }
}
