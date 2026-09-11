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

    /**
     * A role was defined. The context names its identifier and the permissions it was
     * created with — the grant is the consequential part, and the identifier is what
     * every later record of the role refers to. The label is not recorded: it is
     * readable from the role, and it is not what anybody audits.
     */
    public const ROLE_CREATED = 'role.created';

    /**
     * A role's label changed in one language.
     *
     * Only the label: the identifier is immutable, and a change of permissions is its
     * own action. Neither the old nor the new wording is recorded — which locale moved
     * is the answer, and the role carries the value.
     */
    public const ROLE_UPDATED = 'role.updated';

    /**
     * What a role grants changed.
     *
     * Everybody holding the role gained or lost these at once, which makes this the
     * role-side twin of `account.roles_changed` and recorded the same way: permissions
     * added and removed, not the resulting set. Permission names are catalogue
     * identifiers (ADR 0014), not values the redaction rule protects.
     */
    public const ROLE_PERMISSIONS_CHANGED = 'role.permissions_changed';

    /**
     * A role was removed, and with it every grant it made.
     *
     * The context keeps what nothing else will once the row is gone: the identifier,
     * the permissions it carried, and how many accounts held it.
     */
    public const ROLE_DELETED = 'role.deleted';

    /**
     * Content was translated, by hand or by accepting an AI suggestion (ADR 0048 §6).
     *
     * The context names the source, the item, the language, the fields that changed,
     * and whether a person wrote the text or accepted a suggestion — edited or not. It
     * never carries the wording, old or new: the content holds that, and a trail that
     * copied it would be a second, unversioned copy of every translation.
     */
    public const TRANSLATION_UPDATED = 'translation.updated';

    /**
     * An AI provider's configuration was saved: its model, whether it is enabled, and
     * whether its API key was set or replaced.
     *
     * The key is never recorded, in any form — only that it changed. The model is a
     * vendor's public identifier and is recorded, because "which model answered" is a
     * question the trail exists to answer.
     */
    public const AI_PROVIDER_SAVED = 'ai.provider_saved';

    /**
     * An AI provider's API key was removed.
     */
    public const AI_PROVIDER_KEY_REMOVED = 'ai.provider_key_removed';

    /**
     * A different AI provider now answers the platform's AI tasks.
     */
    public const AI_DEFAULT_CHANGED = 'ai.default_changed';

    private function __construct() {}
}
