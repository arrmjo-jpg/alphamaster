<?php

declare(strict_types=1);

/*
 * Media processing that runs a program (ADR 0024, ADR 0054).
 *
 * Operational bounds, not product settings. An operator decides how long a video may be for
 * analysis in the `media_analysis` settings group; these decide how much a worker may spend
 * reading a file's metadata at all, and belong to whoever deploys the worker.
 */
return [

    'probe' => [
        // A name looked up on PATH, or an absolute path. The image installs ffprobe; an
        // environment without it records the duration as unavailable rather than failing
        // the upload.
        'binary' => env('MEDIA_FFPROBE_BINARY', 'ffprobe'),

        // Reading a container's header takes well under a second. A probe still running
        // after this is a malformed or hostile file, and is stopped.
        'timeout_seconds' => (int) env('MEDIA_PROBE_TIMEOUT_SECONDS', 30),

        // Files larger than this are not copied out of storage to be probed. The upload
        // limit is 100 MB, so by default nothing that was accepted is skipped.
        'max_bytes' => (int) env('MEDIA_PROBE_MAX_BYTES', 104857600),

        // How much of a file ffprobe may read, and for how long in media time, before it
        // must decide what the file is. These bound its memory and CPU on a crafted file;
        // a container's duration is in its header and needs neither in full.
        'probe_size_bytes' => (int) env('MEDIA_PROBE_SIZE_BYTES', 10000000),
        'analyze_duration_microseconds' => (int) env('MEDIA_PROBE_ANALYZE_DURATION_MICROSECONDS', 10000000),

        // Run below the worker's own priority, so a probe competes with nothing that
        // serves a request. Null runs it at normal priority.
        'niceness' => env('MEDIA_PROBE_NICENESS', 10),
    ],

];
