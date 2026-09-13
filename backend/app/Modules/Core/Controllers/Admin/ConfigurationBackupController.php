<?php

declare(strict_types=1);

namespace App\Modules\Core\Controllers\Admin;

use App\Modules\Core\Backup\ConfigurationExporter;
use App\Modules\Core\Backup\ConfigurationRestorer;
use App\Modules\Core\Backup\RestoreRefusedException;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\Core\Requests\ExportConfigurationRequest;
use App\Modules\Core\Requests\RestoreConfigurationRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Moving configuration in and out of this deployment (ADR 0039).
 *
 * Both operations require `settings.backup.manage`, which is deliberately not
 * `settings.update`: exporting reads every non-secret value and lists which secrets
 * exist, and restoring rewrites configuration wholesale. Neither is an ordinary
 * settings change, and neither should come with the permission to make one.
 */
class ConfigurationBackupController extends BaseApiController
{
    /**
     * Write a configuration export to the configured disk.
     *
     * The response says where it went and what it left out. It does not contain the
     * export: the artefact on the disk is the artefact, and a response body reproducing
     * it would be a second copy travelling by a route with different access controls.
     */
    // Spelled out because the outcome is a plain data class whose `toArray` widens to
    // `array<string, mixed>`: the generator published `sections` and `omitted_secrets`
    // as arrays of nothing, which is not enough for a client to render the receipt.
    #[Response(200, type: 'array{success: bool, message: string, data: array{location: string, sections: list<string>, includes_secrets: bool, omitted_secrets: list<string>}}')]
    public function export(ExportConfigurationRequest $request, ConfigurationExporter $exporter): JsonResponse
    {
        // Whether to carry ciphertext is the operator's call, because the right answer
        // depends on where the export is going: portable within a deployment, worthless
        // and worth stealing outside one (ADR 0039).
        $outcome = $exporter->export((bool) ($request->validated()['include_secrets'] ?? false));

        return $this->successResponse($outcome->toArray(), 'api.backup.exported');
    }

    /**
     * Read a configuration export back over what is running.
     *
     * Validates before it writes, and reports what it declined rather than coercing it.
     * A mismatched encryption key does not fail the restore — it restores everything
     * that is not encrypted and names every credential it could not, which is the usual
     * shape of seeding a new environment.
     */
    // Likewise. A restore's whole value to an operator is the per-section report of
    // what it declined and why, and that was published as an array of nothing.
    #[Response(200, type: 'array{success: bool, message: string, data: array{location: string, encrypted_restorable: bool, sections: list<array{section: string, restored: int, skipped: list<array{key: string, reason: string}>}>}}')]
    public function restore(RestoreConfigurationRequest $request, ConfigurationRestorer $restorer): JsonResponse
    {
        try {
            $outcome = $restorer->restore((string) $request->validated()['location']);
        } catch (RestoreRefusedException $e) {
            // Nothing was written. A restore that began on a document it could not read
            // would leave a deployment half-configured from a file nobody can inspect.
            return $this->errorResponse('CONFIGURATION_RESTORE_REFUSED', $e->translationKey(), null, 422);
        }

        return $this->successResponse($outcome->toArray(), 'api.backup.restored');
    }
}
