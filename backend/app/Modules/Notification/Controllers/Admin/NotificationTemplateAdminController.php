<?php

declare(strict_types=1);

namespace App\Modules\Notification\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Notification\Models\NotificationTemplate;
use App\Modules\Notification\Requests\UpdateNotificationTemplateRequest;
use App\Modules\Notification\Resources\NotificationTemplateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class NotificationTemplateAdminController extends BaseApiController
{
    /**
     * List templates with every translation they carry.
     */
    public function index(): JsonResponse
    {
        $templates = NotificationTemplate::query()
            ->with('translations')
            ->orderBy('type')
            ->get()
            ->all();

        return $this->successResponse(NotificationTemplateResource::collection($templates));
    }

    /**
     * Show one template.
     */
    public function show(NotificationTemplate $template): JsonResponse
    {
        return $this->successResponse(new NotificationTemplateResource($template->loadMissing('translations')));
    }

    /**
     * Update a template's wording or activation.
     *
     * Submitted translations are merged rather than replacing the set, so editing the
     * English copy cannot silently delete the Arabic.
     */
    public function update(UpdateNotificationTemplateRequest $request, NotificationTemplate $template): JsonResponse
    {
        DB::transaction(function () use ($request, $template): void {
            if ($request->has('is_active')) {
                $template->forceFill(['is_active' => (bool) $request->validated('is_active')])->save();
            }

            foreach ((array) $request->validated('translations', []) as $translation) {
                $template->setTranslation((string) $translation['locale'], [
                    'subject' => (string) $translation['subject'],
                    'body' => (string) $translation['body'],
                ]);
            }
        });

        return $this->successResponse(
            new NotificationTemplateResource($template->refresh()->loadMissing('translations')),
            'Template updated.'
        );
    }
}
