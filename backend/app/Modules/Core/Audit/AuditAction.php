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

    /**
     * Configuration written to a portable artefact, or read back from one (ADR 0039).
     *
     * Both are recorded because both move the platform's configuration across a
     * boundary: one out to a file that will be copied, one in over what is running.
     */
    public const CONFIGURATION_EXPORTED = 'configuration.exported';

    public const CONFIGURATION_RESTORED = 'configuration.restored';

    public const MAIL_TEST_SENT = 'mail.test_sent';

    /**
     * An account was created (ADR 0037, amended 2026-09-10).
     *
     * Always a regular account: creation is not a route across the administrative
     * boundary, so a record of it is never a record of an administrator appearing.
     * The subject is the new account's identifier and the context says whether it
     * was created able to sign in — never the address, the number or the password.
     */
    public const ACCOUNT_CREATED = 'account.created';

    /**
     * An account's identity was changed by an administrator.
     *
     * The context names the fields that changed and no value of any of them, which
     * is the same rule the secret actions follow and for the same reason: the trail
     * is readable by anyone holding `audit.view`, and a directory of every operator's
     * address and telephone number is not something it should become.
     */
    public const ACCOUNT_UPDATED = 'account.updated';

    /**
     * Sign-in was allowed, or stopped and existing tokens revoked.
     *
     * Two actions rather than one with a direction in its context: the question an
     * operator asks the trail is "when was this account switched off", and a filter
     * on `action` should answer it without also reading every record's context.
     */
    public const ACCOUNT_ACTIVATED = 'account.activated';

    public const ACCOUNT_DEACTIVATED = 'account.deactivated';

    /**
     * An account crossed the administrative boundary, in one direction or the other.
     *
     * The most consequential thing on this list. Demotion carries the roles it
     * stripped, because after it runs nothing else remembers what they were.
     */
    public const ACCOUNT_PROMOTED = 'account.promoted';

    public const ACCOUNT_DEMOTED = 'account.demoted';

    /**
     * An administrator's roles were replaced.
     *
     * What an administrator may do, changed by somebody else. The context names the
     * roles added and the roles removed rather than the resulting set, because the
     * question the trail is asked is what changed and by whom — the resulting set is
     * readable from the account itself, and only for as long as nobody changes it
     * again.
     *
     * Role names are published catalogue identifiers (ADR 0014), not values the
     * redaction rule protects: a trail that recorded only that "roles changed" would
     * answer none of the questions it exists for.
     */
    public const ACCOUNT_ROLES_CHANGED = 'account.roles_changed';

    private function __construct() {}
}
