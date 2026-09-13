<?php

declare(strict_types=1);

namespace App\Modules\Core\Controllers\Admin;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Cache\CacheNamespace;
use App\Modules\Core\Contracts\AuditRecorderContract;
use App\Modules\Core\Contracts\PlatformCacheContract;
use App\Modules\Core\Controllers\BaseApiController;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * The application cache, as an operator manages it (ADR 0035, ADR 0051 §7).
 *
 * Policies and scopes, never keys. An operator sees each namespace's declared policy and
 * can invalidate one namespace — which bumps its generation, so every entry in it misses
 * at once and nothing outside it is touched. There is no endpoint that reads, writes or
 * deletes a key, and none that empties the store.
 *
 * Two namespaces cannot be invalidated from here. `auth` is the source of truth for MFA
 * challenges and social login states in flight, so invalidating it would sign people out
 * of the middle of a sign-in. `authorization` belongs to the permission package, which
 * manages its own entries.
 */
class CacheAdminController extends BaseApiController
{
    private const PROTECTED = [CacheNamespace::AUTH, CacheNamespace::AUTHORIZATION];

    public function __construct(
        private readonly PlatformCacheContract $cache,
        private readonly AuditRecorderContract $audit,
    ) {}

    /**
     * Cache namespaces and their policies.
     */
    #[Response(200, type: 'array{success: bool, data: list<array{namespace: string, ttl_seconds: int, failure_mode: string, version: int, generation: int, flushable: bool}>}')]
    public function index(): JsonResponse
    {
        return $this->successResponse(array_map(
            fn (CacheNamespace $namespace): array => $this->describe($namespace),
            CacheNamespace::cases(),
        ));
    }

    /**
     * Invalidate one cache namespace.
     *
     * Every entry in the namespace misses from now on and is rebuilt from its source. Recorded in the audit trail.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: array{namespace: string, ttl_seconds: int, failure_mode: string, version: int, generation: int, flushable: bool}}')]
    #[Response(404, description: 'NOT_FOUND: no such namespace.')]
    #[Response(409, description: 'CACHE_NAMESPACE_PROTECTED: auth and authorization cannot be invalidated here.')]
    public function flush(string $namespace): JsonResponse
    {
        $target = CacheNamespace::tryFrom($namespace);

        if ($target === null) {
            return $this->errorResponse('NOT_FOUND', 'api.error.model_not_found', null, 404);
        }

        if (in_array($target, self::PROTECTED, true)) {
            return $this->errorResponse('CACHE_NAMESPACE_PROTECTED', 'api.error.cache.namespace_protected', null, 409);
        }

        $before = $this->cache->generation($target);
        $this->cache->flushNamespace($target);

        $this->audit->succeeded(AuditAction::CACHE_NAMESPACE_FLUSHED, $target->value, [
            'generation_before' => $before,
            'generation_after' => $this->cache->generation($target),
        ]);

        return $this->successResponse($this->describe($target), 'The cache namespace was invalidated.');
    }

    /**
     * @return array{namespace: string, ttl_seconds: int, failure_mode: string, version: int, generation: int, flushable: bool}
     */
    private function describe(CacheNamespace $namespace): array
    {
        $policy = $namespace->policy();

        return [
            'namespace' => $namespace->value,
            'ttl_seconds' => $policy->ttl,
            'failure_mode' => $policy->failsOpen() ? 'fail_open' : 'fail_closed',
            'version' => $policy->version,
            'generation' => $this->cache->generation($namespace),
            'flushable' => ! in_array($namespace, self::PROTECTED, true),
        ];
    }
}
