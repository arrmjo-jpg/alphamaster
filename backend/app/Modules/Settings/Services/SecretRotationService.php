<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use App\Modules\Settings\Secrets\SecretVerificationResult;
use App\Modules\Settings\Secrets\SecretVerifierRegistry;

/**
 * Rotating a credential: try it, then commit it, and never the other way round
 * (ADR 0038, as extended).
 *
 * Rotation is the one write whose failure is silent and delayed. An ordinary setting
 * saved wrongly is visible on the next screen; a credential saved wrongly looks
 * identical to one saved correctly and surfaces days later as an integration that
 * stopped working. So where a verifier exists, the candidate is checked with the vendor
 * before anything is stored, and where none exists the result says so rather than
 * implying it passed.
 *
 * **The candidate is never persisted mid-flight.** It exists as a parameter, is handed
 * to a verifier that holds it for the length of one call, and is then either committed
 * or dropped. There is no pending column, no staging row and no cache entry — which is
 * what makes "a failed verification leaves the stored credential exactly as it was"
 * a property of the shape rather than a rule somebody has to remember.
 */
class SecretRotationService
{
    public function __construct(
        private readonly SettingServiceInterface $settings,
        private readonly SettingRegistry $registry,
        private readonly SecretVerifierRegistry $verifiers,
        private readonly AuditRecorderContract $audit,
    ) {}

    /**
     * Check a candidate and commit it if it holds up.
     *
     * Returns what the verification concluded, whether or not anything was written, so
     * the caller can report "rotated, unverified" as a distinct outcome from both
     * "rotated and confirmed" and "refused".
     *
     * @throws UnknownSettingKeyException when the reference is not a declared secret
     */
    public function rotate(string $group, string $key, string $candidate): SecretVerificationResult
    {
        $reference = $group.'.'.$key;

        if (! $this->registry->has($reference)) {
            throw new UnknownSettingKeyException($group, $key);
        }

        $definition = $this->registry->get($reference);

        // Rotation is defined for credentials. A non-secret answers the same way an
        // unknown key does rather than being quietly redirected into an ordinary
        // write, because the two operations have different contracts and a caller that
        // confused them should find out.
        if (! $definition->isSecret || ! $definition->editable) {
            throw new UnknownSettingKeyException($group, $key);
        }

        $result = $this->verifiers->verify($reference, $candidate);

        // The only outcome that blocks. An absent verifier does not: refusing to rotate
        // every credential nobody can check would leave most of them unrotatable, which
        // is a worse security posture than rotating them unverified and saying so.
        if (! $result->permitsCommit()) {
            // A refused rotation is still an attempt that reached a vendor, and ADR
            // 0037 requires an external operation to be recorded with its outcome —
            // including when the outcome is a failure. Recording only the successes
            // would leave the trail unable to show a credential being guessed at.
            //
            // Key and outcome, as ever. `detail` is the verifier's own short token,
            // which by contract is a class name or a status and never a message that
            // could carry the host, the username, or the credential itself.
            $this->audit->failed(AuditAction::SECRET_ROTATED, $reference, [
                'verification' => $result->status->value,
                'detail' => $result->detail,
            ]);

            return $result;
        }

        $this->settings->rotateSecret($group, $key, $candidate, $result->status->value);

        return $result;
    }
}
