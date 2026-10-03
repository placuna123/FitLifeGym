<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class PaymentVerified extends FitGymEmail
{
    public function __construct(
        private string $gymName,
        private string $amount,
        private string $invoiceNumber,
        private string $activeUntil,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Payment received — {$this->gymName}")
            ->greeting('Payment verified')
            ->line("Your payment of **PHP {$this->amount}** ({$this->invoiceNumber}) to **{$this->gymName}** has been verified.")
            ->line("Your membership is active until **{$this->activeUntil}**.")
            ->action('View my payments', $this->signInUrl())
            ->line('Thank you!');
    }
}
