<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Core\MediaAnalysis\AnalyzerDescriptor;
use App\Modules\Core\MediaAnalysis\AnalyzerOutcome;
use App\Modules\Core\MediaAnalysis\MediaAnalysisInput;
use App\Modules\Integration\Contracts\MediaAnalysisProviderContract;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Models\IntegrationProvider;
use App\Modules\Integration\Services\MediaAnalysisManager;
use App\Modules\Settings\Contracts\SettingServiceInterface;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * A media analysis driver for tests, and only for tests (ADR 0054).
 *
 * The platform ships no analyzer because no vendor has been chosen, and it will never ship
 * one that answers without looking at the media. This stands in for a real driver so the
 * whole lifecycle — request, queue, claim, analyze, record, supersede — is exercised against
 * the same contract a vendor driver implements.
 */
final class FakeMediaAnalysisProvider implements MediaAnalysisProviderContract
{
    /** @var list<string> */
    public static array $supportedTypes = [];

    /** @var list<string> */
    public static array $acceptedMimeTypes = ['video/'];

    public static ?int $maxBytes = null;

    public static ?int $maxDurationSeconds = null;

    /** @var (Closure(MediaAnalysisInput): AnalyzerOutcome)|null */
    public static ?Closure $answer = null;

    public static int $calls = 0;

    public static ?MediaAnalysisInput $lastInput = null;

    /**
     * Register the driver, configure a provider row and switch the capability on, the way an
     * operator would.
     *
     * @param  list<string>  $supportedTypes
     */
    public static function configure(
        array $supportedTypes = ['ai_generated', 'deepfake', 'face_manipulation', 'visual_manipulation', 'synthetic_audio'],
        bool $enabled = true,
        bool $withCredential = true,
        string $model = 'fake-model-1',
    ): IntegrationProvider {
        self::$supportedTypes = $supportedTypes;
        self::$acceptedMimeTypes = ['video/'];
        self::$maxBytes = null;
        self::$maxDurationSeconds = null;
        self::$answer = null;
        self::$calls = 0;
        self::$lastInput = null;

        // Named rather than `new self`: the manager rebinds a creator closure to itself, and
        // `self` would then mean the manager.
        app(MediaAnalysisManager::class)->extend('fake', static fn (): FakeMediaAnalysisProvider => new FakeMediaAnalysisProvider);

        $provider = IntegrationProvider::query()->firstOrNew([
            'capability' => IntegrationCapability::MEDIA_ANALYSIS->value,
            'driver' => 'fake',
        ]);
        $provider->forceFill([
            'label' => 'Fake analyzer',
            'settings' => ['model' => $model],
            'is_active' => true,
            'is_default' => true,
            'priority' => 0,
        ]);
        $provider->setCredentials($withCredential ? ['api_key' => 'fake-analysis-key'] : null);
        $provider->save();

        app(SettingServiceInterface::class)->set('media_analysis', 'enabled', $enabled);
        Cache::flush();

        return $provider->refresh();
    }

    /**
     * @param  Closure(MediaAnalysisInput): AnalyzerOutcome  $answer
     */
    public static function answer(Closure $answer): void
    {
        self::$answer = $answer;
    }

    public function configurationFields(): array
    {
        return ['settings' => ['model'], 'credentials' => ['api_key']];
    }

    public function missingConfiguration(IntegrationProvider $provider): array
    {
        return ($provider->getCredentials()['api_key'] ?? null) === null ? ['api_key'] : [];
    }

    public function descriptor(IntegrationProvider $provider): AnalyzerDescriptor
    {
        $model = $provider->settings['model'] ?? null;

        return new AnalyzerDescriptor(
            provider: 'fake',
            analyzer: 'fake-detector',
            modelVersion: is_string($model) ? $model : null,
            supportedTypes: self::$supportedTypes,
            acceptedMimeTypes: self::$acceptedMimeTypes,
            maxBytes: self::$maxBytes,
            maxDurationSeconds: self::$maxDurationSeconds,
        );
    }

    public function analyze(MediaAnalysisInput $input, IntegrationProvider $provider): AnalyzerOutcome
    {
        self::$calls++;
        self::$lastInput = $input;

        if (self::$answer !== null) {
            return (self::$answer)($input);
        }

        return AnalyzerOutcome::assessed(
            scores: array_fill_keys($input->types, 0.5),
            confidence: 0.9,
            modelVersion: $provider->settings['model'] ?? null,
            reference: 'fake-ref',
        );
    }
}
