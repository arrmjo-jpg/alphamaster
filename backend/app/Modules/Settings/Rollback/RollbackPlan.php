<?php

declare(strict_types=1);

namespace App\Modules\Settings\Rollback;

/**
 * What a rollback would do, decided before any of it is done (ADR 0040).
 *
 * A plan exists because the authorization question cannot be asked until the answer
 * to "which settings does this touch" is known. A rollback names a point in time, not
 * a list of keys; the keys are derived from history. So the service computes the plan,
 * the controller authorizes every key in it, and only then is the plan applied.
 *
 * Splitting it this way also means the expensive, fallible part — reading history,
 * revalidating against today's declarations — happens outside the write transaction,
 * and the transaction contains only writes.
 *
 * Immutable: a plan describes a decision, and one that can be edited between being
 * authorized and being applied is an authorization bypass wearing a value object.
 */
final class RollbackPlan
{
    /**
     * @param  list<RollbackChange>  $changes  settings that will be written
     * @param  list<RollbackSkip>  $skipped  settings deliberately left alone, with why
     */
    public function __construct(
        public readonly string $group,
        public readonly string $targetRevisionId,
        public readonly array $changes = [],
        public readonly array $skipped = [],
    ) {}

    /**
     * Whether applying this would change anything.
     *
     * A rollback to a state the group is already in is not an error — an operator
     * cannot know it is a no-op until it is computed — but it must not advance the
     * version, because a version that moves without a value moving invalidates every
     * client's cached read for nothing.
     */
    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * Every distinct setting key this plan would write.
     *
     * Distinct because a localized setting contributes one change per locale, and the
     * permission guarding it is a property of the setting rather than of the language.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_unique(array_map(
            static fn (RollbackChange $change): string => $change->key,
            $this->changes,
        )));
    }

    /**
     * The plan as the API reports it.
     *
     * Values are cast through the type each setting is declared with today rather than
     * returned as stored strings, so the response reads the way the settings endpoints
     * read. Secrets contribute no value here because they contribute no change.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'target_revision_id' => $this->targetRevisionId,
            'restored' => array_map(static fn (RollbackChange $change): array => [
                'key' => $change->key,
                'locale' => $change->locale,
                'value' => $change->typed,
            ], $this->changes),
            // Named by key so an operator has a checklist of what to re-supply or
            // reconsider, rather than discovering it when something stops working.
            'skipped' => array_map(static fn (RollbackSkip $skip): array => $skip->toArray(), $this->skipped),
        ];
    }
}
