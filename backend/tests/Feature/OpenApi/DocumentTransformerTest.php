<?php

declare(strict_types=1);

use App\Support\OpenApi\RemoveIllegalAdditionalItems;
use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/*
|--------------------------------------------------------------------------
| The transformer that compensates for an upstream defect
|--------------------------------------------------------------------------
|
| dedoc/scramble v0.13.42 emits `additionalItems: false` beside `prefixItems`
| for a fixed-length array. That keyword was removed in JSON Schema draft
| 2020-12, the dialect OpenAPI 3.1 uses, so the document looks valid and fails
| validation (ADR 0029 item 19).
|
| These are unit tests over the object model. Whether the *whole* generated
| document validates is the `scripts/gate.sh openapi` gate's job, including the
| control that proves removing this transformer brings the failure back — a
| property no unit test can establish.
|
*/

/** A tuple shaped the way the generator shapes one, with the illegal keyword set. */
function tupleWithIllegalKeyword(): ArrayType
{
    $tuple = new ArrayType;
    $tuple->setPrefixItems([new StringType, new StringType]);
    $tuple->setMin(2);
    $tuple->setMax(2);
    $tuple->setAdditionalItems(false);

    return $tuple;
}

/**
 * The context the generator would hand a transformer.
 *
 * Constructed directly rather than resolved from the container: it is a value object
 * the generator builds per run, not a binding. The transformer reads only the document,
 * so this exists to satisfy the contract's signature.
 */
function transformerContext(OpenApi $document): OpenApiContext
{
    return new OpenApiContext($document, new GeneratorConfig);
}

function runTransformer(OpenApi $document): void
{
    (new RemoveIllegalAdditionalItems)->handle($document, transformerContext($document));
}

test('the illegal keyword is present before the transformer runs', function (): void {
    // The premise. Without this the other tests could pass against a generator that
    // never emitted the keyword, and would prove nothing about the defect.
    expect(tupleWithIllegalKeyword()->toArray())->toHaveKey('additionalItems');
});

test('the keyword is removed, not replaced', function (): void {
    $tuple = tupleWithIllegalKeyword();

    $document = OpenApi::make('3.1.0');
    $document->components->addSchema('Tuple', Schema::fromType($tuple));

    runTransformer($document);

    $emitted = $tuple->toArray();

    // Absent entirely. `additionalItems: null` and `additionalItems: false` are both
    // invalid 3.1; only absence is correct, and `ArrayType::toArray()` filters nulls
    // out of its output, which is why the property is nulled rather than unset.
    expect($emitted)->not->toHaveKey('additionalItems')
        ->and($tuple->additionalItems)->toBeNull();
});

test('nothing else about the schema is touched', function (): void {
    $tuple = tupleWithIllegalKeyword();
    $before = $tuple->toArray();
    unset($before['additionalItems']);

    $document = OpenApi::make('3.1.0');
    $document->components->addSchema('Tuple', Schema::fromType($tuple));

    runTransformer($document);

    // The tuple's own constraints still describe it: `prefixItems` for the shape,
    // `minItems`/`maxItems` for the length — which is why removing the keyword loses
    // nothing rather than loosening the schema.
    expect($tuple->toArray())->toBe($before)
        ->and($tuple->toArray())->toHaveKey('prefixItems')
        ->and($tuple->toArray())->toHaveKey('minItems')
        ->and($tuple->toArray())->toHaveKey('maxItems');
});

test('an array that never carried the keyword is left exactly as it was', function (): void {
    $plain = new ArrayType;
    $plain->setItems(new StringType);
    $before = $plain->toArray();

    $document = OpenApi::make('3.1.0');
    $document->components->addSchema('Plain', Schema::fromType($plain));

    runTransformer($document);

    expect($plain->toArray())->toBe($before);
});

test('the keyword is removed wherever it appears, not only at the top level', function (): void {
    // Nested inside another array, which is the shape a paginated response produces.
    // A transformer that only inspected the schemas it was handed directly would pass
    // every other test here and still emit an invalid document.
    $nested = tupleWithIllegalKeyword();
    $outer = new ArrayType;
    $outer->setItems($nested);

    $document = OpenApi::make('3.1.0');
    $document->components->addSchema('Nested', Schema::fromType($outer));

    runTransformer($document);

    expect($nested->toArray())->not->toHaveKey('additionalItems');
});

test('a schema that refers to itself does not hang the transformer', function (): void {
    // A recursive schema is a legitimate shape, not a fault. The walk carries an
    // SplObjectStorage for exactly this, and a test that loops forever is a test
    // nobody can run.
    $recursive = new ArrayType;
    $recursive->setItems($recursive);
    $recursive->setAdditionalItems(false);

    $document = OpenApi::make('3.1.0');
    $document->components->addSchema('Recursive', Schema::fromType($recursive));

    runTransformer($document);

    expect($recursive->additionalItems)->toBeNull();
});

test('the transformer holds no reference to a document it has finished with', function (): void {
    $first = tupleWithIllegalKeyword();
    $documentOne = OpenApi::make('3.1.0');
    $documentOne->components->addSchema('One', Schema::fromType($first));

    $transformer = new RemoveIllegalAdditionalItems;
    $transformer->handle($documentOne, transformerContext($documentOne));

    // A second document must be walked fully, not skipped because the first run's
    // visited-set persisted.
    $second = tupleWithIllegalKeyword();
    $documentTwo = OpenApi::make('3.1.0');
    $documentTwo->components->addSchema('Two', Schema::fromType($second));

    $transformer->handle($documentTwo, transformerContext($documentTwo));

    expect($second->toArray())->not->toHaveKey('additionalItems');
});
