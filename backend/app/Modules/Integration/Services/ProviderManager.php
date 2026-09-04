<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Manager;

/**
 * Base for the per-capability managers (ADR 0017).
 *
 * Extends Laravel's Manager so driver creation follows the framework's own
 * convention, but takes its default driver from the database rather than from a
 * config file: that is what allows a vendor to be swapped from the Admin UI without
 * a deploy.
 */
abstract class ProviderManager extends Manager
{
    /**
     * The capability this manager resolves providers for.
     */
    abstract public function capability(): IntegrationCapability;

    /**
     * The default driver, read from the configured providers.
     *
     * Manager::driver() calls this when no driver is named. It must return a string,
     * so when nothing is configured it returns the fallback driver name and the
     * dispatcher reports the absence with a clearer error than a missing-driver one.
     */
    public function getDefaultDriver(): string
    {
        // ?? reads the property in isset context, which already short-circuits on a
        // null left-hand side, so ?-> adds nothing here.
        return $this->defaultProvider()->driver ?? 'log';
    }

    /**
     * The provider row marked default for this capability, if it is active.
     */
    public function defaultProvider(): ?IntegrationProvider
    {
        return $this->providerQuery()
            ->where('is_default', true)
            ->first();
    }

    /**
     * Every active provider for this capability, default first, then by priority.
     *
     * This is the failover order. It is derived rather than stored so that changing
     * which provider is default reorders the chain with no further bookkeeping.
     *
     * @return Collection<int, IntegrationProvider>
     */
    public function providerChain(): Collection
    {
        return $this->providerQuery()
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('driver')
            ->get();
    }

    /**
     * Base query for usable providers.
     */
    protected function providerQuery()
    {
        return IntegrationProvider::query()
            ->forCapability($this->capability())
            ->active();
    }
}
