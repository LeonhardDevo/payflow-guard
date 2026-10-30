<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Outbox\OutboxMailTransport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;
use Symfony\Component\Mime\Email;

final class AuthMailMessages
{
    public static function verifyEmail(object $notifiable, string $url): MailMessage
    {
        return self::base($notifiable, 'verify_email')
            ->subject((string) __('emails.verify.subject', ['app' => config('app.name')]))
            ->greeting((string) __('emails.verify.greeting', ['name' => self::name($notifiable)]))
            ->line((string) __('emails.verify.intro', ['app' => config('app.name')]))
            ->action((string) __('emails.verify.action'), $url)
            ->line((string) __('emails.verify.outro'));
    }

    public static function resetPassword(object $notifiable, string $url): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords', 'users').'.expire', 60);

        return self::base($notifiable, 'password_reset')
            ->subject((string) __('emails.reset.subject', ['app' => config('app.name')]))
            ->greeting((string) __('emails.reset.greeting', ['name' => self::name($notifiable)]))
            ->line((string) __('emails.reset.intro'))
            ->action((string) __('emails.reset.action'), $url)
            ->line(trans_choice('emails.reset.expires', $minutes, ['count' => $minutes]))
            ->line((string) __('emails.reset.outro'));
    }

    private static function base(object $notifiable, string $referenceType): MailMessage
    {
        $id = method_exists($notifiable, 'getKey') ? (string) $notifiable->getKey() : '';
        $locale = App::getLocale();

        return (new MailMessage)
            ->markdown('mail.notification')
            ->withSymfonyMessage(function (Email $message) use ($referenceType, $id, $locale): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader(OutboxMailTransport::HEADER_REFERENCE_TYPE, $referenceType);
                $headers->addTextHeader(OutboxMailTransport::HEADER_REFERENCE_ID, $id.':'.bin2hex(random_bytes(6)));
                $headers->addTextHeader(OutboxMailTransport::HEADER_LOCALE, $locale);
            });
    }

    private static function name(object $notifiable): string
    {
        $name = property_exists($notifiable, 'name') || isset($notifiable->name) ? (string) $notifiable->name : '';

        return $name !== '' ? $name : (string) __('emails.common.there');
    }
}
