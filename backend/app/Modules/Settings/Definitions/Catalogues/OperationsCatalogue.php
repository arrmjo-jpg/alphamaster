<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * Operational policy an operator sets without a deploy.
 *
 * Audit retention is here rather than left implicit, which ADR 0037 asked for and
 * did not settle: it recorded that retention is an operational decision and that
 * the window should be revisited once backup semantics existed, without naming one.
 *
 * The default is 365 days. It is a deliberate choice rather than a round number:
 * an audit trail exists to answer questions during an incident, incidents are
 * frequently discovered months after the change that caused them, and a year covers
 * the annual review cycles most operators actually run. Shorter defaults optimise
 * for table size, which is the wrong thing to optimise when the rows are small and
 * the question they answer is "who changed this".
 *
 * Nothing prunes automatically. The setting records the intent; acting on it is an
 * operator's decision taken against the database, because a scheduled job that
 * silently deleted audit rows would be the one deletion path ADR 0037 forbids.
 */
class OperationsCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            new SettingDefinition(
                group: 'operations',
                key: 'audit_retention_days',
                type: SettingType::INTEGER,
                default: 365,
                nullable: false,
                rules: ['integer', 'between:30,3650'],
                // Changing how long the record of who did what survives is itself a
                // security decision, so it needs the security permission rather than
                // ordinary settings.update.
                permission: 'settings.security.update',
            ),
            new SettingDefinition(
                group: 'operations',
                key: 'provider_timeout_seconds',
                type: SettingType::INTEGER,
                default: 10,
                nullable: false,
                rules: ['integer', 'between:1,120'],
            ),
            new SettingDefinition(
                group: 'operations',
                key: 'provider_retry_attempts',
                type: SettingType::INTEGER,
                default: 2,
                nullable: false,
                rules: ['integer', 'between:0,10'],
            ),
        ];
    }
}
