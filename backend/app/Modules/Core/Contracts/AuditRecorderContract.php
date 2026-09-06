<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Models\AuditRecord;

/**
 * Records administrative actions (ADR 0037).
 *
 * There is no method for reading the trail here: reading it is a separate,
 * separately permissioned concern, and an interface that both wrote and read would
 * make it easy for a consumer to acquire the second by needing the first.
 */
interface AuditRecorderContract
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function succeeded(string $action, ?string $subject = null, array $context = []): AuditRecord;

    /**
     * @param  array<string, mixed>  $context
     */
    public function failed(string $action, ?string $subject = null, array $context = []): AuditRecord;
}
