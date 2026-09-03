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
        'App\Modules\Localization',
        'App\Modules\Integration',
        'App\Modules\Notification',
        'App\Modules\Media',
    ]);

arch('Auth controllers extend BaseApiController')
    ->expect('App\Modules\Auth\Controllers')
    ->classes()
    ->toExtend('App\Modules\Core\Controllers\BaseApiController');

arch('All Auth classes use strict types')
    ->expect('App\Modules\Auth')
    ->toUseStrictTypes();

arch('All User classes use strict types')
    ->expect('App\Modules\User')
    ->toUseStrictTypes();

arch('Auth module only depends on Core, User and Framework')
    ->expect('App\Modules\Auth')
    ->not->toUse([
        'App\Modules\Localization',
        'App\Modules\Integration',
        'App\Modules\Notification',
        'App\Modules\Media',
    ]);

arch('User module does not depend on Auth or any other domain module')
    ->expect('App\Modules\User')
    ->not->toUse([
        'App\Modules\Auth',
        'App\Modules\Settings',
        'App\Modules\Localization',
        'App\Modules\Integration',
        'App\Modules\Notification',
        'App\Modules\Media',
    ]);

arch('Authorization controllers extend BaseApiController')
    ->expect('App\Modules\Authorization\Controllers')
    ->classes()
    ->toExtend('App\Modules\Core\Controllers\BaseApiController');

arch('All Authorization classes use strict types')
    ->expect('App\Modules\Authorization')
    ->toUseStrictTypes();

arch('Authorization module only depends on Core, User and Framework')
    ->expect('App\Modules\Authorization')
    ->not->toUse([
        'App\Modules\Auth',
        'App\Modules\Settings',
        'App\Modules\Localization',
        'App\Modules\Integration',
        'App\Modules\Notification',
        'App\Modules\Media',
    ]);

arch('Core module never depends on Authorization')
    ->expect('App\Modules\Core')
    ->not->toUse(['App\Modules\Authorization']);

arch('Spatie roles and permissions are reached only through the Authorization module')
    ->expect('Spatie\Permission')
    ->toOnlyBeUsedIn([
        'App\Modules\Authorization',
        'App\Modules\User\Models',
    ]);

arch('Integration controllers extend BaseApiController')
    ->expect('App\Modules\Integration\Controllers')
    ->classes()
    ->toExtend('App\Modules\Core\Controllers\BaseApiController');

arch('All Integration classes use strict types')
    ->expect('App\Modules\Integration')
    ->toUseStrictTypes();

arch('Integration module does not depend on the modules that consume it')
    ->expect('App\Modules\Integration')
    ->not->toUse([
        'App\Modules\Auth',
        'App\Modules\Settings',
        'App\Modules\Localization',
        'App\Modules\Notification',
        'App\Modules\Media',
    ]);

arch('Core module never depends on Integration')
    ->expect('App\Modules\Core')
    ->not->toUse(['App\Modules\Integration']);
