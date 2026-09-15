<?php

declare(strict_types=1);

namespace App\Modules\Settings\Secrets;

use App\Modules\Settings\Contracts\SecretVerifierContract;
use App\Modules\Settings\Services\MailConfigurationTester;

/**
 * Checks an SMTP password by using it (ADR 0038, as extended).
 *
 * The one verifier the platform can currently offer, and it exists because mail is the
 * clearest case of the failure this was written for: a wrong SMTP password looks
 * exactly like a right one until a message silently stops arriving, usually to somebody
 * who was not the person who changed it.
 *
 * The candidate is handed to the tester as an override for the duration of one call and
 * is never stored, so a failed verification leaves the configuration exactly as it was.
 */
class MailPasswordVerifier implements SecretVerifierContract
{
    public function __construct(private readonly MailConfigurationTester $tester) {}

    public function reference(): string
    {
        return 'mail.password';
    }

    public function verify(string $candidate): SecretVerificationResult
    {
        $result = $this->tester->test(['mail.password' => $candidate]);

        if ($result->succeeded) {
            return SecretVerificationResult::verified();
        }

        // An incomplete configuration is not a rejected credential, and reporting it as
        // one sends an operator hunting for a password problem that is really an unsaved
        // host. It still blocks the rotation, because nothing verified it — but it keeps
        // the list of what was missing, so the operator can be told which settings to
        // save first. This used to be flattened to the bare status here, which is how
        // both refusals ended up under one sentence.
        if ($result->status === 'incomplete') {
            return SecretVerificationResult::incomplete($result->missing);
        }

        // The failure's class — `TransportException`, say — and never its message, for
        // the reason the tester already gives: a transport message can carry the host,
        // the username and occasionally the credential it tried.
        return SecretVerificationResult::failed($result->failure ?? $result->status);
    }
}
