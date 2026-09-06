<?php

declare(strict_types=1);

namespace App\Modules\Core\Audit;

/**
 * The stable identifiers the trail records (ADR 0037).
 *
 * Constants rather than free strings, so a query for every secret rotation cannot
 * miss a row because one call site phrased it differently, and so renaming what an
 * action is called in an interface never changes what was recorded.
 */
final class AuditAction
{
    public const SETTING_UPDATED = 'setting.updated';

    public const SETTING_CLEARED = 'setting.cleared';

    /**
     * A secret was given a value it did not have.
     */
    public const SECRET_SET = 'secret.set';

    /**
     * A secret that already had a value was given a different one.
     */
    public const SECRET_ROTATED = 'secret.rotated';

    public const SECRET_CLEARED = 'secret.cleared';

    /**
     * A registry synchronisation left rows nothing declares.
     */
    public const SETTINGS_ORPHANED = 'settings.orphaned';

    /**
     * A group restored to an earlier state (ADR 0040). Recorded once for the whole
     * operation rather than per setting: a rollback is one decision, and a trail that
     * splits it into twenty rows makes it harder to see that it happened, not easier.
     */
    public const SETTINGS_ROLLED_BACK = 'settings.rolled_back';

    /**
     * Records moved out of the active trail and into an archive (ADR 0037).
     *
     * The one action that is never itself eligible for archival. Without that
     * exclusion a patient sequence of operations erases the evidence that any of them
     * happened, one window at a time, and the trail ends up complete-looking and false.
     */
    public const AUDIT_ARCHIVED = 'audit.archived';

    public const MAIL_TEST_SENT = 'mail.test_sent';

    private function __construct() {}
}
