<?php

declare(strict_types=1);

namespace App\Modules\Core\Backup;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Reading a configuration export back over a running deployment (ADR 0039).
 *
 * Validates before it writes, in this order:
 *
 *   1. **the document** — readable, and a shape this release understands;
 *   2. **the key fingerprint** — whether the ciphertext in the file is decryptable here;
 *   3. **each value**, against the declaration as it exists today, inside the section
 *      that owns it.
 *
 * A mismatched fingerprint does not fail the restore. It restores everything that is not
 * encrypted and declines every encrypted value, reporting which — because the operator
 * doing this is usually seeding a new environment, where the non-secret configuration is
 * exactly what they want and the credentials were never going to survive the trip.
 *
 * A value that no longer validates is reported and skipped, never coerced. A partial
 * restore is a state an operator can see and finish; a coerced one is a corruption
 * nobody notices.
 */
class ConfigurationRestorer
{
    public function __construct(
        private readonly ConfigurationPortability $portability,
        private readonly EncryptionKeyFingerprint $fingerprint,
        private readonly AuditRecorderContract $audit,
    ) {}

    /**
     * @throws RestoreRefusedException when the document cannot be read or understood
     */
    public function restore(string $location): RestoreOutcome
    {
        $document = $this->read($location);

        // Sections run in dependency order: languages before the values attached to
        // them, definitions before the values validated against them. Definitions come
        // from the *running* registry rather than from the export, because the running
        // code is what will read them — an export from an older release describes
        // settings this deployment may no longer have.
        $mayWriteEncrypted = $this->fingerprint->matches($document['key_fingerprint'] ?? null);
        $reports = [];

        foreach ($this->portability->ordered() as $contributor) {
            $section = $document['sections'][$contributor->section()] ?? null;

            // A section this export does not carry is skipped rather than treated as an
            // instruction to empty the store. An export written before a module existed
            // must not delete that module's configuration on the way in.
            if (! is_array($section)) {
                continue;
            }

            $reports[] = $contributor->restore($section, $mayWriteEncrypted);
        }

        $outcome = new RestoreOutcome($location, $mayWriteEncrypted, $reports);

        // One record for the whole operation, per ADR 0037: a restore is a single
        // decision, and it carries what was written and what was declined, by key.
        $this->audit->succeeded(AuditAction::CONFIGURATION_RESTORED, $location, $outcome->toArray());

        return $outcome;
    }

    /**
     * The document, or a refusal.
     *
     * A shape version from the future is refused rather than read optimistically: this
     * release cannot know what a later one added, and a restore that quietly ignores
     * fields it does not recognise writes a configuration nobody asked for.
     *
     * @return array<string, mixed>
     *
     * @throws RestoreRefusedException
     */
    private function read(string $location): array
    {
        $disk = Storage::disk((string) config('backup.configuration.disk'));

        if (! $disk->exists($location)) {
            throw new RestoreRefusedException('backup.no_such_export');
        }

        try {
            $document = json_decode((string) $disk->get($location), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RestoreRefusedException('backup.unreadable_export');
        }

        if (! is_array($document) || ! is_array($document['sections'] ?? null)) {
            throw new RestoreRefusedException('backup.unreadable_export');
        }

        $shape = $document['shape_version'] ?? null;

        if (! is_int($shape) || $shape > ConfigurationExporter::SHAPE_VERSION) {
            throw new RestoreRefusedException('backup.unsupported_shape');
        }

        return $document;
    }
}
