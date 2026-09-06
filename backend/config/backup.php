<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where a configuration export is written
    |--------------------------------------------------------------------------
    |
    | Bootstrap configuration, not a setting. A storage destination belongs in the
    | same class as the database DSN: it says where the platform keeps things, and
    | an export destination that an operator could change from inside the
    | application would let configuration be sent somewhere by changing the
    | configuration.
    |
    | A file, not a download (ADR 0039, following ADR 0037's reasoning about the
    | audit archive). An export can carry ciphertext; streaming it to whoever called
    | the endpoint is an exfiltration path with an access-log entry that looks like
    | maintenance. Writing to a disk the deployment controls keeps the artefact
    | where its own access controls apply.
    |
    */

    'configuration' => [
        'disk' => env('CONFIGURATION_BACKUP_DISK', 'local'),

        'path' => env('CONFIGURATION_BACKUP_PATH', 'configuration-exports'),
    ],

];
