<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions;

/**
 * What one synchronisation run did, and what it refused to do.
 *
 * The refusals matter more than the changes. A synchroniser that silently deleted
 * an orphaned row, or silently re-typed a configured one, would destroy operator
 * configuration for reasons that have nothing to do with intent — a bad merge, a
 * module removed from a build, a branch deployed out of order (ADR 0018). So it
 * reports instead, and this is what it reports with.
 */
final class SynchronisationReport
{
    /** @var array<int, string> rows created from a definition that had none */
    private array $created = [];

    /** @var array<int, string> rows whose declared attributes were brought up to date */
    private array $updated = [];

    /** @var array<int, string> rows already matching their definition */
    private array $unchanged = [];

    /** @var array<int, string> rows whose definition no longer exists or is retired */
    private array $orphaned = [];

    /** @var array<string, string> rows a definition contradicts, by reference => reason */
    private array $conflicts = [];

    public function created(string $reference): void
    {
        $this->created[] = $reference;
    }

    public function updated(string $reference): void
    {
        $this->updated[] = $reference;
    }

    public function unchanged(string $reference): void
    {
        $this->unchanged[] = $reference;
    }

    public function orphaned(string $reference): void
    {
        $this->orphaned[] = $reference;
    }

    public function conflict(string $reference, string $reason): void
    {
        $this->conflicts[$reference] = $reason;
    }

    /** @return array<int, string> */
    public function createdReferences(): array
    {
        return $this->created;
    }

    /** @return array<int, string> */
    public function updatedReferences(): array
    {
        return $this->updated;
    }

    /** @return array<int, string> */
    public function unchangedReferences(): array
    {
        return $this->unchanged;
    }

    /** @return array<int, string> */
    public function orphanedReferences(): array
    {
        return $this->orphaned;
    }

    /** @return array<string, string> */
    public function conflictReferences(): array
    {
        return $this->conflicts;
    }

    /**
     * Whether this run changed anything at all.
     *
     * A second run over an unchanged registry must answer false, which is what makes
     * the synchroniser safe to run on every deploy.
     */
    public function changedAnything(): bool
    {
        return $this->created !== [] || $this->updated !== [];
    }

    /**
     * Whether an operator needs to look at something.
     *
     * Orphans and conflicts are not failures — the run completed and the platform is
     * consistent. They are decisions only a person can take.
     */
    public function needsAttention(): bool
    {
        return $this->orphaned !== [] || $this->conflicts !== [];
    }

    /**
     * The report as a structure, for a command or an API to render.
     *
     * Carries references only. A report naming a setting never carries its value,
     * which for a secret would be a copy of the credential in whatever the report
     * was written to (ADR 0018, ADR 0037).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'orphaned' => $this->orphaned,
            'conflicts' => $this->conflicts,
        ];
    }
}
