<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification type for every platform event: stored for the in-app bell
 * and emailed with the branded layout.
 */
class PlatformNotification extends Notification
{
    use Queueable;

    public string $title;

    public string $message;

    public ?string $url;

    public array $options;

    public function __construct(string $title, string $message, ?string $url = null, array $options = [])
    {
        $this->title = $title;
        $this->message = $message;
        $this->url = $url;
        $this->options = $options;
    }

    public function via($notifiable): array
    {
        if (! empty($this->options['mail_only'])) {
            return ['mail'];
        }

        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject($this->title)
            ->view('emails.notification', [
                'title' => $this->title,
                'body' => $this->message,
                'url' => $this->url,
                'code' => $this->options['code'] ?? null,
                'button' => $this->options['button'] ?? 'Open e-Offering',
                'name' => $notifiable->name ?? null,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
