<?php

declare(strict_types=1);

namespace App\Modules\Notification\Controllers\Api;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Notification\Models\NotificationRecord;
use App\Modules\Notification\Resources\NotificationRecordResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the platform has told the signed-in account.
 *
 * Not administrative, and deliberately shaped like the preferences endpoint beside it:
 * these are the caller's own records, about the caller. Every query is scoped to the
 * notifiable, which is the whole of the authorization here — there is no endpoint that
 * reads somebody else's inbox, and an identifier belonging to another account is a
 * 404 rather than a refusal, because confirming that a record exists is already more
 * than a stranger should learn.
 *
 * The in-app record is the one channel a recipient cannot silence (ADR 0019): it is
 * the evidence that a notification was raised at all. Until now the platform wrote
 * those rows and published nothing that could read them, which made the evidence
 * unreachable by the person it was about. This is the reader.
 *
 * There is no delete. A recipient who could remove a record could remove the evidence
 * that they were told, and the reason the row is unsilenceable is the same reason it
 * is undeletable.
 */
class NotificationInboxController extends BaseApiController
{
    /**
     * The account's own records, newest first.
     */
    // `page` is Laravel's rather than this controller's, so the generator has nothing
    // to infer it from and a client would have no documented way to reach page two.
    #[QueryParameter('page', 'Which page of results to return.', type: 'int', default: 1)]
    #[QueryParameter('unread', 'Return only records that have not been read.', type: 'bool', default: false)]
    #[Response(200, type: 'array{success: bool, data: list<NotificationRecordResource>, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int, has_more_pages: bool}, unread: int}}')]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $scoped = fn () => NotificationRecord::query()->for($user::class, (string) $user->getKey());

        $query = $scoped();

        if ($request->boolean('unread')) {
            $query->unread();
        }

        $page = $query->paginate(25)->through(
            fn (NotificationRecord $record): NotificationRecordResource => new NotificationRecordResource($record)
        );

        return $this->successResponse(
            data: $page->items(),
            meta: [
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                    'has_more_pages' => $page->hasMorePages(),
                ],
                // Counted rather than derived from the page, because the number an
                // interface puts on a badge is about the whole inbox and not about
                // whichever twenty-five records happen to be on screen.
                'unread' => $scoped()->unread()->count(),
            ]
        );
    }

    /**
     * Mark one record read.
     *
     * Idempotent: a record that was already read keeps the moment it was first read
     * rather than having it moved, because when somebody first saw a message is the
     * fact worth keeping.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: NotificationRecordResource}')]
    public function read(Request $request, string $notification): JsonResponse
    {
        $user = $request->user();

        $record = NotificationRecord::query()
            ->for($user::class, (string) $user->getKey())
            ->whereKey($notification)
            ->firstOrFail();

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        return $this->successResponse(
            new NotificationRecordResource($record->refresh()),
            'api.notification.record_read'
        );
    }

    /**
     * Mark everything unread as read, and say how many that was.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{marked: int}}')]
    public function readAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $marked = NotificationRecord::query()
            ->for($user::class, (string) $user->getKey())
            ->unread()
            ->update(['read_at' => now()]);

        return $this->successResponse(
            ['marked' => $marked],
            'api.notification.records_read',
            replace: ['count' => $marked]
        );
    }
}
