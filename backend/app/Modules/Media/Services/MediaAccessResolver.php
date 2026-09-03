<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Media\Contracts\MediaAccessPolicyContract;
use App\Modules\Media\Models\MediaFile;

/**
 * Decides whether a viewer may reach a file, by asking the owning module.
 *
 * Media answers only the part it can know: public files are readable, unservable
 * files are not. Everything conditional is delegated to the policy the attaching
 * module registered for its own type.
 *
 * Fails closed. Private media with no registered policy is denied, because a missing
 * policy means nobody has said who should see the file — which is not the same as
 * everybody.
 */
class MediaAccessResolver
{
    /**
     * @var array<string, MediaAccessPolicyContract>
     */
    private array $policies = [];

    /**
     * @param  array<int, MediaAccessPolicyContract>  $policies
     */
    public function __construct(array $policies = [])
    {
        foreach ($policies as $policy) {
            $this->register($policy);
        }
    }

    public function register(MediaAccessPolicyContract $policy): void
    {
        $this->policies[$policy->appliesTo()] = $policy;
    }

    public function allows(MediaFile $media, ?object $viewer): bool
    {
        // An infected or unprocessed file is served to nobody, whatever any policy
        // says: a policy answers who may see it, not whether it is safe to serve.
        if (! $media->isServable()) {
            return false;
        }

        if ($media->isPubliclyReadable()) {
            return true;
        }

        if ($media->attachable_type !== null) {
            $policy = $this->policies[$media->attachable_type] ?? null;

            // Attached to a type whose module registered no policy: nobody has said
            // who may see this, which is not the same as everybody. Falling back to
            // the uploader here would quietly grant access the owning module never
            // agreed to, so an unanswered type is denied outright.
            return $policy !== null && $policy->allows($media, $viewer);
        }

        // Unattached private media falls back to its uploader, the only relationship
        // Media itself can vouch for without asking another module.
        return $viewer !== null
            && $media->uploaded_by !== null
            && isset($viewer->id)
            && $viewer->id === $media->uploaded_by;
    }
}
