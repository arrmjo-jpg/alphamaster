<?php

declare(strict_types=1);

namespace App\Modules\User\Controllers\Api;

use App\Modules\Core\Contracts\ProfileAvatarContract;
use App\Modules\Core\Controllers\BaseApiController;
use App\Modules\User\Models\User;
use App\Modules\User\Models\UserProfileLink;
use App\Modules\User\Requests\UpdateProfileLinksRequest;
use App\Modules\User\Requests\UpdateProfilePasswordRequest;
use App\Modules\User\Requests\UpdateProfileRequest;
use App\Modules\User\Resources\ProfileResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in account's own profile (ADR 0051 §4).
 *
 * Every route acts on `$request->user()` and takes no account identifier, so there is no
 * way to reach somebody else's profile however it is called.
 */
class ProfileController extends BaseApiController
{
    private const LOCATION_FIELDS = ['country_code', 'region', 'city', 'latitude', 'longitude'];

    public function __construct(private readonly ProfileAvatarContract $avatars) {}

    /**
     * Your profile.
     */
    #[Response(200, type: 'array{success: bool, data: ProfileResource}')]
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse($this->present($user));
    }

    /**
     * Change your profile.
     *
     * Only the fields sent are changed. A new email address clears its verification, and a new phone number clears its. An administrator's address is not changed here.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: ProfileResource}')]
    #[Response(403, description: 'PROFILE_EMAIL_MANAGED: an administrator\'s address is changed through account management.')]
    #[Response(409, description: 'LAST_SIGN_IN_METHOD: removing the number would leave the account no way to sign in.')]
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        if (array_key_exists('email', $data)) {
            $email = (string) $data['email'];

            if ($email !== $user->email) {
                // An administrator is stopped at sign-in until the address is verified
                // (ADR 0012), so changing it from the account itself would be a way to
                // lock an administrative account out.
                if ($user->isAdmin()) {
                    return $this->errorResponse('PROFILE_EMAIL_MANAGED', 'api.error.user.profile_email_managed', null, 403);
                }

                $user->email = $email;
                $user->email_verified_at = null;
            }
        }

        if (array_key_exists('phone', $data) && ($data['phone'] === null || $data['phone'] === '')
            && $this->wouldHaveNoSignInMethod($user)) {
            return $this->errorResponse('LAST_SIGN_IN_METHOD', 'api.error.user.profile_last_sign_in_method', null, 409);
        }

        if (array_intersect_key($data, array_flip(self::LOCATION_FIELDS)) !== []) {
            $user->location_updated_at = now();
        }

        $user->fill(Arr::only($data, ['name', 'phone', 'preferred_locale', 'bio', ...self::LOCATION_FIELDS]));
        $user->save();

        return $this->successResponse($this->present($user->refresh()), 'Your profile was updated.');
    }

    /**
     * Set or change your password.
     *
     * The current password is required when the account has one. Every other session is signed out; this one is kept.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: null}')]
    #[Response(422, description: 'VALIDATION_ERROR, including a wrong current password.')]
    public function password(UpdateProfilePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->password !== null
            && ! Hash::check((string) $request->validated('current_password', ''), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [(string) __('validation.current_password')],
            ]);
        }

        DB::transaction(function () use ($user, $request): void {
            // Hashed by the model's cast; the plaintext never reaches the column.
            $user->password = (string) $request->validated('password');
            $user->save();

            $tokens = $user->tokens();

            // Sanctum documents a personal access token here, and a transient cookie token
            // is also possible; only a stored token has an id to keep.
            /** @var mixed $current */
            $current = $user->currentAccessToken();

            if ($current instanceof PersonalAccessToken) {
                $tokens->whereKeyNot($current->getKey());
            }

            $tokens->delete();
        });

        return $this->successResponse(null, 'Your password was changed. Your other sessions were signed out.');
    }

    /**
     * Replace your profile links.
     *
     * The whole list, in order; at most ten. Links are shown on your profile and prove nothing: they are not linked sign-in identities.
     */
    #[Response(200, type: 'array{success: bool, message: string, data: ProfileResource}')]
    public function links(UpdateProfileLinksRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var list<array{platform: string, url: string}> $links */
        $links = array_values((array) $request->validated('links', []));

        DB::transaction(function () use ($user, $links): void {
            UserProfileLink::query()->where('user_id', $user->id)->delete();

            foreach ($links as $position => $link) {
                UserProfileLink::query()->create([
                    'user_id' => $user->id,
                    'platform' => $link['platform'],
                    'url' => $link['url'],
                    'position' => $position,
                ]);
            }
        });

        return $this->successResponse($this->present($user->refresh()), 'Your profile links were updated.');
    }

    private function present(User $user): ProfileResource
    {
        $user->load('profileLinks');

        return new ProfileResource($user, $this->avatars->urlFor($user));
    }

    /**
     * Whether removing the number would leave nothing to sign in with.
     */
    private function wouldHaveNoSignInMethod(User $user): bool
    {
        return $user->email === null
            && $user->password === null
            && ! $user->socialIdentities()->whereNull('unlinked_at')->exists();
    }
}
