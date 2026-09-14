<?php

declare(strict_types=1);

namespace App\Modules\Integration\Rules;

use App\Modules\Integration\Data\ServiceAccount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A pasted Google service-account file that FCM can authenticate with.
 *
 * The rule is `ServiceAccount::parse()` itself rather than a second description of the
 * file beside it, so what validation accepts is exactly what the controller stores. The
 * message names the part of the file that is wrong and never quotes it.
 */
final class ServiceAccountJson implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $result = is_string($value) ? ServiceAccount::parse($value) : 'json';

        if (is_string($result)) {
            $fail(__('validation.custom.service_account_json.'.$result));
        }
    }
}
