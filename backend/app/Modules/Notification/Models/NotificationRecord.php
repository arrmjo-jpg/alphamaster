<?php

declare(strict_types=1);

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * One in-app record: what the platform decided to tell somebody.
 *
 * Laravel's own model, named here so this module has somewhere to put the queries it
 * needs and so nothing outside reaches for the framework's class directly. The table,
 * the casts and the `read_at` semantics are the framework's and are not restated.
 *
 * The record is never deleted through the application. ADR 0019 makes the in-app copy
 * the evidence that a notification was raised at all — the one channel a recipient
 * cannot silence — and a recipient who could delete it could remove the evidence that
 * they were told. Marking it read is therefore the only state this module changes.
 *
 * Larastan reads an Eloquent model's properties from the migrations it can see, and
 * this table is the framework's rather than a module's, so the columns are declared
 * here. The types are Laravel's own: `data` is cast to an array by the parent, and the
 * timestamps are Carbon instances.
 *
 * @property string $id
 * @property string $type
 * @property string $notifiable_type
 * @property string $notifiable_id
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> query()
 */
class NotificationRecord extends DatabaseNotification
{
    /**
     * The records belonging to one account, newest first.
     *
     * Scoped by the notifiable rather than filtered afterwards: this is the whole of
     * the authorization on the inbox, so it belongs in the query rather than in a
     * check a later caller could forget.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFor(Builder $query, string $notifiableType, string $notifiableId): Builder
    {
        return $query
            ->where('notifiable_type', $notifiableType)
            ->where('notifiable_id', $notifiableId)
            ->latest();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
