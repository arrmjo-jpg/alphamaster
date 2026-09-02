<?php

declare(strict_types=1);

arch('Core module never depends on application domain modules')
    ->expect('App\Modules\Core')
    ->not->toUse([
        'App\Modules\Auth',
        'App\Modules\User',
        'App\Modules\Settings',
        'App\Modules\Localization',
        'App\Modules\Integration',
        'App\Modules\Notification',
        'App\Modules\Media',
    ]);

arch('Core controllers extend BaseApiController')
    ->expect('App\Modules\Core\Controllers')
    ->classes()
    ->toExtend('App\Modules\Core\Controllers\BaseApiController');

arch('All Core classes use strict types')
    ->expect('App\Modules\Core')
    ->toUseStrictTypes();

arch('Localization controllers extend BaseApiController')
    ->expect('App\Modules\Localization\Controllers')
    ->classes()
    ->toExtend('App\Modules\Core\Controllers\BaseApiController');

arch('All Localization classes use strict types')
    ->expect('App\Modules\Localization')
    ->toUseStrictTypes();

arch('Localization module only depends on Core and Framework')
    ->expect('App\Modules\Localization')
    ->not->toUse([
        'App\Modules\Auth',
        'App\Modules\User',
        'App\Modules\Settings',
        'App\Modules\Integration',
        'App\Modules\Notification',
        'App\Modules\Media',
    ]);

arch('Settings controllers extend BaseApiController')
    ->expect('App\Modules\Settings\Controllers')
    ->classes()
    ->toExtend('App\Modules\Core\Controllers\BaseApiController');

arch('All Settings classes use strict types')
    ->expect('App\Modules\Settings')
    ->toUseStrictTypes();

arch('Settings module only depends on Core and Framework')
    ->expect('App\Modules\Settings')
    ->not->toUse([
        'App\Modules\Auth',
        'App\Modules\User',
        'App\Modules\Integration',
        'App\Modules\Notification',
        'App\Modules\Media',
    ]);
