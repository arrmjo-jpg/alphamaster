<?php

use App\Modules\Auth\Providers\AuthServiceProvider;
use App\Modules\Authorization\Providers\AuthorizationServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\Localization\Providers\LocalizationServiceProvider;
use App\Modules\Settings\Providers\SettingsServiceProvider;
use App\Modules\User\Providers\UserServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    CoreServiceProvider::class,
    LocalizationServiceProvider::class,
    SettingsServiceProvider::class,
    UserServiceProvider::class,
    AuthServiceProvider::class,
    AuthorizationServiceProvider::class,
];
