<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Cdn;

use App\Modules\Core\Delivery\EdgeInvalidation;
use App\Modules\Core\Delivery\EdgeInvalidationKind;
use App\Modules\Integration\Contracts\CdnProviderContract;
use App\Modules\Integration\Data\CdnLimits;
use App\Modules\Integration\Data\CdnPurgeResult;
use App\Modules\Integration\Data\CdnScopeReport;
use App\Modules\Integration\Exceptions\CredentialDecryptionException;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Services\ProviderHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Cloudflare, as a CDN driver (ADR 0053 §4).
 *
 * Configured with a zone id (a setting) and an API token (a credential). The token needs
 * two permissions on the zone: Zone → Cache Purge, to purge, and Zone → Zone → Read, so
 * verification can read the zone's name, status and plan.
 *
 * Limits are Cloudflare's published ones. Every plan purges by URL, tag, host, prefix and
 * everything. One call carries at most 100 items (500 URLs on Enterprise). Purges by tag,
 * host, prefix and everything share a per-account budget that depends on the plan — five
 * a minute on Free — while URL purges have a far larger one of their own. The plan comes
 * from verification; until a zone is verified the Free limits apply, which is the safe
 * direction to be wrong in.
 */
class CloudflareCdnProvider implements CdnProviderContract
{
    public const API = 'https://api.cloudflare.com/client/v4';

    private const ZONE_ID = '/^[a-f0-9]{32}$/';

    /** Calls a minute against the shared tag/host/prefix/everything budget, per plan. */
    private const BULK_PER_MINUTE = [
        'free' => 5,
        'pro' => 300,
        'business' => 600,
        'enterprise' => 3000,
    ];

    public function configurationFields(): array
    {
        return ['settings' => ['zone_id'], 'credentials' => ['api_token']];
    }

    public function missingConfiguration(IntegrationProvider $provider): array
    {
        $missing = [];

        if (preg_match(self::ZONE_ID, $this->zoneId($provider)) !== 1) {
            $missing[] = 'zone_id';
        }

        if ($this->token($provider) === null) {
            $missing[] = 'api_token';
        }

        return $missing;
    }

    public function limits(IntegrationProvider $provider): CdnLimits
    {
        $plan = $this->plan($provider);
        $bulk = self::BULK_PER_MINUTE[$plan ?? 'free'] ?? self::BULK_PER_MINUTE['free'];

        return new CdnLimits(
            itemsPerRequest: [
                EdgeInvalidationKind::URLS->value => $plan === 'enterprise' ? 500 : 100,
                EdgeInvalidationKind::TAGS->value => 100,
                EdgeInvalidationKind::HOSTS->value => 100,
                EdgeInvalidationKind::PREFIXES->value => 100,
                EdgeInvalidationKind::EVERYTHING->value => 1,
            ],
            requestsPerMinute: [
                EdgeInvalidationKind::TAGS->value => $bulk,
                EdgeInvalidationKind::HOSTS->value => $bulk,
                EdgeInvalidationKind::PREFIXES->value => $bulk,
                EdgeInvalidationKind::EVERYTHING->value => $bulk,
            ],
            buckets: [
                EdgeInvalidationKind::TAGS->value => 'bulk',
                EdgeInvalidationKind::HOSTS->value => 'bulk',
                EdgeInvalidationKind::PREFIXES->value => 'bulk',
                EdgeInvalidationKind::EVERYTHING->value => 'bulk',
            ],
            plan: $plan,
        );
    }

    public function verify(IntegrationProvider $provider): CdnScopeReport
    {
        $token = $this->token($provider);

        if ($token === null) {
            return CdnScopeReport::unreachable('CDN_AUTH_FAILED', 'No API token is stored.');
        }

        try {
            $response = ProviderHttp::client()->withToken($token)->acceptJson()
                ->get(self::API.'/zones/'.$this->zoneId($provider));
        } catch (ConnectionException $e) {
            return CdnScopeReport::unreachable('CDN_UNREACHABLE', $e->getMessage());
        }

        if ($response->successful() && $response->json('success') === true) {
            $name = $response->json('result.name');

            return CdnScopeReport::reachable(
                is_string($name) ? $name : $this->zoneId($provider),
                is_string($response->json('result.status')) ? $response->json('result.status') : null,
                $this->normalisePlan($response->json('result.plan.legacy_id') ?? $response->json('result.plan.name')),
            );
        }

        return CdnScopeReport::unreachable(
            match (true) {
                in_array($response->status(), [401, 403], true) => 'CDN_AUTH_FAILED',
                $response->status() === 404 => 'CDN_SCOPE_NOT_FOUND',
                $response->status() === 429 => 'CDN_RATE_LIMITED',
                default => 'CDN_VENDOR_ERROR',
            },
            $this->vendorMessage($response),
        );
    }

    public function purge(EdgeInvalidation $invalidation, IntegrationProvider $provider): CdnPurgeResult
    {
        $token = $this->token($provider);

        if ($token === null) {
            return CdnPurgeResult::failure('CDN_AUTH_FAILED', 'No API token is stored.', retryable: false);
        }

        try {
            $response = ProviderHttp::client()->withToken($token)->acceptJson()
                ->post(self::API.'/zones/'.$this->zoneId($provider).'/purge_cache', $this->payload($invalidation));
        } catch (ConnectionException $e) {
            return CdnPurgeResult::failure('CDN_UNREACHABLE', $e->getMessage(), retryable: true);
        }

        if ($response->successful() && $response->json('success') === true) {
            $reference = $response->json('result.id');

            return CdnPurgeResult::success(is_string($reference) ? $reference : null);
        }

        $message = $this->vendorMessage($response);

        return match (true) {
            $response->status() === 429 => CdnPurgeResult::failure(
                'CDN_RATE_LIMITED',
                $message,
                retryable: true,
                retryAfterSeconds: $this->retryAfter($response),
            ),
            $response->status() >= 500 => CdnPurgeResult::failure('CDN_VENDOR_ERROR', $message, retryable: true),
            in_array($response->status(), [401, 403], true) => CdnPurgeResult::failure('CDN_AUTH_FAILED', $message, retryable: false),
            default => CdnPurgeResult::failure('CDN_REQUEST_REFUSED', $message, retryable: false),
        };
    }

    public function tagHeader(): ?string
    {
        return 'Cache-Tag';
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EdgeInvalidation $invalidation): array
    {
        return match ($invalidation->kind) {
            EdgeInvalidationKind::URLS => ['files' => $invalidation->items],
            EdgeInvalidationKind::TAGS => ['tags' => $invalidation->items],
            EdgeInvalidationKind::HOSTS => ['hosts' => $invalidation->items],
            // Cloudflare names a prefix without its scheme: `www.example.com/images`.
            EdgeInvalidationKind::PREFIXES => ['prefixes' => array_map(
                static fn (string $prefix): string => (string) preg_replace('#^https?://#i', '', $prefix),
                $invalidation->items,
            )],
            EdgeInvalidationKind::EVERYTHING => ['purge_everything' => true],
        };
    }

    private function zoneId(IntegrationProvider $provider): string
    {
        $zone = $provider->settings['zone_id'] ?? '';

        return is_string($zone) ? strtolower(trim($zone)) : '';
    }

    private function token(IntegrationProvider $provider): ?string
    {
        try {
            $token = $provider->getCredentials()['api_token'] ?? null;
        } catch (CredentialDecryptionException) {
            // Unreadable is unusable: reported as missing rather than sent to the vendor.
            return null;
        }

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    private function plan(IntegrationProvider $provider): ?string
    {
        return $this->normalisePlan($provider->settings['detected_plan'] ?? null);
    }

    private function normalisePlan(mixed $plan): ?string
    {
        if (! is_string($plan)) {
            return null;
        }

        $plan = strtolower($plan);

        foreach (array_keys(self::BULK_PER_MINUTE) as $known) {
            if (str_contains($plan, $known)) {
                return $known;
            }
        }

        return null;
    }

    private function vendorMessage(Response $response): string
    {
        $message = $response->json('errors.0.message');

        return is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status();
    }

    private function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) && (int) $header > 0 ? (int) $header : 60;
    }
}
