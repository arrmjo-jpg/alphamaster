/**
 * What a failed request looks like by the time the application sees it.
 *
 * The platform answers every failure with the same envelope (ADR 0031):
 * `{ success: false, error: { code, message, details? } }`, where `code` is
 * contract and is never localized, and `message` is already in the caller's
 * language. So the application branches on `code` and renders `message` — it never
 * parses a sentence and never writes its own copy for a failure the server has
 * already described.
 */

export interface ApiErrorBody {
    success: false;
    error: {
        code: string;
        message: string;
        details?: unknown;
    };
}

/** The codes the Admin actually branches on. Others pass through unmatched. */
export const ErrorCode = {
    Unauthenticated: 'UNAUTHENTICATED',
    Forbidden: 'FORBIDDEN',
    AdminAccessRequired: 'ADMIN_ACCESS_REQUIRED',
    EmailVerificationRequired: 'EMAIL_VERIFICATION_REQUIRED',
    InvalidCredentials: 'INVALID_CREDENTIALS',
    AccountSuspended: 'ACCOUNT_SUSPENDED',
    TooManyAttempts: 'TOO_MANY_ATTEMPTS',
    MfaChallengeFailed: 'MFA_CHALLENGE_FAILED',
    MfaDeliveryThrottled: 'MFA_DELIVERY_THROTTLED',
    MfaEnrolmentInvalid: 'MFA_ENROLMENT_INVALID',
    EmailVerificationThrottled: 'EMAIL_VERIFICATION_THROTTLED',
    InvalidVerificationLink: 'INVALID_VERIFICATION_LINK',
    ValidationError: 'VALIDATION_ERROR',
    PreconditionRequired: 'PRECONDITION_REQUIRED',
    SettingVersionConflict: 'SETTING_VERSION_CONFLICT',
    SettingValueRejected: 'SETTING_VALUE_REJECTED',
    SettingPermissionRequired: 'SETTING_PERMISSION_REQUIRED',
    NotFound: 'NOT_FOUND',
    /** Not from the platform: the request never reached it. */
    Transport: 'TRANSPORT_ERROR',
} as const;

export type ErrorCodeValue = (typeof ErrorCode)[keyof typeof ErrorCode];

/** Field-keyed messages, the shape a 422 uses. */
export type ValidationDetails = Record<string, string[]>;

export class ApiError extends Error {
    readonly code: string;
    readonly status: number;
    readonly details: unknown;

    private constructor(code: string, message: string, status: number, details: unknown) {
        super(message);
        this.name = 'ApiError';
        this.code = code;
        this.status = status;
        this.details = details;
    }

    static fromResponse(response: Response, body: ApiErrorBody | null): ApiError {
        // A response that is not the platform's envelope still has to become an
        // ApiError, or every caller would need a second failure path for the cases
        // a proxy or a crash produces.
        const code = body?.error?.code ?? `HTTP_${response.status}`;
        const message = body?.error?.message ?? response.statusText;

        return new ApiError(code, message, response.status, body?.error?.details);
    }

    static transport(cause: unknown): ApiError {
        const message = cause instanceof Error ? cause.message : 'The request could not be sent.';

        return new ApiError(ErrorCode.Transport, message, 0, undefined);
    }

    is(code: ErrorCodeValue): boolean {
        return this.code === code;
    }

    /** The session is gone; the caller should return to sign-in. */
    get isUnauthenticated(): boolean {
        return this.status === 401;
    }

    /** Authenticated, but not permitted. A different screen from being signed out. */
    get isForbidden(): boolean {
        return this.status === 403;
    }

    /** Field errors from a 422, or null when this failure is not a validation one. */
    get validationDetails(): ValidationDetails | null {
        if (this.code !== ErrorCode.ValidationError || typeof this.details !== 'object') {
            return null;
        }

        return (this.details ?? null) as ValidationDetails | null;
    }

    /**
     * Seconds to wait, from a throttled response.
     *
     * The platform sends this in `details.retry_after` alongside the `Retry-After`
     * header, so a countdown is a real number rather than a guess.
     */
    get retryAfterSeconds(): number | null {
        const details = this.details as { retry_after?: unknown } | null;
        const value = details?.retry_after;

        return typeof value === 'number' ? value : null;
    }

    /** Attempts left before the limiter trips, from a refused sign-in. */
    get attemptsRemaining(): number | null {
        const details = this.details as { attempts_remaining?: unknown } | null;
        const value = details?.attempts_remaining;

        return typeof value === 'number' ? value : null;
    }

    /**
     * The version the server holds, from a 412.
     *
     * It travels with the refusal so a client can show what changed rather than
     * only reporting that the write failed (ADR 0038).
     */
    get currentVersion(): string | null {
        const details = this.details as { current_version?: unknown } | null;
        const value = details?.current_version;

        return typeof value === 'string' ? value : null;
    }
}
