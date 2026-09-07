<?php

declare(strict_types=1);

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [

    /*
    |--------------------------------------------------------------------------
    | Which routes the contract describes
    |--------------------------------------------------------------------------
    |
    | Everything under the versioned API prefix, which is the whole public and
    | administrative surface (ADR 0001 — the backend is API-only).
    |
    */

    'api_path' => 'api/v1',

    'api_domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Where the generated document is written
    |--------------------------------------------------------------------------
    |
    | Relative to the application root. The artefact is committed, and CI fails if
    | regenerating produces anything different — a specification nobody compares
    | against the code drifts from it, which is the whole reason ADR 0010 chose an
    | inferred contract over a maintained one.
    |
    */

    'export_path' => 'openapi.json',

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => '',
    ],

    'ui' => [
        'title' => 'AlphaMaster API',
        'theme' => 'light',
        'hide_try_it' => true,
        'hide_schemas' => false,
        'logo' => '',
        'try_it_credentials_policy' => 'omit',
        'layout' => 'responsive',
    ],

    'servers' => null,

    /*
    |--------------------------------------------------------------------------
    | Who may reach the documentation routes
    |--------------------------------------------------------------------------
    |
    | The package registers `docs/api` and `docs/api.json` when it is installed.
    | The primary control is that it is a `require-dev` dependency, so a production
    | install does not have it and the routes cannot exist at all — absence rather
    | than a flag somebody can flip.
    |
    | This middleware is the second layer, for the environments that do install it:
    | RestrictedDocsAccess permits the routes only when the application is in debug
    | mode. The contract enumerates every endpoint, which is exactly the map an
    | attacker would otherwise have to assemble by hand.
    |
    */

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [],

    /*
    |--------------------------------------------------------------------------
    | How the contract describes authentication
    |--------------------------------------------------------------------------
    |
    | Without this the document declares no security at all, and every protected
    | operation is indistinguishable from a public one — which Redocly reports as 55
    | `security-defined` errors and which would give a generated client (ADR 0011) no
    | way to know an endpoint needs a token.
    |
    | The strategy infers bearer authentication from route middleware: every route
    | behind `auth:sanctum` is documented as requiring it, and routes without it are
    | marked explicitly public rather than left ambiguous. Inferred from the routes
    | themselves, so it cannot drift from what the perimeter actually enforces
    | (ADR 0012) the way a hand-written security block would.
    |
    */

    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
];
