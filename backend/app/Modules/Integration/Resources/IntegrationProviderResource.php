<?php

declare(strict_types=1);

namespace App\Modules\Integration\Resources;

use App\Modules\Integration\Exceptions\CredentialDecryptionException;
use App\Modules\Integration\Models\IntegrationProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A configured provider.
 *
 * Credentials are reported only as present or absent. The values never leave the
 * server once stored, so a compromised admin session cannot read them back.
 *
 * @property-read IntegrationProvider $resource
 */
class IntegrationProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The label sits beside the value it describes and never replaces it
        // (ADR 0030/0031). The value stays the identifier a client matches on;
        // the label is resolved from the request locale each time it is read.
        return [
            'id' => $this->resource->id,
            'capability' => $this->resource->capability->value,
            'capability_label' => $this->resource->capability->label(),
            'driver' => $this->resource->driver,
            'label' => $this->resource->label,
            /**
             * Non-secret configuration, keyed by setting name.
             *
             * @var array<string, string|null>|null
             */
            'settings' => $this->resource->settings,
            'has_credentials' => $this->resource->hasCredentials(),
            /**
             * For a Firebase provider with a stored service account, the project it sends
             * through. Null for every other provider, and when nothing is stored.
             *
             * @var array{project_id: string|null}|null
             */
            'credential_summary' => $this->credentialSummary(),
            'is_active' => $this->resource->is_active,
            'is_default' => $this->resource->is_default,
            'priority' => $this->resource->priority,
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }

    /**
     * What can be said about a stored service account without revealing it.
     *
     * The project id is the one value in a service account that is not secret — it is in
     * every client's Firebase configuration — and it is what an operator needs to see
     * that the right file was saved. It is read from the credential rather than kept
     * beside it, so it can never describe a key other than the one in use; a credential
     * that no longer decrypts reports a null project rather than failing the listing.
     *
     * @return array{project_id: string|null}|null
     */
    private function credentialSummary(): ?array
    {
        if ($this->resource->driver !== 'fcm' || ! $this->resource->hasCredentials()) {
            return null;
        }

        try {
            $projectId = $this->resource->getCredentials()['project_id'] ?? null;
        } catch (CredentialDecryptionException) {
            $projectId = null;
        }

        return ['project_id' => is_string($projectId) ? $projectId : null];
    }
}
