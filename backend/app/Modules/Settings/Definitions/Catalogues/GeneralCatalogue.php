<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingReach;
use App\Modules\Settings\Enums\SettingType;

/**
 * Site-level settings every deployment has, whatever it is for.
 *
 * The text a visitor reads is declared `isLocalized` rather than as a key per
 * language. ADR 0018 rejects `site_name_ar` / `site_name_en` explicitly: the
 * language set is administrator-managed at runtime (ADR 0015), so a key per
 * language would make adding a language a migration.
 *
 * Nothing here is bootstrap-critical. The application starts, connects to its
 * database and serves an error page without reading any of it, which is the line
 * ADR 0034's ENV boundary draws — `APP_URL`, the database DSN and the encryption
 * key stay in the environment because they are needed before a setting can be read
 * at all.
 */
class GeneralCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return array_merge(
            $this->identity(),
            $this->contact(),
            $this->urls(),
            $this->footer(),
            $this->operations(),
        );
    }

    /**
     * What the platform calls itself.
     *
     * @return array<int, SettingDefinition>
     */
    private function identity(): array
    {
        return [
            new SettingDefinition(
                group: 'general',
                key: 'site_name',
                type: SettingType::STRING,
                default: 'AlphaMaster Enterprise',
                nullable: false,
                rules: ['string', 'max:150'],
                isPublic: true,
                isLocalized: true,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'site_description',
                type: SettingType::STRING,
                default: 'Modern Modular SaaS & Foundation',
                rules: ['string', 'max:500'],
                isPublic: true,
                isLocalized: true,
                reach: SettingReach::AWAITING,
            ),
        ];
    }

    /**
     * How to reach whoever runs the deployment.
     *
     * The phone list is JSON rather than a delimited string: a comma is a legitimate
     * character in a phone label, and splitting on one is how a list silently loses
     * an entry.
     *
     * @return array<int, SettingDefinition>
     */
    private function contact(): array
    {
        return [
            new SettingDefinition(
                group: 'general',
                key: 'official_email',
                type: SettingType::EMAIL,
                rules: ['email:rfc', 'max:255'],
                isPublic: true,
                reach: SettingReach::AWAITING,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'contact_phones',
                type: SettingType::JSON,
                default: [],
                rules: ['array', 'max:10'],
                isPublic: true,
                reach: SettingReach::AWAITING,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'contact_person',
                type: SettingType::STRING,
                rules: ['string', 'max:150'],
                isLocalized: true,
                reach: SettingReach::AWAITING,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'contact_job_title',
                type: SettingType::STRING,
                rules: ['string', 'max:150'],
                isLocalized: true,
                reach: SettingReach::AWAITING,
            ),
            // Coordinates are floats rather than a single JSON pair, so each can be
            // range-validated on its own and a half-supplied location is visible.
            new SettingDefinition(
                group: 'general',
                key: 'location_latitude',
                type: SettingType::FLOAT,
                rules: ['numeric', 'between:-90,90'],
                isPublic: true,
                reach: SettingReach::AWAITING,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'location_longitude',
                type: SettingType::FLOAT,
                rules: ['numeric', 'between:-180,180'],
                isPublic: true,
                reach: SettingReach::AWAITING,
            ),
        ];
    }

    /**
     * Where the deployment lives.
     *
     * `site_url` is the public canonical origin, which is not the same thing as
     * `APP_URL`: the framework needs an origin before any setting can be read, so
     * `APP_URL` stays in the environment and this is what a canonical link, an email
     * and a sitemap use. They will usually agree; a deployment behind a proxy or a
     * vanity domain is where they do not.
     *
     * @return array<int, SettingDefinition>
     */
    private function urls(): array
    {
        return [
            new SettingDefinition(
                group: 'general',
                key: 'site_url',
                type: SettingType::URL,
                rules: ['url:http,https', 'max:255'],
                isPublic: true,
                reach: SettingReach::AWAITING,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'frontend_url',
                type: SettingType::URL,
                rules: ['url:http,https', 'max:255'],
                isPublic: true,
                reach: SettingReach::AWAITING,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'admin_url',
                type: SettingType::URL,
                rules: ['url:http,https', 'max:255'],
                reach: SettingReach::AWAITING,
            ),
        ];
    }

    /**
     * @return array<int, SettingDefinition>
     */
    private function footer(): array
    {
        return [
            new SettingDefinition(
                group: 'general',
                key: 'footer_copyright',
                type: SettingType::STRING,
                rules: ['string', 'max:255'],
                isPublic: true,
                isLocalized: true,
                reach: SettingReach::AWAITING,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'footer_text',
                type: SettingType::STRING,
                rules: ['string', 'max:2000'],
                isPublic: true,
                isLocalized: true,
                reach: SettingReach::AWAITING,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'cookie_message',
                type: SettingType::STRING,
                rules: ['string', 'max:2000'],
                isPublic: true,
                isLocalized: true,
                reach: SettingReach::AWAITING,
            ),
        ];
    }

    /**
     * Runtime switches an operator flips without a deploy.
     *
     * Maintenance mode is a setting rather than Laravel's `down` file because the
     * platform must be repairable through its own API: ADR 0018 requires the 503 to
     * carry the platform error envelope and a localized message, and an administrator
     * holding the bypass to get past it.
     *
     * @return array<int, SettingDefinition>
     */
    private function operations(): array
    {
        return [
            new SettingDefinition(
                group: 'general',
                key: 'maintenance_mode',
                type: SettingType::BOOLEAN,
                default: false,
                nullable: false,
                isPublic: true,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'maintenance_message',
                type: SettingType::STRING,
                rules: ['string', 'max:1000'],
                isPublic: true,
                isLocalized: true,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'maintenance_admin_bypass',
                type: SettingType::BOOLEAN,
                default: true,
                nullable: false,
            ),
            new SettingDefinition(
                group: 'general',
                key: 'comments_enabled',
                type: SettingType::BOOLEAN,
                default: false,
                nullable: false,
                isPublic: true,
                reach: SettingReach::AWAITING,
            ),
        ];
    }
}
