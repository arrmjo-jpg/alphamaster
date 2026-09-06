<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One recorded administrative action (ADR 0037).
 *
 * Append-only, enforced here as well as by the absence of an `updated_at` column:
 * a trail whose rows can be edited or deleted through the application is not a
 * trail. Retention is an operational decision taken against the database, not an
 * application feature, so nothing here offers a way to prune.
 *
 * @property string $id
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject
 * @property string $outcome
 * @property array<string, mixed>|null $context
 * @property string|null $correlation_id
 * @property Carbon $created_at
 */
class AuditRecord extends BaseModel
{
    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_FAILED = 'failed';

    protected $table = 'audit_records';

    /** There is no updated_at: a record is written once and never revised. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'actor_id',
        'action',
        'subject',
        'outcome',
        'context',
        'correlation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A guard rather than a convention. Nothing in the application may revise or
        // remove a record; an operator pruning by retention does it against the
        // database, deliberately and outside this path.
        static::updating(static function (): void {
            throw new RuntimeException('An audit record is append-only and cannot be updated.');
        });

        static::deleting(static function (): void {
            throw new RuntimeException('An audit record is append-only and cannot be deleted.');
        });
    }

    /*
     * The actor is recorded by identifier only. Core does not import a domain module
     * (ADR 0002), so there is no relation here and nothing in Core knows what a user
     * is. Resolving an id to a person is the reader's concern, and the id survives
     * the account being deleted — which is exactly when the record matters most.
     */
}
