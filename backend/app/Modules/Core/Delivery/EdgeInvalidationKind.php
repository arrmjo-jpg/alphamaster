<?php

declare(strict_types=1);

namespace App\Modules\Core\Delivery;

use App\Modules\Core\Concerns\HasDisplayLabel;

/**
 * What an edge invalidation names (ADR 0036, ADR 0053).
 *
 * The five scopes every CDN this platform is likely to front offers in some form. A
 * driver that cannot honour one says so, and the request is refused before it is queued
 * rather than accepted and silently dropped.
 */
enum EdgeInvalidationKind: string
{
    use HasDisplayLabel;

    /** Exact addresses, as the edge keyed them. */
    case URLS = 'urls';

    /** Every object the origin labelled with a tag (ADR 0053 §3). */
    case TAGS = 'tags';

    /** Every object whose address starts with a prefix. */
    case PREFIXES = 'prefixes';

    /** Every object served for a host name. */
    case HOSTS = 'hosts';

    /** Everything. An incident tool, never an invalidation strategy (ADR 0036). */
    case EVERYTHING = 'everything';
}
