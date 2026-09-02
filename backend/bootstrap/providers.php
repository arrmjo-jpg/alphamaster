<?php

use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\Localization\Providers\LocalizationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    CoreServiceProvider::class,
    LocalizationServiceProvider::class,
];
