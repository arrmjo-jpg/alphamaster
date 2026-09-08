<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * Authentication behaviour an operator may change at runtime.
 *
 * Not to be confused with bootstrap-critical authentication configuration — the
 * guard, the token driver, the encryption key — which stays in ENV because the
 * application must be able to authenticate before it can read a setting.
 */
class AuthCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            new SettingDefinition(
                group: 'auth',
                key: 'registration_enabled',
                type: SettingType::BOOLEAN,
                default: true,
                nullable: false,
                isPublic: true,
            ),
            new SettingDefinition(
                group: 'auth',
                key: 'password_min_length',
                type: SettingType::INTEGER,
                default: 8,
                nullable: false,
                rules: ['integer', 'min:8', 'max:128'],
                isPublic: true,
            ),
            // Public because the sign-in page is unauthenticated and has to know
            // whether to render a widget at all. That is not a leak: whether a login
            // form shows a captcha is visible to anyone who loads the page.
            //
            // Off by default. A captcha that is switched on before a provider has
            // credentials would refuse every sign-in, so the switch and the vendor
            // configuration are separate acts and the operator does them in that
            // order.
            new SettingDefinition(
                group: 'auth',
                key: 'captcha_enabled',
                type: SettingType::BOOLEAN,
                default: false,
                nullable: false,
                isPublic: true,
            ),
            // The site key, which a browser must be given to render the widget — it
            // is public by construction and is not a credential. The secret key is,
            // and lives encrypted on the provider row with every other vendor
            // credential (ADR 0017, ADR 0039); it is deliberately not a setting.
            new SettingDefinition(
                group: 'auth',
                key: 'captcha_site_key',
                type: SettingType::STRING,
                rules: ['string', 'max:255'],
                isPublic: true,
            ),
            new SettingDefinition(
                group: 'auth',
                key: 'session_lifetime',
                type: SettingType::INTEGER,
                default: 120,
                nullable: false,
                rules: ['integer', 'min:1', 'max:20160'],
            ),
        ];
    }
}
