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

arch('Auth module depends only on Core, User, Integration and Framework')
    // Integration is permitted deliberately: ADR 0013 routes OTP delivery through it,
    // and the SMS method dispatches there rather than owning a transport of its own.
    // The dependency runs one way only, which the Integration rule below enforces.
    ->expect('App\Modules\Auth')
    ->not->toUse([
        'App\Modules\Localization',
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

arch('Notification controllers extend BaseApiController')
    ->expect('App\Modules\Notification\Controllers')
    ->classes()
    ->toExtend('App\Modules\Core\Controllers\BaseApiController');

arch('All Notification classes use strict types')
    ->expect('App\Modules\Notification')
    ->toUseStrictTypes();

arch('Notification module does not depend on Auth, User internals or Media')
    // Integration is permitted: the SMS channel dispatches through it (ADR 0017).
    // Auth is not: the confirmed number reaches Notification through the Core
    // contract, so neither module needs to know the other exists.
    ->expect('App\Modules\Notification')
    ->not->toUse([
        'App\Modules\Auth',
        'App\Modules\Localization',
        'App\Modules\Media',
    ]);

arch('Core module never depends on Notification')
    ->expect('App\Modules\Core')
    ->not->toUse(['App\Modules\Notification']);

arch('Media controllers extend BaseApiController')
    ->expect('App\Modules\Media\Controllers')
    ->classes()
    ->toExtend('App\Modules\Core\Controllers\BaseApiController');

arch('All Media classes use strict types')
    ->expect('App\Modules\Media')
    ->toUseStrictTypes();

arch('Media module does not depend on the modules that consume it')
    ->expect('App\Modules\Media')
    ->not->toUse([
        'App\Modules\Auth',
        'App\Modules\Localization',
        'App\Modules\Notification',
        'App\Modules\Integration',
    ]);

arch('Core module never depends on Media')
    ->expect('App\Modules\Core')
    ->not->toUse(['App\Modules\Media']);

arch('Filesystem internals are reachable only from the Media storage layer')
    // League\Flysystem is genuinely imported by DiskMediaStorage, so this rule guards
    // a real edge rather than an empty set: no other module may reach past the
    // storage contract to the filesystem implementation beneath it.
    ->expect('League\Flysystem')
    ->toOnlyBeUsedIn('App\Modules\Media\Services\Storage');
