<?php

declare(strict_types=1);

namespace App\Modules\Core\Resources;

use App\Modules\Core\Models\AuditRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit record, as the admin API presents it (ADR 0037).
 *
 * `context` is passed through as it was stored. That is safe here and only here,
 * because the redaction is structural: no code path writes a secret into it, so there
 * is nothing for a presenter to remember to strip. A resource that filtered on the way
 * out would imply the stored record needed filtering — and would be the wrong place to
 * fix it if it ever did.
 *
 * @mixin AuditRecord
 */
class AuditRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actor_id,
            'action' => $this->action,
            // Beside the stable identifier, never instead of it (ADR 0031). The action
            // is what a client branches on; the label is what a person reads.
            'action_label' => __('audit.action.'.$this->action),
            'subject' => $this->subject,
            'outcome' => $this->outcome,
            'context' => $this->context,
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
