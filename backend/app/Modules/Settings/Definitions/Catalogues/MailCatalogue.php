<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * SMTP configuration, as platform settings rather than as a provider.
 *
 * ADR 0018 settled where this belongs. The boundary in ADR 0017 is a vendor whose
 * behaviour differs per provider and which the platform selects between at runtime
 * with failover; SMTP is not that. It is one transport with one set of connection
 * parameters, and Laravel already abstracts the mailer, so putting it behind the
 * provider manager would model a choice the platform does not make.
 *
 * An API-driven transactional sender — SendGrid, Postmark, SES — is the reverse case
 * and does belong behind ADR 0017 as an `email` capability. Nothing here forecloses
 * that: `mail.transport` is what selects between them.
 */
class MailCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            new SettingDefinition(
                group: 'mail',
                key: 'enabled',
                type: SettingType::BOOLEAN,
                default: false,
                nullable: false,
                dependsOn: ['mail.host', 'mail.from_address'],
            ),
            // Not a provider selector — a transport selector. `smtp` is configured
            // here; anything API-driven resolves through ADR 0017 instead.
            new SettingDefinition(
                group: 'mail',
                key: 'transport',
                type: SettingType::STRING,
                default: 'smtp',
                nullable: false,
                rules: ['string', 'in:smtp,log,array'],
            ),
            new SettingDefinition(
                group: 'mail',
                key: 'host',
                type: SettingType::STRING,
                rules: ['string', 'max:255'],
            ),
            new SettingDefinition(
                group: 'mail',
                key: 'port',
                type: SettingType::INTEGER,
                default: 587,
                rules: ['integer', 'between:1,65535'],
            ),
            new SettingDefinition(
                group: 'mail',
                key: 'encryption',
                type: SettingType::STRING,
                default: 'tls',
                rules: ['string', 'in:tls,ssl,none'],
            ),
            new SettingDefinition(
                group: 'mail',
                key: 'username',
                type: SettingType::STRING,
                rules: ['string', 'max:255'],
            ),
            // Encrypted at rest, masked in every response, never logged and never
            // written to an audit record (ADR 0018, ADR 0037). Provisioned unset.
            new SettingDefinition(
                group: 'mail',
                key: 'password',
                type: SettingType::STRING,
                isSecret: true,
            ),
            new SettingDefinition(
                group: 'mail',
                key: 'from_address',
                type: SettingType::EMAIL,
                rules: ['email:rfc', 'max:255'],
            ),
            new SettingDefinition(
                group: 'mail',
                key: 'from_name',
                type: SettingType::STRING,
                rules: ['string', 'max:150'],
                isLocalized: true,
            ),
            new SettingDefinition(
                group: 'mail',
                key: 'reply_to',
                type: SettingType::EMAIL,
                rules: ['email:rfc', 'max:255'],
            ),
            // Where a verification message is sent. A setting rather than a request
            // parameter so that testing a configuration cannot be turned into sending
            // mail to an arbitrary address chosen by whoever called the endpoint.
            new SettingDefinition(
                group: 'mail',
                key: 'test_recipient',
                type: SettingType::EMAIL,
                rules: ['email:rfc', 'max:255'],
            ),
        ];
    }
}
