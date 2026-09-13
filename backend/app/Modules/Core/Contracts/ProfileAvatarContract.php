<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * The picture an account shows, as the modules that present an account see it
 * (ADR 0051 §4).
 *
 * Declared here because the picture is media and neither User nor Auth may depend on
 * the Media module. Media implements it; a profile asks through it. It takes `object`,
 * so Core names no domain model — the same inversion `SmsRecipientResolverInterface`
 * uses.
 */
interface ProfileAvatarContract
{
    /**
     * The URL of the account's current picture, or null when it has none or it is not
     * ready to serve.
     */
    public function urlFor(object $account): ?string;
}
