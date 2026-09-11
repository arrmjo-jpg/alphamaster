<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The model the old global setting shipped with. Carried over only if an operator
     * chose something else: the default was never a choice, and pinning it now would
     * keep a retired model in place of the driver's current default.
     */
    private const OLD_DEFAULT_MODEL = 'gpt-4o-mini';

    /**
     * AI providers become what an administrator sets up — a key and a model — and
     * nothing about how the driver reaches the vendor.
     *
     * A single `ai.translation_model` was sent to whichever provider was the default,
     * so switching the default to Anthropic sent it an OpenAI model name. Each provider
     * now keeps its model in its own `settings`. A model an operator had chosen is moved
     * onto the provider that was answering with it; the old setting row is then removed
     * rather than left behind as an orphan.
     *
     * The rows shipped with an empty `base_url` so an operator could see the field
     * existed. The endpoint belongs to the driver now, so the field is removed rather
     * than left to be edited. And a provider is ready when it holds a key — there is no
     * separate switch — so `is_active` is brought into line with that.
     *
     * The Gemini row is inserted here as well as in the seeder, so an installation that
     * migrates without reseeding still offers it. Without a key, like every vendor row.
     */
    public function up(): void
    {
        $this->addGemini();
        $this->removeDriverSettings();
        $this->carryModelOver();
    }

    /**
     * The Gemini row is removed if nobody has given it a key. The old setting is not
     * recreated: the settings synchroniser restores it from its definition when the
     * code that defines it is back. Nor is the empty `base_url`: the code that read it
     * treated empty as absent.
     */
    public function down(): void
    {
        DB::table('integration_providers')
            ->where('capability', 'ai')
            ->where('driver', 'gemini')
            ->whereNull('credentials')
            ->delete();
    }

    private function addGemini(): void
    {
        $exists = DB::table('integration_providers')
            ->where('capability', 'ai')
            ->where('driver', 'gemini')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('integration_providers')->insert([
            'id' => (string) Str::ulid(),
            'capability' => 'ai',
            'driver' => 'gemini',
            'label' => 'Google Gemini',
            'credentials' => null,
            'settings' => null,
            'is_active' => false,
            'is_default' => false,
            'priority' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * No endpoint in any AI row, and ready exactly when a key is held.
     */
    private function removeDriverSettings(): void
    {
        $rows = DB::table('integration_providers')->where('capability', 'ai')->get();

        foreach ($rows as $row) {
            $settings = json_decode((string) $row->settings, true);
            $settings = is_array($settings) ? $settings : [];

            unset($settings['base_url']);

            DB::table('integration_providers')->where('id', $row->id)->update([
                'settings' => $settings === [] ? null : json_encode($settings),
                'is_active' => $row->credentials !== null,
            ]);
        }
    }

    private function carryModelOver(): void
    {
        $setting = DB::table('settings')->where('group', 'ai')->where('key', 'translation_model')->first();

        if ($setting === null) {
            return;
        }

        $model = $this->decode($setting->value);

        if ($model !== '' && $model !== self::OLD_DEFAULT_MODEL) {
            $default = DB::table('integration_providers')
                ->where('capability', 'ai')
                ->where('is_default', true)
                ->first();

            if ($default !== null) {
                $settings = json_decode((string) $default->settings, true);
                $settings = is_array($settings) ? $settings : [];

                if (! is_string($settings['model'] ?? null) || $settings['model'] === '') {
                    $settings['model'] = $model;

                    DB::table('integration_providers')
                        ->where('id', $default->id)
                        ->update(['settings' => json_encode($settings)]);
                }
            }
        }

        // Its translations and revisions go with it (the foreign keys cascade): a
        // setting that no longer exists has no history worth keeping apart from the
        // audit trail, which is untouched.
        DB::table('settings')->where('id', $setting->id)->delete();
    }

    /**
     * A stored setting value, whether it was written as JSON or as plain text.
     */
    private function decode(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $decoded = json_decode($value, true);

        return trim(is_string($decoded) ? $decoded : $value);
    }
};
