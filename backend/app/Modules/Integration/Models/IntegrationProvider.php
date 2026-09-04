<?php

declare(strict_types=1);

namespace App\Modules\Integration\Models;

use App\Modules\Core\Models\BaseModel;
use App\Modules\Integration\Enums\IntegrationCapability;
use App\Modules\Integration\Exceptions\CredentialDecryptionException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * A configured external service provider.
 *
 * @property string $id
 * @property IntegrationCapability $capability
 * @property string $driver
 * @property string $label
 * @property string|null $credentials
 * @property array<string, mixed>|null $settings
 * @property bool $is_active
 * @property bool $is_default
 * @property int $priority
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder|IntegrationProvider active()
 * @method static Builder|IntegrationProvider forCapability(IntegrationCapability $capability)
 */
class IntegrationProvider extends BaseModel
{
    protected $table = 'integration_providers';

    protected $fillable = [
        'capability',
        'driver',
        'label',
        'settings',
        'is_active',
        'is_default',
        'priority',
    ];

    /**
     * Credentials are never serialised, in any representation.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'capability' => IntegrationCapability::class,
            'settings' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'priority' => 'integer',
        ]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCapability(Builder $query, IntegrationCapability $capability): Builder
    {
        return $query->where('capability', $capability->value);
    }

    /**
     * Store vendor credentials, encrypted at rest.
     *
     * @param  array<string, mixed>|null  $credentials
     */
    public function setCredentials(?array $credentials): void
    {
        $this->credentials = $credentials === null || $credentials === []
            ? null
            : Crypt::encryptString((string) json_encode($credentials));
    }

    /**
     * Read the decrypted credentials.
     *
     * Fails loudly rather than returning ciphertext, which would otherwise be handed
     * to a vendor as if it were an API key and fail in a far less obvious place.
     *
     * @return array<string, mixed>
     *
     * @throws CredentialDecryptionException
     */
    public function getCredentials(): array
    {
        if ($this->credentials === null) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($this->credentials), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $e) {
            throw new CredentialDecryptionException($this->id, $this->driver, $e);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether any credential material is stored, without revealing it.
     */
    public function hasCredentials(): bool
    {
        return $this->credentials !== null;
    }
}
