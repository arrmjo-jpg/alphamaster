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

    public function __construct(private readonly SettingServiceInterface $settings) {}

    /**
     * Attempt a test message, and report what happened.
     *
     * Never throws for a configuration or delivery problem: an unreachable SMTP host
     * is an outcome an operator needs described, not a 500 with a stack trace. Only a
     * programming fault would escape here, which is the distinction ADR 0017 already
     * draws for provider dispatch.
     */
    public function test(): MailTestResult
    {
        $incomplete = $this->missingRequirements();

        if ($incomplete !== []) {
            return MailTestResult::incomplete($incomplete);
        }

        $recipient = (string) $this->settings->get('mail.test_recipient');

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

        if ($this->settings->get('mail.enabled') !== true) {
            $missing[] = 'mail.enabled';
        }

        foreach (['mail.host', 'mail.from_address', 'mail.test_recipient'] as $reference) {
            $value = $this->settings->get($reference);

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
        $encryption = (string) $this->settings->get('mail.encryption', 'tls');

        config([
            'mail.mailers.'.self::MAILER => [
                'transport' => 'smtp',
                'host' => (string) $this->settings->get('mail.host'),
                'port' => (int) $this->settings->get('mail.port', 587),
                'encryption' => $encryption === 'none' ? null : $encryption,
                'username' => $this->settings->get('mail.username'),
                'password' => $this->settings->get('mail.password'),
                'timeout' => (int) $this->settings->get('operations.provider_timeout_seconds', 10),
            ],
            'mail.from.address' => (string) $this->settings->get('mail.from_address'),
            'mail.from.name' => (string) $this->settings->get('mail.from_name', 'AlphaMaster'),
        ]);
    }
}
