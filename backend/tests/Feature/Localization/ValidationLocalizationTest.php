<?php

declare(strict_types=1);

use App\Modules\Localization\Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The container runs against Redis, where a cache entry outlives a test.
    Cache::flush();

    $this->seed(LanguageSeeder::class);
});

/**
 * The first validation message for a field, as a caller would receive it.
 */
function firstError(mixed $test, string $locale, array $payload, string $field): ?string
{
    return $test->withHeaders(['X-Locale' => $locale])
        ->postJson('/api/v1/auth/login', $payload)
        ->json('error.details.'.$field.'.0');
}

// ── The locale reaches the message ────────────────────────────────────────────

/**
 * A value that fails a rule carrying a sentence and a placeholder, rather than a bare
 * `required`. The login field takes an email address or a phone number and so declares
 * no shape rule of its own — asserting on one would be asserting that the endpoint
 * tells an unauthenticated caller which kind of identifier it recognised, which it
 * deliberately does not.
 */
function tooLongIdentifier(): string
{
    return str_repeat('a', 300);
}

test('a request in Arabic receives Arabic validation messages', function (): void {
    $message = firstError($this, 'ar', ['identifier' => tooLongIdentifier()], 'identifier');

    expect($message)->toBeString()
        ->and($message)->toContain('يجب ألا يتجاوز طول')
        ->and($message)->toContain('255')
        ->and($message)->not->toContain('must not be greater');
});

test('a request in English is unchanged', function (): void {
    $message = firstError($this, 'en', ['identifier' => tooLongIdentifier()], 'identifier');

    expect($message)->toContain('must not be greater than 255 characters');
});

test('the same request in two locales differs only in language', function (): void {
    $arabic = firstError($this, 'ar', [], 'password');
    $english = firstError($this, 'en', [], 'password');

    expect($arabic)->not->toBe($english)
        ->and($arabic)->toContain('مطلوب')
        ->and($english)->toContain('is required');
});

// ── :attribute comes from the central list ───────────────────────────────────

test('the field name in a message is the central Arabic attribute', function (): void {
    $message = firstError($this, 'ar', [], 'password');

    // From validation.attributes.password, not the raw field name.
    expect($message)->toContain('كلمة المرور')
        ->and($message)->not->toContain('password');
});

test('the field name in English is the central attribute too', function (): void {
    $message = firstError($this, 'en', ['identifier' => tooLongIdentifier()], 'identifier');

    // From validation.attributes.identifier, not the raw field name.
    expect($message)->toContain('Email or Phone Number')
        ->and($message)->not->toContain('identifier field');
});

test('no FormRequest declares its own attributes', function (): void {
    // The names are central so a field reads the same everywhere. A local
    // attributes() method would be a second place for one to be defined.
    $declaring = [];

    foreach (glob(app_path('Modules/*/Requests/*.php')) as $file) {
        if (str_contains((string) file_get_contents($file), 'function attributes')) {
            $declaring[] = basename($file);
        }
    }

    expect($declaring)->toBe([]);
});

// ── The custom messages ──────────────────────────────────────────────────────

test('a custom message is localized', function (): void {
    app()->setLocale('ar');

    $validator = Validator::make(
        ['name' => 'Not An Identifier', 'permissions' => []],
        ['name' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/']],
        ['name.regex' => __('validation.custom.name.regex')]
    );

    expect($validator->errors()->first('name'))
        ->toContain('معرّفًا بحروف صغيرة');
});

test('the same custom message reads in English', function (): void {
    app()->setLocale('en');

    $validator = Validator::make(
        ['name' => 'Not An Identifier'],
        ['name' => ['regex:/^[a-z][a-z0-9_]*$/']],
        ['name.regex' => __('validation.custom.name.regex')]
    );

    expect($validator->errors()->first('name'))
        ->toContain('lowercase identifier');
});

test('every custom key resolves in both locales and never leaks a raw key', function (): void {
    $keys = [
        'validation.custom.phone.regex',
        'validation.custom.name.regex',
        'validation.custom.label.unusable',
        'validation.custom.permissions.*.in',
        'validation.custom.collection.regex',
        'validation.custom.file.max',
        'validation.custom.translations.*.locale.exists',
        'validation.custom.settings.required',
        'validation.custom.settings.array',
        'validation.custom.settings.max',
    ];

    expect($keys)->toHaveCount(10);

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($keys as $key) {
            $message = __($key);

            expect($message)->toBeString()
                ->and($message)->not->toBe($key, "{$key} is untranslated in {$locale}")
                ->and($message)->not->toStartWith('validation.');
        }
    }
});

// ── The placeholder that replaced a concatenated constant ────────────────────

test('the settings batch message takes its limit from the rule', function (): void {
    // It used to be built by concatenating a class constant into the sentence,
    // which put the number in two places. :max is filled from the rule itself.
    foreach (['en' => '100', 'ar' => '100'] as $locale => $expected) {
        app()->setLocale($locale);

        $validator = Validator::make(
            ['settings' => array_fill(0, 101, 'x')],
            ['settings' => ['required', 'array', 'min:1', 'max:100']],
            ['settings.max' => __('validation.custom.settings.max')]
        );

        expect($validator->errors()->first('settings'))
            ->toContain($expected)
            ->and($validator->errors()->first('settings'))
            ->not->toContain(':max');
    }
});

test('the settings message tracks the rule rather than a hard-coded number', function (): void {
    app()->setLocale('en');

    $validator = Validator::make(
        ['settings' => array_fill(0, 6, 'x')],
        ['settings' => ['array', 'max:5']],
        ['settings.max' => __('validation.custom.settings.max')]
    );

    // A different limit in the rule produces a different number in the message,
    // which a concatenated constant could never do.
    expect($validator->errors()->first('settings'))->toContain('5')
        ->and($validator->errors()->first('settings'))->not->toContain('100');
});

// ── Fallback ─────────────────────────────────────────────────────────────────

test('an unconfigured locale falls back rather than showing a key', function (): void {
    // fr is not an active platform language, so LocaleResolver refuses it and
    // the request is answered in the platform default.
    $message = firstError($this, 'fr', [], 'password');

    expect($message)->toBeString()
        ->and($message)->toContain('is required')
        ->and($message)->not->toContain('validation.');
});

test('the catalogue is complete in both locales', function (): void {
    // A rule added later must not produce an English sentence inside an Arabic
    // response, so every key the framework defines is translated, not only the
    // rules currently in use.
    $flatten = function (array $lines) use (&$flatten): array {
        $out = [];

        foreach ($lines as $key => $value) {
            if ($key === 'custom' || $key === 'attributes') {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $sub => $text) {
                    $out[$key.'.'.$sub] = $text;
                }

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    };

    $english = $flatten(Lang::get('validation', [], 'en'));
    $arabic = $flatten(Lang::get('validation', [], 'ar'));

    expect(array_diff(array_keys($english), array_keys($arabic)))->toBe([])
        ->and(array_diff(array_keys($arabic), array_keys($english)))->toBe([]);

    // and none of the Arabic entries is still the English sentence
    $untranslated = [];

    foreach ($english as $key => $text) {
        if (($arabic[$key] ?? null) === $text) {
            $untranslated[] = $key;
        }
    }

    expect($untranslated)->toBe([]);
});

test('every placeholder survives translation', function (): void {
    $english = Lang::get('validation', [], 'en');
    $arabic = Lang::get('validation', [], 'ar');

    $dropped = [];

    foreach ($english as $key => $value) {
        if ($key === 'custom' || $key === 'attributes' || is_array($value)) {
            continue;
        }

        preg_match_all('/:[a-z_]+/', $value, $inEnglish);
        preg_match_all('/:[a-z_]+/', (string) ($arabic[$key] ?? ''), $inArabic);

        if (array_diff($inEnglish[0], $inArabic[0]) !== []) {
            $dropped[] = $key;
        }
    }

    expect($dropped)->toBe([]);
});

// ── Nothing existing moved ───────────────────────────────────────────────────

test('every FormRequest still validates', function (): void {
    $requests = glob(app_path('Modules/*/Requests/*.php'));

    // 14 through Phase 16A; Phase 16B-2 added the rollback request, 16B-3 the
    // credential-rotation one, and 16B-5 the export and restore pair. The account
    // lifecycle added the create and update pair, M3-A the announcement, phone
    // verification the request that carries a code, and the translation workshop its
    // write. AI added asking for suggestions and accepting one, and push the device registration.
    expect($requests)->toHaveCount(26);

    foreach ($requests as $file) {
        $source = (string) file_get_contents($file);

        expect($source)->toContain('function rules');
    }
});

test('the requests with custom messages still declare them', function (): void {
    $expected = [
        'MfaEnrolRequest.php' => 1,
        // RoleRequest declares one here; its second custom message belongs to a
        // closure rule and is asserted separately below.
        'RoleRequest.php' => 1,
        'StoreMediaRequest.php' => 2,
        // The password minimum is a configured setting, so its message has to name
        // the number that actually applies rather than the one in the rule string.
        'StoreUserRequest.php' => 1,
        'UpdateNotificationTemplateRequest.php' => 1,
        'UpdateGroupSettingsRequest.php' => 3,
        // `preferred_locale` is validated against the languages table, and "the
        // selected preferred locale is invalid" says nothing an operator can act on.
        'UpdateUserRequest.php' => 1,
    ];

    $found = [];

    foreach (glob(app_path('Modules/*/Requests/*.php')) as $file) {
        $source = (string) file_get_contents($file);

        if (! str_contains($source, 'function messages')) {
            continue;
        }

        preg_match('/function messages\(\).*?\n    \}/s', $source, $body);
        $found[basename($file)] = preg_match_all("/__\('validation\.custom\./", $body[0] ?? '');
    }

    ksort($found);
    ksort($expected);

    expect($found)->toBe($expected);

    // The role label rule cannot express itself through messages(), because a
    // closure rule names its own message. It is still a custom key and still has
    // to resolve, so it is checked here rather than going uncounted.
    expect((string) file_get_contents(app_path('Modules/Authorization/Requests/RoleRequest.php')))
        ->toContain("__('validation.custom.label.unusable')");
});

test('custom messages and attributes are complete in both locales', function (): void {
    // The completeness check above deliberately skips these two sections, so
    // they get their own. A custom message missing from ar still resolves,
    // because __() falls back to en, and would ship English text to an Arabic
    // caller without any key appearing to be absent.
    $leaves = function (array $node, string $prefix = '') use (&$leaves): array {
        $out = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $out += is_array($value) ? $leaves($value, $path) : [$path => $value];
        }

        return $out;
    };

    // Read the files this platform owns rather than the translator's view:
    // Laravel merges its own catalogue underneath ours with
    // array_replace_recursive, so the framework's placeholder stub
    // (custom.attribute-name.rule-name) shows up in en and in no real
    // catalogue, and would read as a gap in ar that isn't one.
    $english = require base_path('lang/en/validation.php');
    $arabic = require base_path('lang/ar/validation.php');

    foreach (['custom', 'attributes'] as $section) {
        $en = $leaves($english[$section] ?? []);
        $ar = $leaves($arabic[$section] ?? []);

        expect(array_diff(array_keys($en), array_keys($ar)))->toBe([], $section.' is missing keys in ar')
            ->and(array_diff(array_keys($ar), array_keys($en)))->toBe([], $section.' has extra keys in ar');

        $stillEnglish = array_keys(array_filter(
            $en,
            static fn (string $text, string $key): bool => ($ar[$key] ?? null) === $text,
            ARRAY_FILTER_USE_BOTH
        ));

        expect($stillEnglish)->toBe([], $section.' still holds the English text in ar');
    }
});
