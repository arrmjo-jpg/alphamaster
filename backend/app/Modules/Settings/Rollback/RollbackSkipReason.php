<?php

declare(strict_types=1);

namespace App\Modules\Settings\Rollback;

/**
 * Why a setting in a rolled-back group was left as it is (ADR 0040).
 *
 * A rollback reports rather than fails. Refusing the whole operation because one
 * setting of twenty can no longer hold its old value would leave an operator with
 * nothing restored and a problem they cannot fix from the API; restoring the other
 * nineteen and naming the exception leaves them with a shorter list.
 *
 * Each case carries a machine-readable value for a client to branch on and a
 * translation key for a human to read, because an interface needs both.
 */
enum RollbackSkipReason: string
{
    /**
     * A credential. It has no revision to restore from and never will, which is a
     * property of the history store rather than a shortfall in this operation.
     */
    case SECRET = 'secret';

    /** The setting no longer appears in the registry, so nothing declares what it means. */
    case UNDECLARED = 'undeclared';

    /** Declared as not editable through the admin API, which a rollback does not override. */
    case NOT_EDITABLE = 'not_editable';

    /**
     * The stored value cannot be represented in the type the setting is declared with
     * today. A setting retyped from string to integer has history that is no longer
     * readable as the thing it now is.
     */
    case TYPE_CHANGED = 'type_changed';

    /**
     * The value still fits the type but no longer satisfies the declaration's rules —
     * a tightened bound, a media id that has since been deleted.
     */
    case INVALID_TODAY = 'invalid_today';

    /**
     * Restoring it would leave a capability switched on with a prerequisite that is no
     * longer satisfied. Dropped rather than written, because a rollback that produces a
     * configuration the platform cannot act on has not restored anything.
     */
    case DEPENDENCY_UNSATISFIED = 'dependency_unsatisfied';

    public function translationKey(): string
    {
        return 'settings.rollback.skipped.'.$this->value;
    }
}
