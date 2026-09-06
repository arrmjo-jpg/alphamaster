<?php

declare(strict_types=1);

namespace App\Modules\Core\Audit;

use RuntimeException;

/**
 * An archive was written and could not be confirmed readable (ADR 0037, as extended).
 *
 * Raised only inside the archivist, and caught there. It exists so that the two ways
 * verification can fail — bytes that differ, and a count that differs — are the same
 * kind of event to the code that decides whether anything may be removed, rather than
 * one being an exception and the other a boolean somebody has to remember to check.
 */
class ArchiveVerificationException extends RuntimeException {}
