<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Media\Contracts\CdnUrlResolverContract;
use App\Modules\Media\Models\MediaFile;

/**
 * Rewrites storage URLs to a CDN base, when one is configured.
 *
 * Reads the cdn settings group rather than owning configuration of its own, so there
 * is one place a CDN is configured (brief section 20). No vendor is named: a base URL
 * and a switch describe every CDN that fronts an origin, and a provider driver is
 * only needed for capabilities like cache invalidation, which nothing yet uses.
 *
 * Private media is never rewritten. Its URLs are signed and expiring, and putting a
 * shared cache in front of a credential-bearing URL is how private files stop being
 * private.
 */
class SettingsCdnUrlResolver implements CdnUrlResolverContract
{
    public function isEnabled(): bool
    {
        return (bool) setting('cdn.enabled', false) && $this->baseUrl() !== '';
    }

    public function resolve(MediaFile $media, string $storageUrl): string
    {
        if (! $this->isEnabled() || ! $media->isPubliclyReadable()) {
            return $storageUrl;
        }

        $path = (string) parse_url($storageUrl, PHP_URL_PATH);

        if ($path === '') {
            return $storageUrl;
        }

        return rtrim($this->baseUrl(), '/').'/'.ltrim($path, '/');
    }

    private function baseUrl(): string
    {
        $configured = setting('cdn.base_url', '');

        return is_string($configured) ? trim($configured) : '';
    }
}
