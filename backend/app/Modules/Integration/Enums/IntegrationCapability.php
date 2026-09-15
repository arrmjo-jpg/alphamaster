<?php

declare(strict_types=1);

namespace App\Modules\Integration\Enums;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * A class of external service the platform can consume.
 *
 * Only capabilities with a real consumer exist here. SMS is present because the OTP
 * multi-factor method deferred by ADR 0013 needs it; CAPTCHA because the sign-in
 * endpoint verifies one. Email, WhatsApp and storage arrive when the phases that
 * consume them do, which is what the manager pattern makes cheap.
 *
 * Adding a case is not enough on its own: the capability column carries a database
 * constraint listing the permitted values, so a new capability arrives with a
 * migration that widens it.
 */
enum IntegrationCapability: string
{
    use HasDisplayLabel;

    case SMS = 'sms';

    case CAPTCHA = 'captcha';

    /**
     * Text generation (ADR 0044). Present because the translation workshop asks for
     * suggestions; it is the one AI task the platform has a consumer for.
     */
    case AI = 'ai';

    /**
     * Push delivery to a registered device (ADR 0045). Firebase Cloud Messaging is a
     * driver here and nothing more: this platform owns identity, its database and its
     * media, and adopts Firebase only as a transport.
     */
    case PUSH = 'push';

    /**
     * Signing in with an identity a vendor vouches for (ADR 0050). For user accounts only:
     * no administrator signs in, registers or links through this capability.
     *
     * No failover, for the reason captcha has none. An identity belongs to the vendor that
     * issued it, so providers stand side by side rather than behind one another.
     */
    case SOCIAL_LOGIN = 'social_login';

    /**
     * Invalidating a content delivery network's edge cache (ADR 0053).
     *
     * Consumed through Core's `EdgeCacheContract`, so the modules that invalidate — and the
     * domain modules that will — never learn which vendor fronts the platform. No failover:
     * a zone belongs to one vendor, and purging a second vendor's cache would not remove
     * the object the first one is serving.
     */
    case CDN = 'cdn';

    /**
     * Analysing stored media for indicators such as AI generation or manipulation (ADR 0054).
     *
     * Its own capability rather than part of `ai`: that one generates text from one default
     * provider and model, and a media analyzer differs in contract, limits, cost and what it
     * is sent. Consumed through Core's `MediaAnalyzerContract` by Media, never by a consumer
     * directly. No failover: two analyzers give two different readings of one file.
     */
    case MEDIA_ANALYSIS = 'media_analysis';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
