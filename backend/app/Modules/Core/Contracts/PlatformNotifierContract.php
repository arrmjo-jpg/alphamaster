<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Translation\Phrase;

/**
 * Raises a notification from a module that may not know notifications exist.
 *
 * The Notification module's own `NotifierContract` takes a `NotificationType` — its
 * enum — and every module that has something worth telling somebody about is forbidden
 * from importing that namespace. So `security.alert` and `account.updated` were seeded,
 * templated and translated, and never raised once (M3 decision 1).
 *
 * This is the inversion the platform already uses for SMS recipients and locales:
 * Core declares the seam, Notification implements it, a producer depends only on Core.
 * The recipient is `object` so Core names no domain model.
 *
 * The cost is the type. A producer writes `'security.alert'` rather than an enum case,
 * so a typo would compile. That is paid back by a test that reads every call to this
 * method in the codebase and fails unless its type is a literal naming a real
 * notification type — which also means a producer may not pass a variable here.
 */
interface PlatformNotifierContract
{
    /**
     * Queue a notification for one account.
     *
     * Delivery is the recipient's business: which channels apply is their preference
     * and what the message says is the template's, both resolved on the queue in the
     * recipient's own language. A producer supplies only the occasion.
     *
     * Never throws for a notification that cannot be raised. The operation that
     * prompted it has already happened — a second factor was removed, an account was
     * edited — and failing the response would report as failed something that
     * succeeded. The fault is reported instead.
     *
     * @param  string  $type  A notification type value, e.g. `security.alert`.
     * @param  array<string, string|int|Phrase>  $placeholders  Literal values are inserted as
     *                                                          given; a Phrase is rendered in
     *                                                          the recipient's language.
     */
    public function notify(object $recipient, string $type, array $placeholders = []): void;
}
