<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmailAddress extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hash = sha1($notifiable->email);

        $apiUrl = URL::temporarySignedRoute(
            'api.v1.email.verify',
            now()->addHour(),
            [
                'id' => $notifiable->getKey(),
                'hash' => $hash,
            ],
        );

        $query = parse_url($apiUrl, PHP_URL_QUERY);
        $frontend = rtrim((string) config('flowmv.frontend_url'), '/')
            .'/verify-email/'.$notifiable->getKey().'/'.$hash
            .($query ? '?'.$query : '');

        return (new MailMessage)
            ->subject('Verify your FlowMV email')
            ->line('Verify your email address before accepting an organization invitation.')
            ->action('Verify email', $frontend);
    }
}
