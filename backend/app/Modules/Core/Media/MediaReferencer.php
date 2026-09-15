<?php

declare(strict_types=1);

namespace App\Modules\Core\Media;

/**
 * A module's answer to "what public representation shows this file?" (ADR 0058 §7).
 *
 * When a file stops being servable — deleted, infected, failed — every cached response that
 * shows it must go. Media cannot know which responses those are, and may not import the
 * modules that do; each module that refers to media by id answers for its own references.
 */
interface MediaReferencer
{
    /**
     * The edge tags of every public response that shows this file.
     *
     * @return list<string>
     */
    public function edgeTagsReferencing(string $mediaId): array;
}
