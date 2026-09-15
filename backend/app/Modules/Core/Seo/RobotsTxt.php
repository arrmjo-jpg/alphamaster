<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

use App\Modules\Core\Contracts\SiteSeoDefaultsContract;

/**
 * The public site's robots.txt (ADR 0058 §3).
 *
 * Outside production the answer is always "crawl nothing": a staging copy indexed under the
 * real site's name is the failure this exists to prevent, and no setting can turn it off. In
 * production, crawling is allowed, the administrative API is excluded, the operator's extra
 * rules follow, and the sitemap is named at the public origin.
 *
 * Extra rules are an operator's text, so only lines that are robots directives are written:
 * anything else — an HTML fragment, a stray header — is dropped rather than published.
 */
final class RobotsTxt
{
    /** The directives an extra line may use. */
    public const DIRECTIVES = ['user-agent', 'allow', 'disallow', 'crawl-delay', 'sitemap'];

    public function __construct(private readonly SiteSeoDefaultsContract $defaults) {}

    public function render(bool $production): string
    {
        if (! $production) {
            return "User-agent: *\nDisallow: /\n";
        }

        $lines = ['User-agent: *', 'Disallow: /api/v1/admin'];

        foreach (self::extraLines($this->defaults->robotsExtra()) as $line) {
            $lines[] = $line;
        }

        $origin = $this->defaults->publicOrigin();

        if ($origin !== null) {
            $lines[] = 'Sitemap: '.rtrim($origin, '/').'/sitemap.xml';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The directive lines in an operator's text, normalised, and nothing else.
     *
     * @return list<string>
     */
    public static function extraLines(?string $extra): array
    {
        if ($extra === null) {
            return [];
        }

        $lines = [];

        foreach (preg_split('/\R/u', $extra) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^([A-Za-z-]+)\s*:\s*([^\x00-\x1F\x7F<>]{1,500})$/u', $line, $match) !== 1) {
                continue;
            }

            $directive = strtolower($match[1]);

            if (! in_array($directive, self::DIRECTIVES, true)) {
                continue;
            }

            $lines[] = ucfirst($directive === 'user-agent' ? 'User-agent' : $directive).': '.trim($match[2]);
        }

        return $lines;
    }
}
