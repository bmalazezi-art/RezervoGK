<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminLoginCode extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $code, public readonly int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The code is deliberately not in the subject, so it never shows on a lock screen preview.
        return (new MailMessage)
            ->subject(__('Kodi për hyrjen në administrim'))
            ->view(['html' => 'mail.admin-login-code', 'text' => 'mail.admin-login-code-text'], ['code' => $this->code, 'minutes' => $this->minutes]);
    }
}
