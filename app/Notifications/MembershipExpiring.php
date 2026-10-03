<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class MembershipExpiring extends FitGymEmail
{
    public function __construct(
        private string $gymName,
        private string $expiresOn,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your {$this->gymName} membership expires on {$this->expiresOn}")
            ->greeting('Membership reminder')
            ->line("Your membership at **{$this->gymName}** will expire on **{$this->expiresOn}**.")
            ->line('Renew through GCash in the Member portal, or pay at the front desk, to keep your access active.')
            ->action('Renew my membership', $this->signInUrl());
    }
}
