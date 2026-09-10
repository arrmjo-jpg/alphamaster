<?php

declare(strict_types=1);

namespace App\Modules\Notification\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Notification\Contracts\NotifierContract;
use App\Modules\Notification\Enums\AnnouncementAudience;
use App\Modules\Notification\Enums\NotificationType;
use App\Modules\Notification\Requests\SendAnnouncementRequest;
use App\Modules\User\Enums\AccountType;
use App\Modules\User\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Raising an announcement.
 *
 * `admin.announcement` has been a notification type with a seeded, active, translated
 * template since Phase 9, and nothing has ever raised one — the platform could render
 * the message and had no way to send it. This is the sender.
 *
 * It is deliberately the one notification an administrator raises by hand. Every other
 * type describes something that happened, and something that happened has a producer
 * rather than a button; an announcement is the case where the operator *is* the event.
 *
 * Delivery is queued per recipient on the notifications queue (ADR 0020), and each
 * recipient's own preferences and language decide how and in what words it arrives.
 * The type defaults to the in-app record alone, so an announcement does not reach
 * anybody's mailbox unless that recipient asked for it there.
 */
class AnnouncementAdminController extends BaseApiController
{
    public function __construct(
        protected NotifierContract $notifier
    ) {}

    /**
     * Send an announcement.
     *
     * The count returned is how many recipients it was queued for, which is not the
     * same as how many will read it: a recipient who has silenced every channel they
     * are allowed to silence still receives the in-app record, and delivery on any
     * other channel is decided per recipient after this responds.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{audience: AnnouncementAudience, audience_label: string, recipients: int}}')]
    public function store(SendAnnouncementRequest $request): JsonResponse
    {
        $audience = AnnouncementAudience::from((string) $request->validated('audience'));

        $recipients = 0;

        // Chunked rather than loaded whole. The audience is every active account, and
        // a platform with a hundred thousand of them should not need a hundred
        // thousand models in memory to tell them something.
        $this->query($audience)->chunkById(200, function ($chunk) use ($request, &$recipients): void {
            $this->notifier->sendMany($chunk, NotificationType::ADMIN_ANNOUNCEMENT, [
                'subject' => (string) $request->validated('subject'),
                'body' => (string) $request->validated('body'),
            ]);

            $recipients += $chunk->count();
        });

        return $this->successResponse(
            [
                // The label sits beside the value it describes (ADR 0030/0031).
                'audience' => $audience->value,
                'audience_label' => $audience->label(),
                'recipients' => $recipients,
            ],
            'api.notification.announcement_sent',
            replace: ['count' => $recipients]
        );
    }

    /**
     * The accounts an audience names.
     *
     * A suspended account is in neither audience. The in-app record is where this
     * notification lands by default, and an account that cannot sign in cannot read
     * one — queueing work to write a row nobody will ever see is not a kindness.
     *
     * @return Builder<User>
     */
    private function query(AnnouncementAudience $audience): Builder
    {
        $query = User::query()->where('is_active', true);

        if ($audience === AnnouncementAudience::ADMINISTRATORS) {
            $query->where('account_type', AccountType::ADMIN);
        }

        return $query;
    }
}
