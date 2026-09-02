<?php

declare(strict_types=1);

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
        ->and(fn () => Setting::strictCastBoolean(2))->toThrow(InvalidArgumentException::class);
});

test('strict integer casting accepts numeric strings and integers and rejects floats and text', function (): void {
    expect(Setting::strictCastInteger(120))->toBe(120)
        ->and(Setting::strictCastInteger('120'))->toBe(120)
        ->and(Setting::strictCastInteger('-5'))->toBe(-5);

    expect(fn () => Setting::strictCastInteger('12.5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Setting::strictCastInteger('abc'))->toThrow(InvalidArgumentException::class);
});

test('strict float casting accepts valid floats and numeric strings and rejects text', function (): void {
    expect(Setting::strictCastFloat(12.5))->toBe(12.5)
        ->and(Setting::strictCastFloat('12.5'))->toBe(12.5)
        ->and(Setting::strictCastFloat(10))->toBe(10.0);

    expect(fn () => Setting::strictCastFloat('not-a-number'))->toThrow(InvalidArgumentException::class);
});

test('strict json casting parses valid json payloads and rejects malformed json', function (): void {
    $array = ['feature_a' => true, 'limit' => 50];
    $jsonString = json_encode($array);

    expect(Setting::strictCastJson($array))->toBe($array)
        ->and(Setting::strictCastJson($jsonString))->toBe($array);

    expect(fn () => Setting::strictCastJson('{invalid-json'))->toThrow(InvalidArgumentException::class);
});
