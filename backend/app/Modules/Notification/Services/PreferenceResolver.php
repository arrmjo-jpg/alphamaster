<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Contracts\PreferenceResolverContract;
use App\Modules\Notification\Enums\NotificationChannel;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Models\NotificationPreference;
use App\Modules\User\Models\User;

/**
 * Decides which channels a given notification may reach a given recipient on.
 *
 * A stored preference is an override, not the whole answer: absence of a row means
 * the notification's own defaults apply, so a user who has never opened the settings
 * screen is not represented by rows asserting the obvious.
 */
class PreferenceResolver implements PreferenceResolverContract
{
    /**
     * The channels this notification should actually be delivered on.
     *
     * @return array<int, NotificationChannel>
     */
    public function channelsFor(User $user, NotificationType $type): array
    {
        $overrides = $this->overridesFor($user, $type);

        $channels = [];

        foreach (NotificationChannel::cases() as $channel) {
            if ($this->allows($type, $channel, $overrides)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * Record a recipient's choice.
     *
     * Refuses the combinations that are not the user's to make: the in-app record is
     * the audit trail of what the platform decided to tell them, and a security alert
     * is not a preference. Silencing either would be a setting that exists only to be
     * regretted.
     */
    public function set(User $user, NotificationType $type, NotificationChannel $channel, bool $enabled): void
    {
        if (! $enabled && ! $this->isSilenceable($type, $channel)) {
            throw new \InvalidArgumentException(
                sprintf('[%s] cannot be disabled on the [%s] channel.', $type->value, $channel->value)
            );
        }

        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'type' => $type->value, 'channel' => $channel->value],
            ['enabled' => $enabled],
        );
    }

    /**
     * Every effective decision for a recipient, defaults included, for display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(User $user): array
    {
        $described = [];

        foreach (NotificationType::cases() as $type) {
            $overrides = $this->overridesFor($user, $type);

            foreach (NotificationChannel::cases() as $channel) {
                $described[] = [
                    'type' => $type->value,
                    'channel' => $channel->value,
                    'enabled' => $this->allows($type, $channel, $overrides),
                    'silenceable' => $this->isSilenceable($type, $channel),
                ];
            }
        }

        return $described;
    }

    /**
     * Whether a recipient is permitted to turn this combination off at all.
     */
    public function isSilenceable(NotificationType $type, NotificationChannel $channel): bool
    {
        return $type->isOptional() && $channel->isOptional();
    }

    /**
     * Resolve one channel, override first and default second.
     *
     * @param  array<string, bool>  $overrides
     */
    private function allows(NotificationType $type, NotificationChannel $channel, array $overrides): bool
    {
        // A non-silenceable combination is delivered regardless of any stored row,
        // so a preference written before the rules tightened cannot suppress it.
        if (! $this->isSilenceable($type, $channel)) {
            return in_array($channel, $type->defaultChannels(), true)
                || $channel === NotificationChannel::DATABASE;
        }

        return $overrides[$channel->value]
            ?? in_array($channel, $type->defaultChannels(), true);
    }

    /**
     * Stored overrides for one recipient and notification, keyed by channel.
     *
     * @return array<string, bool>
     */
    private function overridesFor(User $user, NotificationType $type): array
    {
        return NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->pluck('enabled', 'channel')
            ->map(static fn (mixed $enabled): bool => (bool) $enabled)
            ->all();
    }
}
