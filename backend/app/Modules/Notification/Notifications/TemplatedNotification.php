<?php

declare(strict_types=1);

namespace App\Modules\Notification\Notifications;

use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Notification\Channels\SmsChannel;
use App\Modules\Notification\Contracts\PreferenceResolverContract;
use App\Modules\Notification\Contracts\TemplateRendererContract;
use App\Modules\Notification\Data\RenderedNotification;
use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\User\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A notification whose content comes from a database template rather than from code.
 *
 * One class serves every notification type: what differs between them is the template
 * and the recipient's preferences, neither of which is a reason to write another
 * class. Adding a notification means adding an enum case and a template row.
 */
class TemplatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Rendered once per recipient and reused across that recipient's channels, so a
     * three-channel delivery reads the template once rather than three times.
     */
    private ?RenderedNotification $rendered = null;

    /**
     * @param  array<string, string|int>  $placeholders
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly array $placeholders = [],
    ) {
        // Notifications run on their own queue so a burst of them cannot delay
        // user-facing work on the default queue (ADR 0020).
        $this->onQueue('notifications');
    }

    /**
     * The channels this recipient should actually receive this on.
     *
     * Preferences are consulted here rather than at dispatch, so a notification sent
     * to many recipients respects each one's choices individually.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [NotificationChannel::DATABASE->value];
        }

        $channels = app(PreferenceResolverContract::class)->channelsFor($notifiable, $this->type);

        return array_map(
            static fn (NotificationChannel $channel): string => match ($channel) {
                NotificationChannel::SMS => SmsChannel::class,
                default => $channel->value,
            },
            $channels
        );
    }

    /**
     * The in-app record.
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return $this->render($notifiable)->toArray();
    }

    /**
     * The email.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $rendered = $this->render($notifiable);

        return (new MailMessage)
            ->subject($rendered->subject)
            ->line($rendered->body);
    }

    /**
     * The SMS body. Subject and body would read as repetition in a text message, so
     * only the body is sent.
     */
    public function toSms(mixed $notifiable): string
    {
        return $this->render($notifiable)->body;
    }

    /**
     * Render in the recipient's own language.
     *
     * A notification is read by its recipient, not by whoever triggered it, so the
     * locale comes from the recipient's preference and falls back to the platform
     * default rather than to whatever request happened to be in flight.
     */
    private function render(mixed $notifiable): RenderedNotification
    {
        if ($this->rendered !== null) {
            return $this->rendered;
        }

        $locale = $this->localeFor($notifiable);

        return $this->rendered = app(TemplateRendererContract::class)
            ->render($this->type, $locale, $this->placeholders);
    }

    private function localeFor(mixed $notifiable): string
    {
        $preferred = is_object($notifiable) && isset($notifiable->preferred_locale)
            ? (string) $notifiable->preferred_locale
            : '';

        return $preferred !== ''
            ? $preferred
            : app(LocaleResolverInterface::class)->getDefaultLocale();
    }
}
