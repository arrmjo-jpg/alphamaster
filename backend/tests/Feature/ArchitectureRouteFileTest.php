<?php

declare(strict_types=1);

use App\Modules\Authorization\Enums\AdminPermission;

/**
 * The boundaries, checked in the files the class rules cannot see.
 *
 * Pest's architecture tests analyse classes. A module's `Routes/api.php` is a plain
 * script, so its imports are invisible to `toUse` — and ADR 0029 item 1 records the
 * consequence: Phase 9 found `Notification/Routes/api.php` importing an Auth enum, a
 * dependency the rules forbid, and the rules passed. It was found by reading.
 *
 * This closes that item. The table below is the same one ArchitectureTest states, and
 * it is duplicated rather than derived because Pest exposes no way to read an `arch()`
 * rule back as data. The last test in this file guards the duplication: both files
 * must know about the same set of modules, so a module added to one and forgotten in
 * the other fails here rather than silently going unchecked.
 */

/**
 * Which modules each module may not reach, verbatim from ArchitectureTest.
 *
 * @return array<string, array<int, string>>
 */
function forbiddenDependencies(): array
{
    return [
        // Core is the shared floor: it may not depend on anything built on it.
        'Core' => ['Auth', 'User', 'Settings', 'Localization', 'Integration', 'Notification', 'Media', 'Authorization'],
        'Localization' => ['Auth', 'User', 'Settings', 'Integration', 'Notification', 'Media', 'Authorization'],
        'Settings' => ['Auth', 'User', 'Localization', 'Integration', 'Notification', 'Media', 'Authorization'],
        // Integration is permitted deliberately: ADR 0013 routes OTP delivery through it.
        'Auth' => ['Localization', 'Notification', 'Media'],
        'User' => ['Auth', 'Settings', 'Localization', 'Integration', 'Notification', 'Media'],
        'Authorization' => ['Auth', 'Settings', 'Localization', 'Integration', 'Notification', 'Media'],
        'Integration' => ['Auth', 'Settings', 'Localization', 'Notification', 'Media'],
        'Notification' => ['Auth', 'Localization', 'Media'],
        'Media' => ['Auth', 'Localization', 'Notification', 'Integration'],
    ];
}

/**
 * Every module route file, keyed by the module that owns it.
 *
 * @return array<string, string>
 */
function routeFiles(): array
{
    $files = glob(app_path('Modules/*/Routes/*.php'));
    $found = [];

    foreach ($files === false ? [] : $files as $file) {
        $module = basename(dirname($file, 2));
        $found[$module.'/'.basename($file)] = $file;
    }

    return $found;
}

/**
 * The modules a route file imports, other than its own.
 *
 * @return array<int, string>
 */
function importedModules(string $file, string $owner): array
{
    $source = (string) file_get_contents($file);

    preg_match_all('/^use\s+App\\\\Modules\\\\([A-Za-z]+)\\\\/m', $source, $matches);

    return array_values(array_unique(array_filter(
        $matches[1],
        static fn (string $module): bool => $module !== $owner
    )));
}

test('a route file reaches no module its own classes may not reach', function (): void {
    $forbidden = forbiddenDependencies();
    $violations = [];

    foreach (routeFiles() as $label => $file) {
        $owner = explode('/', $label)[0];

        foreach (importedModules($file, $owner) as $imported) {
            if (in_array($imported, $forbidden[$owner] ?? [], true)) {
                $violations[] = $label.' imports '.$imported;
            }
        }
    }

    expect($violations)->toBe([]);
});

test('every module has a route file this check can see', function (): void {
    // A module whose routes moved somewhere this glob does not reach would pass the
    // test above by being absent from it, which is the failure mode a check like this
    // has to guard against explicitly.
    $modules = array_unique(array_map(
        static fn (string $label): string => explode('/', $label)[0],
        array_keys(routeFiles())
    ));

    sort($modules);

    expect($modules)->toBe([
        'Auth', 'Authorization', 'Core', 'Integration', 'Localization', 'Media', 'Notification', 'Settings',
    ]);
});

test('a permission named as a literal in a route file is a permission that exists', function (): void {
    // Core writes its permission strings literally because it may not import the
    // Authorization enum. A literal can drift from the catalogue in a way an import
    // cannot, so the drift is checked here rather than left to be noticed when an
    // endpoint silently stops being reachable.
    $known = AdminPermission::values();
    $unknown = [];

    foreach (routeFiles() as $label => $file) {
        $source = (string) file_get_contents($file);

        preg_match_all("/'permission:([a-z_.]+)'/", $source, $matches);

        foreach ($matches[1] as $permission) {
            if (! in_array($permission, $known, true)) {
                $unknown[] = $label.' names '.$permission;
            }
        }
    }

    expect($unknown)->toBe([]);
});

test('the two architecture files know about the same modules', function (): void {
    // The table in this file is a copy of the one in ArchitectureTest, because Pest
    // gives no way to read an `arch()` rule back as data. A module added to one and
    // forgotten in the other would go unchecked in a way nothing else would report.
    $source = (string) file_get_contents(base_path('tests/Feature/ArchitectureTest.php'));

    preg_match_all('/App\\\\Modules\\\\([A-Za-z]+)/', $source, $matches);

    $inClassRules = array_values(array_unique($matches[1]));
    sort($inClassRules);

    $here = array_keys(forbiddenDependencies());
    $here[] = 'Core';
    $here = array_values(array_unique($here));
    sort($here);

    expect($inClassRules)->toBe($here);
});
