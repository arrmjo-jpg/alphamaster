<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Core\Contracts\RetentionPolicyContract;
use App\Modules\Settings\Contracts\SettingServiceInterface;

/**
 * Retention, answered from the settings catalogue (ADR 0037).
 *
 * `operations.audit_retention_days` is declared with a 365-day default and guarded by
 * `settings.security.update`, because shortening how long the record of who did what
 * survives is not an ordinary configuration change.
 */
class SettingsRetentionPolicy implements RetentionPolicyContract
{
    public function __construct(private readonly SettingServiceInterface $settings) {}

    public function auditRetentionDays(): int
    {
        $days = $this->settings->get('operations.audit_retention_days');

        // A floor rather than a trusting cast. A zero or negative window would make
        // every record eligible the moment it is written, which is a configuration
        // mistake that should not be able to empty the trail — and the declaration's
        // own rules already refuse it on the way in, so reaching here means something
        // wrote around them.
        return is_int($days) && $days > 0 ? $days : 365;
    }
}
