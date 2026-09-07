<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Data\MailTestResult;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Verifies a mail configuration by using it.
 *
 * ADR 0018 named this as a deferred capability: verifying a configuration by
 * connecting, and sending a test message to a nominated address, both writing to the
 * audit trail and neither revealing the password.
 *
 * It really sends. A test that reported success without attempting delivery would be
 * worse than no test at all — an operator would read it as proof and stop looking,
 * which is the fake integration this project has refused throughout.
 *
 * The recipient is a setting, not a request parameter, so verifying a configuration
 * cannot be turned into sending mail to an address chosen by whoever called the
 * endpoint.
 */
class MailConfigurationTester
{
    /** The runtime mailer this builds from the stored settings. */
    private const MAILER = 'settings_test';

    /**
     * Values standing in for stored settings, for the duration of one test call.
     *
     * @var array<string, mixed>
     */
    private array $overrides = [];

    public function __construct(private readonly SettingServiceInterface $settings) {}

    /**
     * Attempt a test message, and report what happened.
     *
     * Never throws for a configuration or delivery problem: an unreachable SMTP host
     * is an outcome an operator needs described, not a 500 with a stack trace. Only a
     * programming fault would escape here, which is the distinction ADR 0017 already
     * draws for provider dispatch.
     *
     * $overrides substitutes setting references for the duration of this call and
     * nothing longer. It exists so a credential can be tried before it is stored
     * (ADR 0038): the candidate arrives as an argument, is used to build a mailer that
     * lives for one request, and is gone when the call returns. There is deliberately
     * no setter and no way to leave one behind — a rotation must have no pending state.
     *
     * @param  array<string, mixed>  $overrides  setting reference => value, for this call only
     */
    public function test(array $overrides = []): MailTestResult
    {
        $this->overrides = $overrides;

        $incomplete = $this->missingRequirements();

        if ($incomplete !== []) {
            return MailTestResult::incomplete($incomplete);
        }

        $recipient = (string) $this->value('mail.test_recipient');

        try {
            $this->configureRuntimeMailer();

            Mail::mailer(self::MAILER)->raw(
                'This is a test message from AlphaMaster, confirming that the configured mail transport works.',
                function ($message) use ($recipient): void {
                    $message->to($recipient)->subject('AlphaMaster mail configuration test');
                },
            );
        } catch (Throwable $e) {
            // The class, not the message: a transport exception can carry the host,
            // the username and occasionally the credential it tried. What an operator
            // needs is which failure it was, and the rest belongs nowhere.
            return MailTestResult::failed(class_basename($e), $recipient);
        }

        return MailTestResult::sent($recipient);
    }

    /**
     * One setting value, from the override if this call supplied one.
     *
     * Every read in this class goes through here, so a candidate credential cannot be
     * honoured in the mailer and missed in the prerequisite check — which would test a
     * configuration nobody asked about and report the answer as if it were the one
     * requested.
     */
    private function value(string $reference, mixed $default = null): mixed
    {
        if (array_key_exists($reference, $this->overrides)) {
            return $this->overrides[$reference];
        }

        return $this->settings->get($reference, $default);
    }

    /**
     * Which prerequisites are missing, as setting references.
     *
     * The dependencies the catalogue already declares, checked before attempting
     * anything: telling an operator that the host is unset is more useful than a
     * connection error that means the same thing.
     *
     * @return array<int, string>
     */
    private function missingRequirements(): array
    {
        $missing = [];

        if ($this->value('mail.enabled') !== true) {
            $missing[] = 'mail.enabled';
        }

        foreach (['mail.host', 'mail.from_address', 'mail.test_recipient'] as $reference) {
            $value = $this->value($reference);

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $reference;
            }
        }

        return $missing;
    }

    /**
     * Build a mailer from the stored settings for the duration of this request.
     *
     * Configured at runtime rather than read from config/mail.php, because the point
     * is to test what an operator has configured through the platform — the password
     * is read from the settings engine, decrypted there, and never written anywhere
     * this method can log.
     */
    private function configureRuntimeMailer(): void
    {
        $encryption = (string) $this->value('mail.encryption', 'tls');

        config([
            'mail.mailers.'.self::MAILER => [
                'transport' => 'smtp',
                'host' => (string) $this->value('mail.host'),
                'port' => (int) $this->value('mail.port', 587),
                'encryption' => $encryption === 'none' ? null : $encryption,
                'username' => $this->value('mail.username'),
                'password' => $this->value('mail.password'),
                'timeout' => (int) $this->value('operations.provider_timeout_seconds', 10),
            ],
            'mail.from.address' => (string) $this->value('mail.from_address'),
            'mail.from.name' => (string) $this->value('mail.from_name', 'AlphaMaster'),
        ]);
    }
}
