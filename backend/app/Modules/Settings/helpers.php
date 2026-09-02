<?php

declare(strict_types=1);

use App\Modules\Settings\Contracts\SettingServiceInterface;

if (! function_exists('setting')) {
    /**
     * Get a setting value by key ('group.key') using the SettingServiceInterface.
     */
    function setting(string $key, mixed $default = null): mixed
    {
        if (app()->bound(SettingServiceInterface::class)) {
            return app(SettingServiceInterface::class)->get($key, $default);
        }

        return $default;
    }
}
