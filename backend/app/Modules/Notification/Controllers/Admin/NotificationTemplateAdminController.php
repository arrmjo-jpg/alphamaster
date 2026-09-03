<?php

declare(strict_types=1);

namespace App\Modules\Notification\Controllers\Admin;

use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Notification\Models\NotificationTemplate;
use App\Modules\Notification\Requests\UpdateNotificationTemplateRequest;
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
            ->map(fn (NotificationTemplate $t): array => $this->present($t))
            ->all();

        return $this->successResponse($templates);
    }

    /**
     * Show one template.
     */
    public function show(NotificationTemplate $template): JsonResponse
    {
        return $this->successResponse($this->present($template->loadMissing('translations')));
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
            $this->present($template->refresh()->loadMissing('translations')),
            'Template updated.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NotificationTemplate $template): array
    {
        return [
            'id' => $template->id,
            'type' => $template->type->value,
            'is_active' => $template->is_active,
            'translations' => $template->translations
                ->map(fn ($t): array => [
                    'locale' => $t->locale,
                    'subject' => $t->subject,
                    'body' => $t->body,
                ])
                ->values()
                ->all(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
