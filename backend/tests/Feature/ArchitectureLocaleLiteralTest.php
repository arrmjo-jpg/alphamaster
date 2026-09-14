<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * No content language is written into the platform's code (ADR 0048, ADR 0056).
 *
 * The languages are data: added in Language Management, served when activated, and picked up by
 * every translatable source without a deployment. A list of codes in a class — or a branch on
 * one — is a language that has to be added twice, once where the operator adds it and once where
 * a developer remembers to.
 *
 * Seeders are excluded, and only seeders: they install the first languages and the wording that
 * ships with them, which is data that happens to be written in PHP and runs once. A framework
 * fallback such as `config('app.locale', 'en')` is not a list and not a comparison, and is not
 * matched.
 */
const LOCALE_CODES = 'en|ar|fr|de|es|it|pt|tr|ru|zh|ja|ko|nl|fa|ur|he|hi|ms|sv|pl|uk';

/**
 * @return array<string, string>
 */
function localeLiteralPatterns(): array
{
    $code = "'(?:".LOCALE_CODES.")(?:[-_][A-Za-z]{2})?'";

    return [
        'a list of locale codes' => '/\[\s*'.$code.'\s*(?:,\s*'.$code.'\s*)+,?\s*\]/',
        'a comparison with a locale code' => '/(?:===|!==|==|!=)\s*'.$code.'|'.$code.'\s*(?:===|!==|==|!=)/',
        'a locale code as an array key' => '/'.$code.'\s*=>/',
        'a locale code as a match or switch arm' => '/(?:case\s+'.$code.'\s*:|'.$code.'\s*(?:,\s*'.$code.'\s*)*=>)/',
    ];
}

function codeWithoutComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * @return list<string>
 */
function localeLiteralsIn(string $code): array
{
    $found = [];

    foreach (localeLiteralPatterns() as $label => $pattern) {
        if (preg_match_all($pattern, codeWithoutComments($code), $matches) > 0) {
            foreach ($matches[0] as $match) {
                $found[] = $label.': '.$match;
            }
        }
    }

    return $found;
}

test('the check finds a locale written into code', function (string $planted): void {
    // The control, before the result is trusted: a check that has stopped matching would
    // otherwise report a clean application for ever.
    expect(localeLiteralsIn("<?php\n".$planted))->not->toBe([]);
})->with([
    'a list' => ["\$supported = ['en', 'ar'];"],
    'a comparison' => ["if (\$locale === 'ar') { return 'rtl'; }"],
    'a keyed map' => ["\$labels = ['fr' => 'Français'];"],
    'a match arm' => ["return match (\$locale) { 'ar' => 'rtl', default => 'ltr' };"],
]);

test('the check ignores comments and framework fallbacks', function (): void {
    expect(localeLiteralsIn("<?php\n// Arabic ('ar') reads right to left.\n\$locale = config('app.locale', 'en');"))->toBe([]);
});

test('no application code names a content language', function (): void {
    $offending = [];

    $files = Finder::create()
        ->files()
        ->in(app_path())
        ->name('*.php')
        ->notPath('#(^|/)Database/Seeders/#');

    foreach ($files as $file) {
        foreach (localeLiteralsIn($file->getContents()) as $finding) {
            $offending[] = str_replace('\\', '/', $file->getRelativePathname()).' — '.$finding;
        }
    }

    expect($offending)->toBe([]);
});
