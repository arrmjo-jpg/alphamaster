<?php

declare(strict_types=1);

use App\Modules\Settings\Enums\SettingType;
use App\Modules\Settings\Models\Setting;

test('strict boolean casting accepts valid representations and rejects invalid values', function (): void {
    expect(Setting::strictCastBoolean(true))->toBeTrue()
        ->and(Setting::strictCastBoolean(false))->toBeFalse()
        ->and(Setting::strictCastBoolean(1))->toBeTrue()
        ->and(Setting::strictCastBoolean(0))->toBeFalse()
        ->and(Setting::strictCastBoolean('true'))->toBeTrue()
        ->and(Setting::strictCastBoolean('false'))->toBeFalse()
        ->and(Setting::strictCastBoolean('1'))->toBeTrue()
        ->and(Setting::strictCastBoolean('0'))->toBeFalse();

    // Rejects silent truthy/falsy coercion in PHP (e.g. "random", "yes", 2)
    expect(fn () => Setting::strictCastBoolean('yes'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::strictCastBoolean('random'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::strictCastBoolean(2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::strictCastBoolean(['true']))->toThrow(InvalidArgumentException::class);
});

test('strict integer casting accepts numeric strings and integers and rejects floats and text', function (): void {
    expect(Setting::strictCastInteger(120))->toBe(120)
        ->and(Setting::strictCastInteger('120'))->toBe(120)
        ->and(Setting::strictCastInteger('-5'))->toBe(-5);

    expect(fn () => Setting::strictCastInteger('12.5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::strictCastInteger('abc'))->toThrow(InvalidArgumentException::class);
});

test('strict integer casting reports arrays without an array-to-string warning', function (): void {
    // The message used to interpolate the value directly, emitting a PHP warning.
    expect(fn () => Setting::strictCastInteger(['a']))
        ->toThrow(InvalidArgumentException::class, 'Invalid value [array] for integer setting.');
});

test('strict float casting accepts valid floats and numeric strings and rejects text', function (): void {
    expect(Setting::strictCastFloat(12.5))->toBe(12.5)
        ->and(Setting::strictCastFloat('12.5'))->toBe(12.5)
        ->and(Setting::strictCastFloat(10))->toBe(10.0);

    expect(fn () => Setting::strictCastFloat('not-a-number'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::strictCastFloat(INF))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::strictCastFloat(NAN))->toThrow(InvalidArgumentException::class);
});

test('strict json casting parses valid json payloads and rejects malformed json', function (): void {
    $array = ['feature_a' => true, 'limit' => 50];
    $jsonString = json_encode($array);

    expect(Setting::strictCastJson($array))->toBe($array)
        ->and(Setting::strictCastJson($jsonString))->toBe($array);

    expect(fn () => Setting::strictCastJson('{invalid-json'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::strictCastJson(12))->toThrow(InvalidArgumentException::class);
});

test('strict string casting accepts strings and numbers but never coerces arrays or booleans', function (): void {
    expect(Setting::strictCastString('hello'))->toBe('hello')
        ->and(Setting::strictCastString(42))->toBe('42')
        ->and(Setting::strictCastString(1.5))->toBe('1.5');

    // PHP would turn these into the literal "Array" / "1" / "" with only a warning.
    expect(fn () => Setting::strictCastString(['a' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::strictCastString(true))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::strictCastString(new stdClass))->toThrow(InvalidArgumentException::class);
});

test('serializeValue maps null to null for every type and never to an empty string', function (): void {
    foreach (SettingType::cases() as $type) {
        expect(Setting::serializeValue(null, $type))->toBeNull();
    }
});

test('serialized values round-trip back to their original typed value', function (): void {
    $cases = [
        [SettingType::STRING, 'AlphaMaster', 'AlphaMaster'],
        [SettingType::INTEGER, '42', 42],
        [SettingType::INTEGER, 42, 42],
        [SettingType::FLOAT, 12.5, 12.5],
        [SettingType::FLOAT, 10, 10.0],
        [SettingType::BOOLEAN, true, true],
        [SettingType::BOOLEAN, 'false', false],
        [SettingType::JSON, ['a' => 1, 'b' => [2, 3]], ['a' => 1, 'b' => [2, 3]]],
    ];

    foreach ($cases as [$type, $input, $expected]) {
        $serialized = Setting::serializeValue($input, $type);

        expect($serialized)->toBeString()
            ->and(Setting::castValue($serialized, $type))->toBe($expected);
    }
});

test('float serialization is locale independent and precision preserving', function (): void {
    $value = 0.1 + 0.2;
    $serialized = Setting::serializeValue($value, SettingType::FLOAT);

    expect($serialized)->not->toContain(',')
        ->and(Setting::castValue($serialized, SettingType::FLOAT))->toBe($value);
});

test('json serialization keeps unicode readable and round-trips', function (): void {
    $payload = ['label' => 'العربية', 'path' => 'a/b'];
    $serialized = Setting::serializeValue($payload, SettingType::JSON);

    expect($serialized)->toContain('العربية')
        ->and($serialized)->toContain('a/b')
        ->and(Setting::castValue($serialized, SettingType::JSON))->toBe($payload);
});
