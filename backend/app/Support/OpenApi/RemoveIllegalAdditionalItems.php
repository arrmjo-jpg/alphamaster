<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use SplObjectStorage;

/**
 * TEMPORARY WORKAROUND for an open upstream defect in dedoc/scramble v0.13.42.
 *
 * **This is not the upstream fix, and it must not be described as one.** It compensates
 * for the defect in this repository's output until the package stops producing it.
 *
 * ## The defect
 *
 * For a fixed-length array literal Scramble emits a tuple using `prefixItems`, which is
 * correct, and alongside it `additionalItems: false`, which is not. That keyword was
 * removed in JSON Schema draft 2020-12 — the dialect OpenAPI 3.1 uses — so the document
 * looks valid and fails validation. Redocly reports `Property additionalItems is not
 * expected here`. The keyword is set at `TypeTransformer.php:235` via
 * `setAdditionalItems(false)` and serialised by `ArrayType.php:101`; it appears nowhere
 * in this application's own code.
 *
 * ADR 0029 item 19 records this as *blocked upstream, not deferred by project choice*,
 * and forbids three responses: changing the application to work around it, disabling a
 * linter rule, and patching `vendor/`. This does none of those. It is registered through
 * `Scramble::afterOpenApiGenerated()`, a published extension point of the package, and
 * it corrects the serialiser's output rather than this project's contract — no DTO,
 * controller or resource is altered to make a third-party serialiser behave.
 *
 * ## Why removal rather than substitution
 *
 * The valid 3.1 forms are `items: false` or the absence of the keyword. This removes it.
 * Substituting a value would be this project asserting a constraint the generator never
 * inferred; `minItems` and `maxItems` already fix the tuple's length, so removal loses
 * nothing. `ArrayType::toArray()` filters null values out of its output, so setting the
 * property to null deletes the key rather than emitting `additionalItems: null` — which
 * would be a different and equally invalid document.
 *
 * ## When this is deleted
 *
 * On an upstream release that emits `items`, or nothing, in its place. Removal is: bump
 * the constraint, delete this class and its provider, delete its tests, regenerate. The
 * `openapi` gate then proves the document still validates without it — which is why the
 * negative control asserting that removing this class *reintroduces* the failure is the
 * one test here that must never be relaxed.
 */
final class RemoveIllegalAdditionalItems implements DocumentTransformer
{
    /**
     * Objects already visited, so a document containing a cycle terminates.
     *
     * Scramble's schema objects reference each other, and a reference loop is a
     * legitimate shape for a recursive schema rather than a fault to guard against.
     *
     * @var SplObjectStorage<object, true>|null
     */
    private ?SplObjectStorage $seen = null;

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $this->seen = new SplObjectStorage;

        $this->strip($document);

        // Released so the transformer holds no reference to a document it has finished
        // with, and so a second invocation starts from a clean slate rather than
        // treating the previous document's objects as already visited.
        $this->seen = null;
    }

    /**
     * Walk everything reachable from the document and remove the keyword wherever it
     * appears.
     *
     * A reflective walk rather than a path-by-path descent through
     * paths → operations → responses → content → schema. That descent would have to
     * know every intermediate shape the generator uses, and would silently stop
     * covering a location the moment the package introduced one — leaving an invalid
     * node in a document the gate reports as clean. Visiting every reachable object
     * cannot develop that particular blind spot.
     */
    private function strip(mixed $node): void
    {
        if (is_array($node)) {
            foreach ($node as $item) {
                $this->strip($item);
            }

            return;
        }

        if (! is_object($node) || $this->seen === null || $this->seen->contains($node)) {
            return;
        }

        $this->seen->attach($node, true);

        if ($node instanceof ArrayType && $node->additionalItems !== null) {
            // Null, not false: false is the value that is invalid here, and null is
            // what `ArrayType::toArray()` filters out of the emitted document.
            $node->additionalItems = null;
        }

        foreach (get_object_vars($node) as $value) {
            $this->strip($value);
        }
    }
}
