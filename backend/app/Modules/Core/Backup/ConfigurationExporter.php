<?php

declare(strict_types=1);

namespace App\Modules\Core\Backup;

use App\Modules\Core\Audit\AuditAction;
use App\Modules\Core\Contracts\AuditRecorderContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * A portable, inspectable snapshot of what this deployment is configured to do
 * (ADR 0039).
 *
 * Not a database backup. Infrastructure takes those, on its own schedule, with its own
 * retention, and does it better than an application can. This is the other thing: a way
 * to move configuration between environments deliberately, seed a new deployment, and
 * answer "what was this set to" without restoring a whole database.
 *
 * What it never contains:
 *
 *   * **decrypted secrets, ever.** A secret travels as the ciphertext already stored or
 *     not at all;
 *   * **audit records** — evidence with its own retention and its own permission
 *     (ADR 0037). Putting them here would make an ordinary configuration transfer a way
 *     to carry the security trail somewhere it does not belong;
 *   * **MFA methods, in any form.** A second factor belongs to a person and to one
 *     deployment; exporting them would let a restore reproduce every administrator's
 *     authenticator, which is a credential-theft primitive wearing a maintenance task's
 *     clothes;
 *   * **users, roles or permission assignments.** Configuration is not identity.
 *
 * Those exclusions are structural rather than filtered: no contributor is registered for
 * any of those stores, so there is no code path that could reach them.
 */
class ConfigurationExporter
{
    /**
     * The shape of the document itself.
     *
     * Bumped when the envelope changes in a way a reader must notice. It travels in the
     * file so a restore can detect that it is reading an export written by a different
     * release, rather than misreading it confidently.
     */
    public const SHAPE_VERSION = 1;

    public function __construct(
        private readonly ConfigurationPortability $portability,
        private readonly EncryptionKeyFingerprint $fingerprint,
        private readonly AuditRecorderContract $audit,
    ) {}

    /**
     * Write an export and report where it went.
     *
     * `$includeSecrets` is the operator's call, because the right answer depends on
     * where the export is going. Into the same deployment, ciphertext is portable and
     * the restore is complete. Into a different environment it is undecryptable, so
     * carrying it achieves nothing and increases what a leaked file is worth — and the
     * export instead lists which secrets were omitted, by key, so an operator has a
     * checklist rather than a discovery.
     *
     * Written to a configured disk, not returned as a download, for the reason ADR 0037
     * gives about the audit archive: an endpoint that streams the platform's
     * configuration to whoever called it is an exfiltration path with an access-log
     * entry that looks like maintenance.
     */
    public function export(bool $includeSecrets): ExportOutcome
    {
        $sections = [];
        $omitted = [];

        foreach ($this->portability->ordered() as $contributor) {
            $result = $contributor->export($includeSecrets);

            $sections[$contributor->section()] = $result['data'];
            $omitted = array_merge($omitted, $result['omitted_secrets']);
        }

        $document = [
            'shape_version' => self::SHAPE_VERSION,
            'exported_at' => Carbon::now()->toIso8601String(),
            'exported_by' => is_string(Auth::id()) ? Auth::id() : null,
            'platform' => ['laravel' => app()->version()],
            // So a restore can tell whether the ciphertext in this file is readable
            // where it is being read, before it writes any of it.
            'key_fingerprint' => $this->fingerprint->current(),
            'includes_secrets' => $includeSecrets,
            // Named, so an operator re-supplying them has a list rather than a search.
            'omitted_secrets' => $omitted,
            'sections' => $sections,
        ];

        $payload = (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $location = $this->location();

        Storage::disk($this->disk())->put($location, $payload);

        $outcome = new ExportOutcome($location, array_keys($sections), $includeSecrets, $omitted);

        $this->audit->succeeded(AuditAction::CONFIGURATION_EXPORTED, $this->disk(), $outcome->toArray());

        return $outcome;
    }

    /**
     * Named by the moment it was taken plus a random suffix, so two exports in the same
     * second cannot silently overwrite one another.
     */
    private function location(): string
    {
        return rtrim((string) config('backup.configuration.path'), '/')
            .'/configuration-'.Carbon::now()->format('Ymd-His')
            .'-'.bin2hex(random_bytes(4)).'.json';
    }

    private function disk(): string
    {
        return (string) config('backup.configuration.disk');
    }
}
