<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use App\Modules\Core\Concerns\CarriesLocalizableMessage;
use App\Modules\Core\Contracts\LocalizableException;
use RuntimeException;

/**
 * A social sign-in, link or authorization the platform refuses (ADR 0050).
 *
 * Carries the contract code and status the API answers with, so the flow decides what
 * was wrong and the controller only says it.
 *
 * `reason` is internal. Several refusals share one public answer —
 * `SOCIAL_SIGN_IN_REFUSED` — so a caller cannot tell an administrator's address from an
 * inactive account from an unverified one, and the reason is what the audit trail
 * records for the two that concern an administrator.
 */
class SocialLoginException extends RuntimeException implements LocalizableException
{
    use CarriesLocalizableMessage;

    /** The identity's account, or the account being linked, is an administrator. */
    public const ADMIN_ACCOUNT = 'admin_account';

    /** The provider's address belongs to an administrator. */
    public const ADMIN_EMAIL_MATCH = 'admin_email_match';

    public const INACTIVE_ACCOUNT = 'inactive_account';

    /** The subject exists only as an unlinked row, reserved to its original account. */
    public const UNLINKED_IDENTITY = 'unlinked_identity';

    /** The provider did not assert that it verified the address. */
    public const UNVERIFIED_EMAIL = 'unverified_email';

    private function __construct(
        public readonly string $apiCode,
        public readonly int $status,
        private readonly string $messageKey,
        public readonly ?string $reason = null,
    ) {
        parent::__construct(self::englishMessage($messageKey));
    }

    /**
     * Unknown, disabled or not-yet-effective provider, or social login switched off.
     */
    public static function providerUnavailable(): self
    {
        return new self('SOCIAL_PROVIDER_UNAVAILABLE', 404, 'api.error.auth.social_provider_unavailable');
    }

    public static function invalidRedirectUri(): self
    {
        return new self('INVALID_REDIRECT_URI', 422, 'api.error.auth.social_invalid_redirect_uri');
    }

    /**
     * State unknown, expired, already used, or presented with anything it was not bound
     * to — including a PKCE verifier that does not match. One answer for all of them.
     */
    public static function stateInvalid(): self
    {
        return new self('SOCIAL_STATE_INVALID', 422, 'api.error.auth.social_state_invalid');
    }

    public static function providerError(): self
    {
        return new self('SOCIAL_PROVIDER_ERROR', 502, 'api.error.auth.social_provider_error');
    }

    public static function refused(string $reason): self
    {
        return new self('SOCIAL_SIGN_IN_REFUSED', 403, 'api.error.auth.social_sign_in_refused', $reason);
    }

    public static function identityNotLinked(): self
    {
        return new self('SOCIAL_IDENTITY_NOT_LINKED', 409, 'api.error.auth.social_identity_not_linked');
    }

    public static function registrationClosed(): self
    {
        return new self('REGISTRATION_CLOSED', 403, 'api.error.auth.social_registration_closed');
    }

    public function translationKey(): string
    {
        return $this->messageKey;
    }
}
