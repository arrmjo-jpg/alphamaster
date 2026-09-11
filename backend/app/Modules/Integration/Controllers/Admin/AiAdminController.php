<?php

declare(strict_types=1);

namespace App\Modules\Integration\Controllers\Admin;

use App\Modules\Core\Ai\TextGenerationRequest;
use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Contracts\EffectiveGrants;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Requests\AiCheckRequest;
use App\Modules\Integration\Requests\SaveAiProviderRequest;
use App\Modules\Integration\Services\AiManager;
use App\Modules\Integration\Services\AiStatus;
use App\Modules\Integration\Services\TextGenerator;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The AI control centre: set up each provider, test it, choose which one answers.
 *
 * The setup is deliberately small — a provider, its API key, its model — and each
 * provider keeps its own. How a driver reaches its vendor (the address, the headers) is
 * the driver's and is never asked for. Configuring OpenAI never touches Anthropic or
 * Gemini; several can be configured at once, and a provider is ready once it holds a
 * key. One of them is the default, which is the one the platform's AI tasks use;
 * changing it leaves the others configured, and there is no failover between them
 * (ADR 0044 §3).
 *
 * Nothing here reveals a key. It is written, replaced or removed, and reported only as
 * present or absent. The audit trail records that it changed, never what it is.
 */
class AiAdminController extends BaseApiController
{
    /**
     * The order providers are offered in. Anything else configured appears after these.
     */
    private const ORDER = ['openai', 'anthropic', 'gemini'];

    public function __construct(
        private readonly AiStatus $status,
        private readonly AiManager $manager,
        private readonly TextGenerator $generator,
        private readonly AuditRecorderContract $audit,
        private readonly EffectiveGrants $grants,
    ) {}

    /**
     * Every AI provider with its own configuration, and what the platform can currently
     * do: which provider answers, whether it holds a key, and what the last attempt did.
     */
    #[Response(200, type: 'array{success: bool, data: array{configured: bool, provider: array{driver: string, label: string, has_credentials: bool, model: string}|null, providers: array<int, array{driver: string, label: string, has_key: bool, is_default: bool, model: string|null, effective_model: string, default_model: string, suggested_models: array<int, string>}>, last_attempt: array{status: string, at: string, error_code: string|null, error_message: string|null, duration_ms: int|null, units: int|null}|null, recent_failures: int}}')]
    public function show(): JsonResponse
    {
        $status = $this->status->describe();
        unset($status['available_drivers']);

        $default = $this->manager->defaultProvider();

        if ($status['provider'] !== null && $default !== null) {
            $status['provider']['model'] = $this->generator->modelFor($default);
        }

        $status['providers'] = array_map(fn (IntegrationProvider $row): array => $this->present($row), $this->rows());

        return $this->successResponse($status);
    }

    /**
     * Save one provider's configuration.
     *
     * The key is optional after the first save; leaving it out keeps the stored one. A
     * provider is ready once it holds a key — there is no separate switch — and saving
     * one never touches another. A provider saved while no ready provider is the default
     * becomes the default, so the first provider configured is the one that answers
     * without a second step.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{driver: string, label: string, has_key: bool, is_default: bool, model: string|null, effective_model: string, default_model: string, suggested_models: array<int, string>}}')]
    #[Response(404, description: 'There is no such AI provider.')]
    #[Response(422, description: 'The provider has no API key stored and none was entered.')]
    public function save(SaveAiProviderRequest $request, string $provider): JsonResponse
    {
        $row = $this->row($provider);

        if ($row === null) {
            return $this->unknown($provider);
        }

        $key = trim((string) $request->validated('api_key', ''));
        $model = trim((string) $request->validated('model'));
        $hadKey = $row->hasCredentials();
        $previousModel = $this->savedModel($row);

        if ($key === '' && ! $hadKey) {
            return $this->errorResponse('AI_KEY_REQUIRED', 'api.error.ai.key_required', null, 422, [
                'provider' => $row->label,
            ]);
        }

        $isDefault = DB::transaction(function () use ($row, $key, $model, $hadKey, $previousModel): bool {
            if ($key !== '') {
                $row->setCredentials(['api_key' => $key]);
            }

            // The model is the only setting an AI provider has. Replaced rather than
            // merged, so nothing about how the driver reaches its vendor can linger.
            $row->settings = ['model' => $model];
            // Ready because it holds a key. No other provider is switched on or off.
            $row->is_active = true;
            $row->save();

            // The first provider set up answers without a second step. That is a change
            // of default, and it is recorded as one below.
            $becomesDefault = ! $row->is_default && ! $this->defaultIsUsable();

            $this->audit->succeeded(AuditAction::AI_PROVIDER_SAVED, $row->driver, [
                'provider' => $row->driver,
                // Before and after, so a change of model reads as one.
                'previous_model' => $previousModel,
                'model' => $model,
                // That it changed, never what it is.
                'key' => $key === '' ? 'unchanged' : ($hadKey ? 'replaced' : 'set'),
                // Whether this provider answers the platform's AI tasks after the save —
                // the fact an operator reading the trail is asking about.
                'is_default' => $row->is_default || $becomesDefault,
            ]);

            if ($becomesDefault) {
                $this->makeTheDefault($row);
            }

            return $row->is_default;
        });

        return $this->successResponse(
            $this->present($row->refresh()),
            $isDefault ? 'api.ai.provider_saved_default' : 'api.ai.provider_saved',
            replace: ['provider' => $row->label]
        );
    }

    /**
     * Remove a provider's API key. Without a key it cannot answer, so it stops being
     * ready; every other provider is left as it is. If it was the default, AI stays off
     * until another provider is made the default or a key is saved again — nothing is
     * switched over automatically (ADR 0044 §3).
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{driver: string, label: string, has_key: bool, is_default: bool, model: string|null, effective_model: string, default_model: string, suggested_models: array<int, string>}}')]
    #[Response(404, description: 'There is no such AI provider.')]
    public function removeKey(string $provider): JsonResponse
    {
        $row = $this->row($provider);

        if ($row === null) {
            return $this->unknown($provider);
        }

        if ($row->hasCredentials()) {
            DB::transaction(function () use ($row): void {
                $row->setCredentials(null);
                $row->is_active = false;
                $row->save();

                $this->audit->succeeded(AuditAction::AI_PROVIDER_KEY_REMOVED, $row->driver, [
                    'provider' => $row->driver,
                    'was_default' => $row->is_default,
                ]);
            });
        }

        return $this->successResponse($this->present($row->refresh()), 'api.ai.key_removed', replace: [
            'provider' => $row->label,
        ]);
    }

    /**
     * Make a provider the one the platform's AI tasks use. It must hold a key: a default
     * that cannot answer would switch AI off without saying so. The previous default
     * keeps its key and model and stays ready — only which one answers changes.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{driver: string, label: string, has_key: bool, is_default: bool, model: string|null, effective_model: string, default_model: string, suggested_models: array<int, string>}}')]
    #[Response(404, description: 'There is no such AI provider.')]
    #[Response(422, description: 'The provider has no API key.')]
    public function makeDefault(string $provider): JsonResponse
    {
        $row = $this->row($provider);

        if ($row === null) {
            return $this->unknown($provider);
        }

        if (! $row->hasCredentials()) {
            return $this->errorResponse('AI_PROVIDER_NOT_READY', 'api.error.ai.provider_not_ready', null, 422, [
                'provider' => $row->label,
            ]);
        }

        if (! $row->is_default) {
            DB::transaction(fn () => $this->makeTheDefault($row));
        }

        return $this->successResponse($this->present($row->refresh()), 'api.ai.default_changed', replace: [
            'provider' => $row->label,
        ]);
    }

    /**
     * Ask a provider a trivial question and report what happened.
     *
     * With no body, the default provider as saved — `ai.use`, because it is a real call
     * with a real cost. With a provider named, that provider as saved. With a key or a
     * model as well, the setup form as it stands before saving — which also needs
     * `integrations.update`, because it is configuring a vendor. A key sent here is used
     * for this one call and never stored.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{answered: bool, driver: string, model: string|null, units: int|null, error_code: string|null, error_message: string|null}}')]
    #[Response(403, description: 'Testing an unsaved key or model needs permission to change integrations.')]
    #[Response(404, description: 'There is no such AI provider.')]
    public function check(AiCheckRequest $request): JsonResponse
    {
        $driver = trim((string) $request->validated('provider', ''));
        $key = trim((string) $request->validated('api_key', ''));
        $model = trim((string) $request->validated('model', ''));

        if (($key !== '' || $model !== '') && ! $this->mayConfigure($request)) {
            return $this->errorResponse('FORBIDDEN', 'api.error.ai.configure_forbidden', null, 403);
        }

        $row = $driver === '' ? $this->manager->defaultProvider() : $this->row($driver);

        if ($driver !== '' && $row === null) {
            return $this->unknown($driver);
        }

        $question = new TextGenerationRequest(
            instruction: 'Reply with the single word OK. Do not explain.',
            content: 'ping',
            // Generous for a one-word answer, because a model that reasons before it
            // answers spends tokens thinking, and too small a ceiling would make a
            // working key look broken.
            maxOutputTokens: 256,
            temperature: 0.0,
        );

        if ($row === null) {
            $result = $this->generator->generate($question);
            $usedModel = null;
        } else {
            // A copy held in memory: the form's key and model are tried without being
            // written anywhere.
            $candidate = clone $row;

            if ($key !== '') {
                $candidate->setCredentials(['api_key' => $key]);
            }

            if ($model !== '') {
                $candidate->settings = array_merge($candidate->settings ?? [], ['model' => $model]);
            }

            $usedModel = $this->generator->modelFor($candidate);
            $result = $this->generator->generateWith($candidate, $question);
        }

        return $this->successResponse(
            [
                'answered' => $result->successful,
                'driver' => $result->driver,
                'model' => $usedModel,
                'units' => $result->units,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ],
            // 200 either way: the check ran, and what it found is the payload. A 502
            // for a vendor that refused would make a working diagnostic look broken.
            $result->successful ? 'api.ai.check_answered' : 'api.ai.check_failed'
        );
    }

    /**
     * The AI provider rows, in the order they are offered.
     *
     * @return array<int, IntegrationProvider>
     */
    private function rows(): array
    {
        /** @var array<int, IntegrationProvider> $rows */
        $rows = IntegrationProvider::query()->forCapability(IntegrationCapability::AI)->get()->all();

        usort($rows, function (IntegrationProvider $a, IntegrationProvider $b): int {
            $position = fn (IntegrationProvider $row): int => ($index = array_search($row->driver, self::ORDER, true)) === false
                ? count(self::ORDER)
                : (int) $index;

            return [$position($a), $a->label] <=> [$position($b), $b->label];
        });

        return $rows;
    }

    private function row(string $driver): ?IntegrationProvider
    {
        return IntegrationProvider::query()
            ->forCapability(IntegrationCapability::AI)
            ->where('driver', $driver)
            ->first();
    }

    /**
     * One provider as the setup form reads it. Never the key: only whether one is held.
     *
     * @return array<string, mixed>
     */
    private function present(IntegrationProvider $row): array
    {
        $driver = $this->generator->driverFor($row);

        return [
            'driver' => $row->driver,
            'label' => $row->label,
            'has_key' => $row->hasCredentials(),
            'is_default' => $row->is_default,
            'model' => $this->savedModel($row),
            'effective_model' => $this->generator->modelFor($row),
            'default_model' => $driver->defaultModel(),
            'suggested_models' => $driver->suggestedModels(),
        ];
    }

    /**
     * Whether the current default can answer. A default that holds no key is a default
     * in name only.
     */
    private function defaultIsUsable(): bool
    {
        $default = $this->manager->defaultProvider();

        return $default !== null && $default->hasCredentials();
    }

    /** The model saved with a provider, if one was chosen. */
    private function savedModel(IntegrationProvider $row): ?string
    {
        $saved = $row->settings['model'] ?? null;

        return is_string($saved) && trim($saved) !== '' ? $saved : null;
    }

    /**
     * Make one provider the default, and record which one it replaced. Every other
     * provider keeps its key and model; only which one answers changes.
     */
    private function makeTheDefault(IntegrationProvider $row): void
    {
        $previous = IntegrationProvider::query()
            ->forCapability(IntegrationCapability::AI)
            ->where('is_default', true)
            ->value('driver');

        // Holding a key is what makes a provider ready, so a row that has one is ready
        // by definition; saved with the default flag below.
        $row->is_active = true;
        $this->swapDefaultTo($row);

        $this->audit->succeeded(AuditAction::AI_DEFAULT_CHANGED, $row->driver, [
            'previous_default' => is_string($previous) ? $previous : null,
            'default' => $row->driver,
        ]);
    }

    /**
     * One default per capability is a partial unique index, so the old one is cleared
     * before the new one is set.
     */
    private function swapDefaultTo(IntegrationProvider $row): void
    {
        IntegrationProvider::query()
            ->forCapability(IntegrationCapability::AI)
            ->where('is_default', true)
            ->where('id', '!=', $row->id)
            ->update(['is_default' => false]);

        $row->forceFill(['is_default' => true])->save();
    }

    private function mayConfigure(Request $request): bool
    {
        return in_array('integrations.update', $this->grants->permissionsFor($request->user()), true);
    }

    private function unknown(string $provider): JsonResponse
    {
        return $this->errorResponse('AI_PROVIDER_UNKNOWN', 'api.error.ai.provider_unknown', null, 404, [
            'provider' => $provider,
        ]);
    }
}
