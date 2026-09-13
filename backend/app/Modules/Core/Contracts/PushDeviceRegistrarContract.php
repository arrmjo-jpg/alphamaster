<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Removing the devices a session registered, declared where Auth can name it.
 *
 * In Core for the reason `SmsRecipientResolverInterface` is: the registry belongs to
 * Notification, the caller is Auth's logout, and Auth's dependency rule names Core,
 * User, Integration and the framework — not Notification.
 *
 * The direction matters beyond tidiness. A shared handset must not keep receiving the
 * previous account's notifications, so signing out has to reach the registry; and the
 * registry must not have to know what a session is.
 */
interface PushDeviceRegistrarContract
{
    /**
     * Forget every device registered under one access token.
     *
     * Keyed by the token rather than by the account, because signing out of one
     * session must not silence a phone that is still signed in on another.
     */
    public function forgetForAccessToken(string $accessTokenId): void;
}
