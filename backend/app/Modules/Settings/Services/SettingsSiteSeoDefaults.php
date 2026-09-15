<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Core\Contracts\LocaleResolverInterface;
use App\Modules\Core\Contracts\MediaReferenceContract;
use App\Modules\Core\Contracts\SiteSeoDefaultsContract;
use App\Modules\Core\Seo\SeoFields;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Models\Setting;

/**
 * The site's SEO defaults, read from settings (ADR 0058 §4).
 *
 * A localized value is read in the language asked for and nowhere else. A setting's ordinary
 * read falls back through other languages (ADR 0015), which suits a label and is wrong here:
 * an Arabic page with no Arabic site name must not present the English one as its title. The
 * provisioned base value is the default language's, so it answers for that language only.
 */
final class SettingsSiteSeoDefaults implements SiteSeoDefaultsContract
{
    public function __construct(
        private readonly SettingServiceInterface $settings,
        private readonly MediaReferenceContract $media,
        private readonly LocaleResolverInterface $locales,
    ) {}

    public function title(string $locale): ?string
    {
        return $this->inLocale('general', 'site_name', $locale);
    }

    public function description(string $locale): ?string
    {
        return $this->inLocale('general', 'site_description', $locale);
    }

    public function imageUrl(): ?string
    {
        $id = $this->settings->get('branding.og_image');

        return is_string($id) && $id !== '' ? $this->media->publicImage($id)?->url : null;
    }

    public function robots(): string
    {
        $policy = $this->settings->get('seo.robots_policy');

        return is_string($policy) && in_array($policy, SeoFields::ROBOTS, true) ? $policy : SeoFields::DEFAULT_ROBOTS;
    }

    public function publicOrigin(): ?string
    {
        $origin = $this->settings->get('general.frontend_url');

        return is_string($origin) && trim($origin) !== '' ? rtrim(trim($origin), '/') : null;
    }

    public function robotsExtra(): ?string
    {
        $extra = $this->settings->get('seo.robots_extra');

        return is_string($extra) && trim($extra) !== '' ? $extra : null;
    }

    private function inLocale(string $group, string $key, string $locale): ?string
    {
        /** @var Setting|null $setting */
        $setting = Setting::query()->where('group', $group)->where('key', $key)->with('translations')->first();

        if ($setting === null) {
            return null;
        }

        $value = $setting->translationIn($locale)?->getAttribute('value');

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if ($locale !== $this->locales->getDefaultLocale()) {
            return null;
        }

        $base = $setting->getAttribute('value');

        return is_string($base) && trim($base) !== '' ? $base : null;
    }
}
