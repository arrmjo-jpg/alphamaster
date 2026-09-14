<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Core\Contracts\EdgeCacheContract;
use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Delivery\EdgeInvalidationReceipt;
use App\Modules\Integration\Contracts\CdnProviderContract;
use App\Modules\Integration\Enums\CdnPurgeStatus;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Jobs\ProcessCdnPurgeRequest;
use App\Modules\Integration\Models\CdnPurgeRequest;
use App\Modules\Integration\Models\IntegrationProvider;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The edge cache, backed by the configured CDN driver (ADR 0053).
 *
 * An invalidation is split to the vendor's per-call limit, recorded as one row per call
 * inside a transaction, and dispatched to the queue after commit. Nothing here calls the
 * vendor: a purge is an outbound request with its own failures and rate limits, and a
 * content change must not wait on it or fail because of it. The worker does the calling,
 * and every outcome stays on the row.
 *
 * Verification results live on the provider row under `detected_*` keys, written only
 * here, so the admin controller and the limits read one place.
 */
class CdnEdgeCache implements EdgeCacheContract
{
    /** How long the tag header answer is trusted within one process. */
    private const TAG_HEADER_TTL_SECONDS = 60;

    private ?string $tagHeader = null;

    private ?int $tagHeaderAt = null;

    public function __construct(private readonly CdnManager $manager) {}

    public function invalidate(EdgeInvalidation $invalidation, ?string $reason = null): EdgeInvalidationReceipt
    {
        $provider = $this->usableProvider();

        if ($provider === null) {
            return EdgeInvalidationReceipt::notConfigured();
        }

        $limits = $this->driverFor($provider)->limits($provider);

        if (! $limits->supports($invalidation->kind)) {
            return new EdgeInvalidationReceipt(EdgeInvalidationReceipt::UNSUPPORTED);
        }

        $requestedBy = auth()->user()?->getAuthIdentifier();

        $ids = DB::transaction(function () use ($invalidation, $limits, $provider, $reason, $requestedBy): array {
            $ids = [];

            foreach ($invalidation->chunk($limits->itemsPerRequest($invalidation->kind)) as $piece) {
                $ids[] = CdnPurgeRequest::query()->create([
                    'integration_provider_id' => $provider->id,
                    'driver' => $provider->driver,
                    'kind' => $piece->kind,
                    'items' => $piece->items,
                    'item_count' => $piece->count(),
                    'status' => CdnPurgeStatus::PENDING,
                    'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
                    'requested_by' => is_string($requestedBy) ? $requestedBy : null,
                ])->id;
            }

            return $ids;
        });

        foreach ($ids as $id) {
            ProcessCdnPurgeRequest::dispatch($id)->afterCommit();
        }

        return new EdgeInvalidationReceipt(EdgeInvalidationReceipt::QUEUED, $ids);
    }

    public function tagHeader(): ?string
    {
        $now = time();

        if ($this->tagHeaderAt === null || $now - $this->tagHeaderAt >= self::TAG_HEADER_TTL_SECONDS) {
            $provider = $this->usableProvider();
            $this->tagHeader = $provider === null ? null : $this->driverFor($provider)->tagHeader();
            $this->tagHeaderAt = $now;
        }

        return $this->tagHeader;
    }

    /**
     * The provider the edge is invalidated through: the default, active, fully configured.
     */
    public function usableProvider(): ?IntegrationProvider
    {
        $provider = $this->manager->defaultProvider();

        if ($provider === null || ! $this->hasDriver($provider)) {
            return null;
        }

        return $this->driverFor($provider)->missingConfiguration($provider) === [] ? $provider : null;
    }

    /**
     * The CDN row an operator configures, active or not: the default first.
     */
    public function configuredProvider(): ?IntegrationProvider
    {
        return IntegrationProvider::query()
            ->forCapability(IntegrationCapability::CDN)
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->first();
    }

    public function driverFor(IntegrationProvider $provider): CdnProviderContract
    {
        $driver = $this->manager->driver($provider->driver);

        if (! $driver instanceof CdnProviderContract) {
            throw new InvalidArgumentException("[{$provider->driver}] is not a CDN driver.");
        }

        return $driver;
    }

    public function hasDriver(IntegrationProvider $provider): bool
    {
        try {
            $this->driverFor($provider);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function missingConfiguration(IntegrationProvider $provider): array
    {
        return $this->hasDriver($provider)
            ? $this->driverFor($provider)->missingConfiguration($provider)
            : ['driver'];
    }

    /**
     * What an operator must type to purge everything: the verified scope's name.
     *
     * Null until the scope has been verified, so an unverified configuration cannot be
     * emptied by confirming a value nobody checked.
     */
    public function confirmationPhrase(IntegrationProvider $provider): ?string
    {
        $name = $provider->settings['detected_scope_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Keep what verification detected only while the settings it verified are unchanged.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array<string, mixed>
     */
    public function retainVerification(IntegrationProvider $provider, ?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];
        $fields = $this->hasDriver($provider) ? $this->driverFor($provider)->configurationFields()['settings'] : [];

        $unchanged = true;
        foreach ($fields as $field) {
            if (array_key_exists($field, $after) && ($after[$field] ?? null) !== ($before[$field] ?? null)) {
                $unchanged = false;
            }
        }

        $kept = array_filter(
            $before,
            static fn (string $key): bool => str_starts_with($key, 'detected_') || $key === 'verified_at',
            ARRAY_FILTER_USE_KEY,
        );

        $configured = array_filter(
            $after,
            static fn (string $key): bool => ! str_starts_with($key, 'detected_') && $key !== 'verified_at',
            ARRAY_FILTER_USE_KEY,
        );

        return $unchanged ? array_merge($configured, $kept) : $configured;
    }
}
