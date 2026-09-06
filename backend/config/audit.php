<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where an archived trail is written
    |--------------------------------------------------------------------------
    |
    | Bootstrap configuration, not a setting (ADR 0018's boundary). A storage
    | destination is in the same class as the database DSN: it says where the
    | platform keeps things, and an operator who could move the audit archive from
    | inside the application would be able to move evidence by changing a row that
    | the evidence itself records them changing.
    |
    | ADR 0037 requires a configured disk rather than a download. An endpoint that
    | streamed the security trail to whoever called it is an exfiltration path with
    | an access-log entry that looks like maintenance; writing to a disk the
    | deployment controls keeps the artefact where its own access controls apply.
    |
    */

    'archive' => [
        'disk' => env('AUDIT_ARCHIVE_DISK', 'local'),

        'path' => env('AUDIT_ARCHIVE_PATH', 'audit-archives'),
    ],

];
