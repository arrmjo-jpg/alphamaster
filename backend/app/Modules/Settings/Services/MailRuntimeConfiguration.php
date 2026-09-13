<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Contracts\SettingServiceInterface;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The mail configuration an operator entered, applied to the mail the platform sends.
 *
 * Eleven settings described an SMTP connection and exactly one thing read them: the
 * "send a test message" button. Everything else — email verification, notifications,
 * announcements — went out through `config/mail.php`, which is env-driven and cannot
 * be reached from the console. An operator could configure a mail server, watch the
 * test message arrive, and still have every real message leave through whatever the
 * deployment's environment file happened to say.
 *
 * This closes that. The settings are applied to the mailer the rest of the platform
 * resolves, so the console's configuration is the platform's configuration.
 *
 * Three properties are worth stating.
 *
 * **It is lazy.** Applied when something first resolves the mail manager, not at boot,
 * so a request that sends no mail reads no settings and a console command that runs
 * before the settings table exists never asks for one.
 *
 * **It fails open, to the environment.** If the settings store cannot be read the
 * environment's configuration stands, which is the state every deployment starts in
 * and the same precedent the rate limiter and the maintenance middleware set. A
 * platform whose mail configuration lives in a database should still send mail when
 * the database is unreachable — or, at worst, fail for the reason it would have failed
 * anyway rather than for a new one.
 *
 * **`mail.enabled` is the switch, and it means what it says.** Off, and nothing here
 * applies: the deployment's own configuration is used, unchanged. That is deliberately
 * not "off means send nothing" — suppressing mail is `MAIL_MAILER=log`, which a
 * deployment already has, and quietly swallowing a password-reset message is a worse
 * failure than sending it from the wrong host.
 */
class MailRuntimeConfiguration
{
    public function __construct(
        private readonly SettingServiceInterface $settings,
        private readonly Config $config,
    ) {}

    /**
     * Apply the stored configuration over the environment's, where there is one.
     */
    public function apply(): void
    {
        try {
            if ($this->settings->get('mail.enabled') !== true) {
                return;
            }

            $host = $this->settings->get('mail.host');
            $from = $this->settings->get('mail.from_address');

            // `mail.enabled` declares these as its dependencies, and a half-configured
            // connection is worse than none: it would replace a working environment
            // mailer with one that cannot connect.
            if (! $this->isFilled($host) || ! $this->isFilled($from)) {
                return;
            }

            $this->config->set($this->mailer($host));
            $this->config->set($this->sender($from));
        } catch (Throwable $e) {
            // The environment's configuration stands. Logged rather than swallowed,
            // because an operator who configured a mail server and sees mail leaving
            // from somewhere else needs a reason to exist somewhere.
            Log::warning('Stored mail configuration could not be applied; the environment’s stands.', [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The transport, as `config/mail.php` describes one.
     *
     * `log` and `array` take no connection parameters — writing host and credentials
     * into them would be describing a connection that is never opened.
     *
     * @return array<string, mixed>
     */
    private function mailer(mixed $host): array
    {
        $transport = (string) ($this->settings->get('mail.transport') ?? 'smtp');

        if ($transport !== 'smtp') {
            return [
                'mail.default' => $transport,
                'mail.mailers.'.$transport => ['transport' => $transport],
            ];
        }

        $encryption = (string) ($this->settings->get('mail.encryption') ?? 'tls');

        return [
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => (string) $host,
                'port' => (int) ($this->settings->get('mail.port') ?? 587),
                // "none" is the platform's word for it; Laravel's is the absence of
                // the key, and passing the string through would configure a transport
                // encryption scheme by that name.
                'encryption' => $encryption === 'none' ? null : $encryption,
                'username' => $this->settings->get('mail.username'),
                'password' => $this->settings->get('mail.password'),
                // The same ceiling every other outbound call obeys (ADR 0017): an
                // unreachable mail host must not hold a request open indefinitely.
                'timeout' => (int) ($this->settings->get('operations.provider_timeout_seconds') ?? 10),
            ],
        ];
    }

    /**
     * Who the mail is from, and where a reply goes.
     *
     * The sender name is localized, so it is read in the locale of whoever the mail is
     * being rendered for — which is the request's locale, and for a queued notification
     * the locale that was carried onto the job.
     *
     * @return array<string, mixed>
     */
    private function sender(mixed $from): array
    {
        $applied = [
            'mail.from.address' => (string) $from,
            'mail.from.name' => (string) ($this->settings->get('mail.from_name') ?? config('app.name')),
        ];

        $replyTo = $this->settings->get('mail.reply_to');

        if ($this->isFilled($replyTo)) {
            // A reply-to is only meaningful when it differs from the sender, but the
            // platform does not second-guess an operator who set both to the same
            // address: it is a valid, if redundant, thing to say.
            $applied['mail.reply_to'] = ['address' => (string) $replyTo, 'name' => $applied['mail.from.name']];
        }

        return $applied;
    }

    private function isFilled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
