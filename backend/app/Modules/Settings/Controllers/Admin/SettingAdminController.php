<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers\Admin;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Settings\Concerns\AssertsSettingPrecondition;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use App\Modules\Settings\Definitions\SettingRegistry;
use App\Modules\Settings\Exceptions\SettingGroupNotFoundException;
use App\Modules\Settings\Exceptions\SettingValueRejectedException;
use App\Modules\Settings\Exceptions\UnknownRevisionException;
use App\Modules\Settings\Exceptions\UnknownSettingKeyException;
use App\Modules\Settings\Requests\RollbackGroupSettingsRequest;
use App\Modules\Settings\Requests\UpdateGroupSettingsRequest;
use App\Modules\Settings\Resources\SettingDefinitionResource;
use App\Modules\Settings\Services\MailConfigurationTester;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SettingAdminController extends BaseApiController
{
    use AssertsSettingPrecondition;

    public function __construct(
        protected SettingServiceInterface $settingService,
        protected SettingRegistry $registry,
        protected AuditRecorderContract $audit,
    ) {}

    /**
     * Verify the stored mail configuration by using it.
     *
     * It really sends. A test reporting success without attempting delivery would be
     * worse than none: an operator would read it as proof and stop looking.
     *
     * The recipient comes from settings rather than the request, so this cannot be
     * turned into sending mail to an address the caller chose. Every attempt is
     * recorded with its outcome (ADR 0037), and no credential appears in the
     * response, the record, or the failure.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{status: string, recipient?: string, missing?: list<string>, failure?: string}}')]
    public function testMail(MailConfigurationTester $tester): JsonResponse
    {
        $result = $tester->test();

        $this->audit->{$result->succeeded ? 'succeeded' : 'failed'}(
            AuditAction::MAIL_TEST_SENT,
            'mail',
            $result->toArray(),
        );

        if ($result->succeeded) {
            return $this->successResponse($result->toArray(), 'api.settings.mail_test_sent');
        }

        // 422 rather than 500: an unreachable host or an unfinished configuration is
        // an answer about the configuration, not a fault in the platform.
        return $this->errorResponse(
            $result->status === 'incomplete' ? 'MAIL_CONFIGURATION_INCOMPLETE' : 'MAIL_TEST_FAILED',
            $result->status === 'incomplete' ? 'api.error.settings.mail_incomplete' : 'api.error.settings.mail_test_failed',
            $result->toArray(),
            422,
        );
    }

    /**
     * The catalogue: what settings exist and what the rules are for each.
     *
     * The question the registry was built to answer, and the one a future Admin UI
     * needs before it can render a form for anything. It carries no values — a
     * definition describes a setting, and what one is set to is a different endpoint
     * with a different shape.
     *
     * Deprecated definitions are included and flagged rather than hidden, so an
     * interface can show an operator that a setting they configured is on its way out.
     */
    #[Response(200, type: 'array{success: bool, data: array<string, list<SettingDefinitionResource>>}')]
    public function definitions(): JsonResponse
    {
        $grouped = [];

        foreach ($this->registry->all() as $definition) {
            $grouped[$definition->group][] = (new SettingDefinitionResource($definition))->resolve();
        }

        return $this->successResponse($grouped);
    }

    /**
     * List all settings grouped by group with admin details and masked secrets.
     */
    #[Response(200, type: 'array{success: bool, data: array<string, list<array{id: string, group: string, key: string, value: mixed, is_localized: bool, locale: string|null, type: string, type_label: string, is_secret: bool, is_public: bool, description: string|null, updated_at: string|null}>>}')]
    public function index(): JsonResponse
    {
        return $this->successResponse($this->settingService->getAdminAll());
    }

    /**
     * Get all settings in a specific group with admin details and masked secrets.
     */
    #[Response(200, type: 'array{success: bool, data: list<array{id: string, group: string, key: string, value: mixed, is_localized: bool, locale: string|null, type: string, type_label: string, is_secret: bool, is_public: bool, description: string|null, updated_at: string|null}>, meta: array{version: string}}')]
    public function show(string $group): JsonResponse
    {
        try {
            $settings = $this->settingService->getAdminGroup($group);
            $version = $this->settingService->groupVersion($group);

            // Handed back both ways: as an ETag for a client that speaks HTTP
            // conditional requests, and in meta for one that does not (ADR 0038).
            return $this->successResponse($settings, meta: ['version' => $version])
                ->header('ETag', '"'.$version.'"');
        } catch (SettingGroupNotFoundException $e) {
            return $this->errorResponse('SETTING_GROUP_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        }
    }

    /**
     * What the settings in this group used to be (ADR 0040).
     *
     * Reading history is `settings.view`, the same as reading the values themselves:
     * seeing what something was is no more privileged than seeing what it is, and
     * making it harder would only push an operator toward reading the database.
     *
     * Secrets are absent because they have no revisions, not because they are filtered
     * here — there is nothing to filter.
     */
    #[Response(200, type: 'array{success: bool, data: list<array{key: string, locale: string|null, version: int, value: mixed, actor_id: string|null, recorded_at: string}>, meta: array{group: string, count: int}}')]
    public function history(Request $request, string $group): JsonResponse
    {
        $key = $request->query('key');
        $key = is_string($key) && $key !== '' ? $key : null;

        $limit = (int) $request->query('limit', '100');
        $limit = max(1, min($limit, 500));

        try {
            $history = $this->settingService->groupHistory($group, $key, $limit);
        } catch (SettingGroupNotFoundException $e) {
            return $this->errorResponse('SETTING_GROUP_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        } catch (UnknownSettingKeyException $e) {
            return $this->errorResponse('SETTING_KEY_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        }

        return $this->successResponse($history, meta: ['group' => $group, 'count' => count($history)]);
    }

    /**
     * Batch update an array of settings within a group atomically.
     * Expected contract: { "settings": { "key1": "val1", "key2": val2 } }
     *
     * An unknown group or key is a 404 (settings are provisioned, not created here);
     * a value that cannot be represented in the setting's declared type is a 422.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{group: string, updated: array<string, mixed>}, meta: array{version: string}}')]
    public function update(UpdateGroupSettingsRequest $request, string $group): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->validated()['settings'];

        try {
            $precondition = $this->assertPrecondition($request, $group);

            if ($precondition !== null) {
                return $precondition;
            }

            $forbidden = $this->assertKeyPermissions($request, $group, $payload);

            if ($forbidden !== null) {
                return $forbidden;
            }

            $updated = $this->settingService->updateGroup($group, $payload);
        } catch (SettingGroupNotFoundException $e) {
            return $this->errorResponse('SETTING_GROUP_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        } catch (UnknownSettingKeyException $e) {
            return $this->errorResponse('SETTING_KEY_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        } catch (SettingValueRejectedException $e) {
            // Named, so an operator writing twenty settings at once is told which one
            // was refused and by which rule.
            return $this->errorResponse('SETTING_VALUE_REJECTED', 'api.error.settings.value_rejected', $e->details(), 422);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse('INVALID_SETTING_VALUE', $e->getMessage(), null, 422);
        }

        $version = $this->settingService->groupVersion($group);

        return $this->successResponse(
            data: [
                'group' => $group,
                'updated' => $updated,
            ],
            message: 'api.settings.group_updated',
            replace: ['group' => $group],
            meta: ['version' => $version],
        )->header('ETag', '"'.$version.'"');
    }

    /**
     * Restore a group to the state it held at a point in its history (ADR 0040).
     *
     * The order is deliberate and each step exists for a reason:
     *
     * 1. the precondition, so a rollback cannot be run from a page left open — the
     *    operation most likely to be attempted from one (ADR 0038);
     * 2. the plan, computed without writing, because the keys a rollback touches are
     *    derived from history rather than submitted, and nothing can be authorized
     *    until they are known;
     * 3. the same per-key permission check an ordinary write performs, which is what
     *    stops rollback becoming a way to change a guarded value without holding the
     *    permission that guards it;
     * 4. the write, applying the plan that was authorized rather than a fresh one.
     *
     * It reports what it restored and what it did not. A group containing a credential
     * is rolled back successfully and names that credential as unrestorable, because a
     * secret has no history to restore from — an operator gets a checklist rather than
     * a failure or a silence.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{group: string, target_revision_id: string, restored: list<array{key: string, locale: string|null, value: mixed}>, skipped: list<array{key: string, locale: string|null, reason: string, reason_label: string}>}, meta: array{version: string}}')]
    public function rollback(RollbackGroupSettingsRequest $request, string $group): JsonResponse
    {
        /** @var string $revisionId */
        $revisionId = $request->validated()['revision_id'];

        try {
            $precondition = $this->assertPrecondition($request, $group);

            if ($precondition !== null) {
                return $precondition;
            }

            $plan = $this->settingService->planRollback($group, $revisionId);

            // Checked against the plan, and through the same method an ordinary write
            // uses, so a setting that needs its own permission needs it here too. A
            // plan mixing a permitted key with a forbidden one applies neither, which
            // is how batch updates already behave.
            $forbidden = $this->assertKeyPermissions($request, $group, array_flip($plan->keys()));

            if ($forbidden !== null) {
                return $forbidden;
            }

            // Always called, even when the plan restores nothing: the service
            // decides what a plan with no changes writes, and it writes the record of
            // having been run without touching a value or a version.
            $this->settingService->applyRollback($plan);
        } catch (SettingGroupNotFoundException $e) {
            return $this->errorResponse('SETTING_GROUP_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        } catch (UnknownRevisionException $e) {
            return $this->errorResponse('SETTING_REVISION_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        } catch (UnknownSettingKeyException $e) {
            return $this->errorResponse('SETTING_KEY_NOT_FOUND', $e->translationKey(), null, 404, $e->translationParameters());
        }

        $version = $this->settingService->groupVersion($group);

        return $this->successResponse(
            data: $plan->toArray(),
            message: 'api.settings.group_rolled_back',
            replace: ['group' => $group],
            meta: ['version' => $version],
        )->header('ETag', '"'.$version.'"');
    }

    /**
     * Refuse a write touching a key the caller is not entitled to change.
     *
     * Checked per key rather than per route, because sensitivity is a property of the
     * setting and not of the group it lives in: `settings.update` is enough to rename
     * the site, and is deliberately not enough to widen the login throttle, replace a
     * credential, or shorten how long the record of who did what survives.
     *
     * A secret needs `settings.secrets.manage` whatever group it is in, so a
     * credential added to any future catalogue is covered the moment it is declared
     * rather than when somebody remembers to guard it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertKeyPermissions(Request $request, string $group, array $payload): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Authorizable) {
            return null;
        }

        foreach (array_keys($payload) as $key) {
            $reference = $group.'.'.(string) $key;

            if (! $this->registry->has($reference)) {
                continue;
            }

            $required = $this->registry->get($reference)->requiredPermission();

            // Asked through the framework's own authorization API rather than the
            // Authorization module's contract: Settings depends on Core and the
            // framework only (ADR 0002), and Spatie registers permissions with the
            // Gate, so `can()` is the same answer by a permitted route.
            if ($required === null || $user->can($required)) {
                continue;
            }

            return $this->errorResponse(
                'PERMISSION_DENIED',
                'api.error.settings.permission_required',
                ['setting' => $reference, 'permission' => $required],
                403,
            );
        }

        return null;
    }
}
