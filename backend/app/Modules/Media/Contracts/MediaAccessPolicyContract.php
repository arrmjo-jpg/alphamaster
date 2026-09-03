<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts;

use App\Modules\Media\Models\MediaFile;

/**
 * Decides whether a particular viewer may reach a particular private file.
 *
 * This is the seam brief section 24 requires. Media knows whether authorization is
 * needed; who satisfies it is a business question, and one Media cannot anticipate:
 * owner, team member, judge, subscriber. A module attaching media registers a policy
 * for its own type, and Media refuses access when no policy answers yes.
 */
interface MediaAccessPolicyContract
{
    /**
     * The attachable type this policy governs, as stored in attachable_type.
     *
     * @return class-string
     */
    public function appliesTo(): string;

    /**
     * Whether this viewer may access this file.
     */
    public function allows(MediaFile $media, ?object $viewer): bool;
}
