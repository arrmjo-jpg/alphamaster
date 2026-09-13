<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingReach;
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
                reach: SettingReach::AWAITING,
            ),
            // The one-time code policy, read in one place by `OtpPolicy` so the code
            // an MFA challenge sends and the code that confirms a phone number cannot
            // drift into two different policies.
            //
            // None of the four is public. A sign-in page needs the password minimum to
            // render a form; nothing unauthenticated needs to know how long a code
            // lives or how many wrong answers it survives, and publishing the attempt
            // limit tells an attacker exactly how much room they have.
            new SettingDefinition(
                group: 'auth',
                key: 'otp_length',
                type: SettingType::INTEGER,
                default: 6,
                nullable: false,
                // Four is the shortest length in real use; beyond eight a recipient
                // starts transcribing rather than reading.
                rules: ['integer', 'between:4,8'],
            ),
            new SettingDefinition(
                group: 'auth',
                key: 'otp_lifetime_seconds',
                type: SettingType::INTEGER,
                default: 300,
                nullable: false,
                // A minute is the floor because a message has to arrive first, and a
                // quarter of an hour is the ceiling because a code is not a session.
                rules: ['integer', 'between:60,900'],
            ),
            new SettingDefinition(
                group: 'auth',
                key: 'otp_resend_cooldown_seconds',
                type: SettingType::INTEGER,
                default: 30,
                nullable: false,
                rules: ['integer', 'between:15,300'],
            ),
            new SettingDefinition(
                group: 'auth',
                key: 'otp_max_attempts',
                type: SettingType::INTEGER,
                default: 5,
                nullable: false,
                rules: ['integer', 'between:3,10'],
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
            // Which reCAPTCHA the site key belongs to.
            //
            // The verifier already accepts either — it applies `minimum_score` when
            // the vendor returns one, which is v3's shape — but the two are entirely
            // different in the browser: v2 renders a checkbox and yields a token when
            // it is ticked, while v3 renders nothing and a token is executed at
            // submit. A client cannot infer which from a site key, and guessing wrong
            // produces a sign-in page that cannot be submitted with nothing on it to
            // explain why. That happened, which is why this exists.
            //
            // Public for the same reason the switch and the site key are: the
            // unauthenticated sign-in page is what has to act on it, and which
            // challenge a login form shows is visible to anyone who loads it.
            //
            // Defaults to v2, the version a fresh installation is most likely to be
            // handed and the one that fails visibly rather than silently.
            new SettingDefinition(
                group: 'auth',
                key: 'captcha_version',
                type: SettingType::STRING,
                default: 'v2',
                nullable: false,
                rules: ['string', 'in:v2,v3'],
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
