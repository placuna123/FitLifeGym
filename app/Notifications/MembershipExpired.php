<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class MembershipExpired extends FitGymEmail
{
    public function __construct(
        private string $gymName,
        private string $expiredOn,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your {$this->gymName} membership expired on {$this->expiredOn}")
            ->greeting('Membership expired')
            ->line("Your membership at **{$this->gymName}** expired on **{$this->expiredOn}**, so you can't check in for now.")
            ->line('Renew through GCash in the Member portal, or pay in cash at the front desk, to start checking in again.')
            ->action('Renew my membership', $this->signInUrl());
    }
}
