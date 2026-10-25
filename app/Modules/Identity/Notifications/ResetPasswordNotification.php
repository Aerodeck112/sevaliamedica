<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject('Resetarea parolei — SEVALIA')
            ->greeting('Bună ziua,')
            ->line('Am primit o cerere de resetare a parolei pentru contul dumneavoastră din panoul SEVALIA.')
            ->action('Setează o parolă nouă', $url)
            ->line("Linkul poate fi folosit o singură dată și expiră în {$minutes} de minute.")
            ->line('Dacă nu ați solicitat resetarea, ignorați acest mesaj; parola rămâne neschimbată.')
            ->salutation('Echipa SEVALIA');
    }
}
