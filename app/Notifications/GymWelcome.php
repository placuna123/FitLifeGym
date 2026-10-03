<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class GymWelcome extends FitGymEmail
{
    public function __construct(
        private string $gymName,
        private ?string $trialEndsOn,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Welcome to FitGym, {$this->gymName}!")
            ->greeting('Welcome to FitGym!')
            ->line("Your gym **{$this->gymName}** is ready, and you are its Admin.");

        if ($this->trialEndsOn) {
            $mail->line("Your free trial runs until **{$this->trialEndsOn}**.");
        }

        return $mail
            ->line('To get started, sign in as Admin and set up your membership plans, GCash details, and staff accounts.')
            ->action('Sign in to FitGym', $this->signInUrl())
            ->line('If you did not create this gym, you can ignore this email.');
    }
}
