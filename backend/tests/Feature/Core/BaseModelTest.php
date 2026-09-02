<?php

declare(strict_types=1);

use App\Modules\Core\Models\BaseModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

test('base model has string key type and disabled auto-incrementing', function (): void {
    $model = new class extends BaseModel {};

    expect($model->getKeyType())->toBe('string')
        ->and($model->getIncrementing())->toBeFalse();
});

test('base model automatically generates a 26-character ULID on creation', function (): void {
    Schema::create('test_dummy_models', function (Blueprint $table): void {
        $table->ulid('id')->primary();
        $table->string('name');
        $table->timestamps();
    });

    $modelClass = new class extends BaseModel
    {
        protected $table = 'test_dummy_models';
    };

    $instance = $modelClass::create(['name' => 'Alphamaster Test']);

    expect($instance->id)->toBeString()
        ->and(strlen($instance->id))->toBe(26);

    Schema::dropIfExists('test_dummy_models');
});
