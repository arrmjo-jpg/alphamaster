<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\OpenApi\RemoveIllegalAdditionalItems;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the OpenAPI generator, which is build tooling rather than runtime behaviour.
 *
 * Scramble is a `require-dev` dependency, so a production install (`--no-dev`) does not
 * have it and this provider must boot cleanly without it. That absence is what keeps the
 * generated contract and its UI out of production — enforced by the package not being
 * installed rather than by a configuration flag somebody could flip.
 *
 * Nothing here touches application behaviour. It registers one document transformer that
 * compensates for an upstream defect, described in full on that class.
 */
class OpenApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The guard is the point, not defensive habit: this provider is registered
        // unconditionally in bootstrap/providers.php, and in production the class it
        // configures does not exist.
        if (! class_exists(Scramble::class)) {
            return;
        }

        // Registered by class name rather than as an instance. `afterOpenApiGenerated()`
        // type-hints `callable`, and a DocumentTransformer implements `handle()` rather
        // than `__invoke()`, so passing an instance there is a TypeError. The generator
        // resolves a class-name entry through the container and then dispatches on
        // `instanceof DocumentTransformer`, which is the supported way to register an
        // implementation of the package's own contract.
        Scramble::configure()->withDocumentTransformers(RemoveIllegalAdditionalItems::class);
    }
}
