<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Backup\RestoreReport;

/**
 * One module's share of a portable configuration export (ADR 0039).
 *
 * A contract because the export spans stores that belong to different modules —
 * settings, languages, provider metadata — and no module may reach into another's.
 * Core owns the envelope, the key fingerprint, the ordering and the audit record; each
 * module owns the shape of its own section and how to read it back.
 *
 * The alternative was one exporter that knew every table, which would have to live
 * somewhere that may import all of them. That place does not exist, and inventing it
 * would mean a module whose entire purpose is to violate the dependency rules.
 */
interface ConfigurationPortabilityContract
{
    /**
     * The section name this contributes, used as the key in the export document.
     */
    public function section(): string;

    /**
     * Where this section sits in a restore.
     *
     * Lower runs first. The order is a dependency order, not a preference: languages
     * must exist before a per-locale value can be attached to one, and setting
     * definitions must be synchronised from the running registry before values can be
     * validated against them (ADR 0039).
     */
    public function order(): int;

    /**
     * This module's configuration, as plain data.
     *
     * **Never decrypts.** A secret is carried as the ciphertext already stored, or is
     * omitted and named — a file containing plaintext credentials is a worse artefact
     * than no backup at all, because it will be copied to a laptop, attached to a
     * ticket, and kept.
     *
     * @return array{data: array<string, mixed>, omitted_secrets: array<int, string>}
     */
    public function export(bool $includeSecrets): array;

    /**
     * Read a section back.
     *
     * `$mayWriteEncrypted` is false when the export was written under a different
     * encryption key. An implementation must then restore everything it can and decline
     * every encrypted value, reporting which — writing ciphertext that decrypts to
     * nothing surfaces later as an integration that stopped working for reasons nobody
     * can trace (ADR 0039).
     *
     * Validates before it writes: a value that no longer fits its current declaration is
     * reported and skipped, never coerced. A partial restore is a state an operator can
     * see and finish; a coerced one is a corruption nobody notices.
     *
     * @param  array<string, mixed>  $section
     */
    public function restore(array $section, bool $mayWriteEncrypted): RestoreReport;
}
