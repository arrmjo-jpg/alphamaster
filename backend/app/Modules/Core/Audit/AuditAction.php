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
     * An account's identity was changed, by an administrator or by the account holder.
     *
     * The context names the fields that changed and no value of any of them, which
     * is the same rule the secret actions follow and for the same reason: the trail
     * is readable by anyone holding `audit.view`, and a directory of every operator's
     * address and telephone number is not something it should become. A change the
     * holder made to their own profile carries `by_account_holder: true` (ADR 0057).
     */
    public const ACCOUNT_UPDATED = 'account.updated';

    /**
     * An account's password was set or changed by its holder (ADR 0057).
     *
     * That it happened, whether one existed before, and how many other sessions were signed
     * out. Never the password, and nothing derived from one.
     */
    public const ACCOUNT_PASSWORD_CHANGED = 'account.password_changed';

    /**
     * An account's profile picture was set or replaced. The context names the media id.
     */
    public const ACCOUNT_AVATAR_CHANGED = 'account.avatar_changed';

    /**
     * An account's profile picture was removed.
     */
    public const ACCOUNT_AVATAR_REMOVED = 'account.avatar_removed';

    /**
     * A second factor now guards an account. The context names the method, never its secret
     * or recovery codes. Not an authentication event: it changes who can sign in, once.
     */
    public const ACCOUNT_MFA_ENABLED = 'account.mfa_enabled';

    /**
     * An account's second factor was disabled. The context says whether every session was
     * signed out, which is what happens to an administrator.
     */
    public const ACCOUNT_MFA_DISABLED = 'account.mfa_disabled';

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

    /**
     * A vendor provider's configuration was saved outside the AI control centre: which
     * of its fields changed, and whether its credentials were set, replaced or cleared.
     *
     * Names, never values, and nothing derived from a credential — not a fragment, not a
     * hash, not a length. That the Firebase service account was replaced is the fact an
     * operator needs after a key rotation; what it contains is not the trail's to keep.
     */
    public const INTEGRATION_PROVIDER_UPDATED = 'integration.provider_updated';

    /**
     * An administrator raised an announcement: to which audience, and how many
     * recipients it was queued for. The wording is in every recipient's in-app record
     * and is not copied here.
     */
    public const ANNOUNCEMENT_SENT = 'notification.announcement_sent';

    /**
     * A social identity can now sign in to an account (ADR 0050 §9, ADR 0037 extension
     * of 2026-09-13).
     *
     * Recorded even when the account holder rather than an administrator linked it: the
     * subject is who may sign in, not who changed it. The context names the provider, the
     * identity's identifier and whether this was a re-link — never the address and never
     * the provider's subject, both of which identify a person.
     */
    public const ACCOUNT_SOCIAL_LINKED = 'account.social_linked';

    /**
     * A social identity can no longer sign in to an account.
     *
     * The context carries how many identities remain linked, so the record with a count of
     * zero answers when the account stopped being blocked from promotion.
     */
    public const ACCOUNT_SOCIAL_UNLINKED = 'account.social_unlinked';

    /**
     * Promotion refused while a social identity was linked (ADR 0050 §6).
     *
     * The one refusal the trail records: an attempt to make an administrator of an account
     * a third-party identity can sign in to. Written after the promotion's own transaction
     * has rolled back, because the refusal is what rolls it back.
     */
    public const ACCOUNT_PROMOTION_REFUSED = 'account.promotion_refused';

    /**
     * A social provider was used against an administrative account.
     *
     * Only for the two reasons ADR 0037 admits — the matched account is an administrator,
     * or the provider's address matches one. Every other social refusal is an
     * authentication event and stays out.
     */
    public const AUTH_SOCIAL_REFUSED = 'auth.social_refused';

    /**
     * An administrator invalidated one cache namespace (ADR 0035, ADR 0051 §7).
     */
    public const CACHE_NAMESPACE_FLUSHED = 'cache.namespace_flushed';

    /**
     * An administrator asked the edge to purge named objects (ADR 0036, ADR 0053). The
     * outcome is on the purge request rows; this records who asked for what.
     */
    public const CDN_PURGE_REQUESTED = 'cdn.purge_requested';

    /**
     * An administrator asked the edge to purge everything. Its own action, because it is
     * the incident tool ADR 0036 requires to be separately visible.
     */
    public const CDN_PURGE_EVERYTHING_REQUESTED = 'cdn.purge_everything_requested';

    /**
     * What the vendor answered to a purge of everything, written by the worker when the
     * request is final (ADR 0036: the provider's result is recorded, not assumed). The
     * actor is empty because a worker has none; `requested_by` names the operator.
     */
    public const CDN_PURGE_EVERYTHING_COMPLETED = 'cdn.purge_everything_completed';

    /**
     * An administrator asked for a media analysis by hand (ADR 0054). Analyses a module
     * requests are not audited: the analysis row is their record.
     */
    public const MEDIA_ANALYSIS_REQUESTED = 'media.analysis_requested';

    /** A person recorded a review of a media analysis (ADR 0054). */
    public const MEDIA_ANALYSIS_REVIEWED = 'media.analysis_reviewed';

    /** An administrator put a failed purge back in the queue. */
    public const CDN_PURGE_RETRIED = 'cdn.purge_retried';

    /** An administrator asked the CDN vendor about the configured scope. */
    public const CDN_SCOPE_VERIFIED = 'cdn.scope_verified';

    private function __construct() {}
}
