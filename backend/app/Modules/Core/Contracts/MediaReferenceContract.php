<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Media\MediaReference;

/**
 * A stored media file, as a module that may not depend on Media refers to it (ADR 0055 §10).
 *
 * Declared here because content modules keep a media id and need its URL, and the
 * architecture forbids them from importing Media. Media implements it, the way it implements
 * `ProfileAvatarContract`.
 */
interface MediaReferenceContract
{
    /**
     * A public image that is ready to serve, or null when the id names nothing, something
     * that is not an image, something private, or something not ready.
     *
     * Only public images qualify: content referencing them is served to anonymous visitors
     * and cached at the edge, where a signed private URL would leak or expire.
     */
    public function publicImage(string $mediaId): ?MediaReference;
}
