<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Secret Scan
|--------------------------------------------------------------------------
|
| Searches the repository for credentials that must never be committed.
|
| The scan proves itself before it reports. Three controls run first: every pattern
| must match a string built to match it, the file walk must have reached known
| repository files, and a credential planted moments earlier must be found end to
| end. If any control fails the scan exits UNPROVEN rather than clean, because the
| failure this guards against is not a missed secret — it is a scan that has
| quietly stopped looking and reports zero findings with total confidence.
|
| That is not hypothetical. A scan at the Phase 9 gate returned zero because its
| file list resolved to a path that did not exist; the control that would have
| caught it was added afterwards, and the same class of mistake has since been
| found in an architecture rule guarding a namespace nothing imported.
|
| Exit codes:
|   0  clean, controls proven
|   1  findings, review each
|   2  unproven — the scan could not demonstrate it works; treat as a failure
|
*/

const EXIT_CLEAN = 0;
const EXIT_FINDINGS = 1;
const EXIT_UNPROVEN = 2;

/**
 * Patterns and, for each, a string that must match it.
 *
 * Every entry carries its own control so a pattern cannot be broken silently by
 * an edit — a regex that no longer matches its own sample fails the build.
 *
 * @var array<string, array{pattern: string, control: string}>
 */
$checks = [
    'aws_access_key' => [
        'pattern' => '/\bAKIA[0-9A-Z]{16}\b/',
        'control' => 'AKIA'.'IOSFODNN7EXAMPLE',
    ],
    'private_key_block' => [
        'pattern' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/',
        'control' => '-----BEGIN RSA PRIVATE KEY-----',
    ],
    'laravel_app_key' => [
        'pattern' => '/\bbase64:[A-Za-z0-9+\/]{40,}={0,2}/',
        'control' => 'base64:'.str_repeat('Ab3d', 12).'=',
    ],
    'bearer_token' => [
        'pattern' => '/\b[Bb]earer\s+[A-Za-z0-9_\-.=]{25,}/',
        'control' => 'Bearer '.str_repeat('x9Kd', 8),
    ],
    'slack_token' => [
        'pattern' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
        'control' => 'xoxb-'.str_repeat('12ab', 5),
    ],
    'github_token' => [
        'pattern' => '/\bgh[pousr]_[A-Za-z0-9]{30,}/',
        'control' => 'ghp_'.str_repeat('aB3d', 10),
    ],
    'stripe_key' => [
        'pattern' => '/\b[sr]k_(?:live|test)_[A-Za-z0-9]{20,}/',
        'control' => 'sk_live_'.str_repeat('aB3d', 8),
    ],
    'twilio_sid' => [
        'pattern' => '/\bAC[0-9a-fA-F]{32}\b/',
        'control' => 'AC'.str_repeat('0a1b', 8),
    ],
    'google_api_key' => [
        'pattern' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
        'control' => 'AIza'.str_repeat('aB3d3', 7),
    ],
    'jwt' => [
        'pattern' => '/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}/',
        'control' => 'eyJhbGciOiJIUzI1NiJ9.'.str_repeat('aB3d', 4).'.'.str_repeat('aB3d', 4),
    ],
    'assigned_secret' => [
        'pattern' => '/(?i)\b(?:password|passwd|secret|api_?key|access_?token|client_?secret)\s*[:=]\s*[\'"][^\'"\s]{8,}[\'"]/',
        'control' => 'password = "hunter2hunter2"',
    ],
];

/**
 * Paths whose contents are not ours to police, plus build output.
 *
 * @var list<string>
 */
$excludedFragments = [
    '/vendor/',
    '/node_modules/',
    '/.git/',
    '/storage/logs/',
    '/storage/framework/',
    '/public/build/',
];

/**
 * Findings that are known and accepted, keyed by path, with the reason.
 *
 * An entry here is a decision, not a silencer: the path must still be ignored by
 * git, which is asserted below. A committed file can never be allow-listed.
 *
 * @var array<string, string>
 */
$accepted = [
    'backend/.env' => 'local development environment file; gitignored and never committed',
];

$root = getcwd();

if (! is_string($root) || ! is_dir($root.'/.git')) {
    fwrite(STDERR, "Run this from the repository root.\n");
    exit(EXIT_UNPROVEN);
}

// ── Control 1: every pattern matches its own sample ──────────────────────────
$brokenPatterns = [];

foreach ($checks as $name => $check) {
    if (@preg_match($check['pattern'], $check['control']) !== 1) {
        $brokenPatterns[] = $name;
    }
}

// ── Build the file list ──────────────────────────────────────────────────────
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $entry) {
    /** @var SplFileInfo $entry */
    if (! $entry->isFile()) {
        continue;
    }

    $path = str_replace('\\', '/', $entry->getPathname());

    foreach ($excludedFragments as $fragment) {
        if (str_contains($path, $fragment)) {
            continue 2;
        }
    }

    // A credential does not need two megabytes to hide in, and reading large
    // binaries would dominate the run.
    if ($entry->getSize() > 2_000_000) {
        continue;
    }

    $files[] = $path;
}

// ── Control 2: the walk reached real repository files ────────────────────────
// If the file list is empty, or rooted somewhere unexpected, a clean result is
// meaningless. These files exist in every checkout, so their absence means the
// walk — not the repository — is what is empty.
$mustHaveWalked = [
    'composer.json' => $root.'/backend/composer.json',
    'docker-compose.yml' => $root.'/docker-compose.yml',
    'this script' => $root.'/scripts/security/secrets-scan.php',
];

$walkMisses = [];
$normalisedFiles = array_flip($files);

foreach ($mustHaveWalked as $label => $expected) {
    if (! isset($normalisedFiles[str_replace('\\', '/', $expected)])) {
        $walkMisses[] = $label;
    }
}

// ── Control 3: a planted credential is detected end to end ───────────────────
// Control 1 proves the patterns in isolation; this proves the whole pipeline —
// enumerate, read, match — on a file that was not there a moment ago. It is
// planted in a temporary directory rather than in the repository so the scan
// never needs write access to the tree it is inspecting.
$controlRoot = sys_get_temp_dir().'/secrets-scan-control-'.bin2hex(random_bytes(6));
$plantedPath = $controlRoot.'/planted.txt';
$plantedFound = false;

@mkdir($controlRoot, 0700, true);
$planted = @file_put_contents($plantedPath, 'AKIA'.'IOSFODNN7EXAMPLE');

if ($planted === false) {
    fwrite(STDERR, "Could not write the control file to {$controlRoot}.\n");
    echo "RESULT: UNPROVEN\n";
    exit(EXIT_UNPROVEN);
}

// Appended to the same list the repository files are in, so it travels through
// exactly the same loop rather than a special case beside it.
$files[] = str_replace('\\', '/', $plantedPath);

/*
 * This file necessarily contains a sample for every pattern, so it matches every
 * pattern. Those exact samples are ignored when they appear here and nowhere else,
 * which keeps the scan from reporting itself without granting the file a blanket
 * exemption: any string in here that is not one of the declared controls is
 * reported like any other finding.
 */
$ownPath = str_replace('\\', '/', __FILE__);
$controlStrings = array_map(static fn (array $check): string => $check['control'], $checks);

$findings = [];

foreach ($files as $path) {
    $contents = @file_get_contents($path);

    if (! is_string($contents) || $contents === '' || str_contains($contents, "\0")) {
        continue;
    }

    foreach ($checks as $name => $check) {
        if (preg_match_all($check['pattern'], $contents, $matches) < 1) {
            continue;
        }

        if ($path === str_replace('\\', '/', $plantedPath)) {
            $plantedFound = true;

            continue;
        }

        $relative = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');

        foreach ($matches[0] as $match) {
            if ($path === $ownPath && in_array($match, $controlStrings, true)) {
                continue;
            }

            $findings[] = [
                'check' => $name,
                'file' => $relative,
                'sample' => substr($match, 0, 60),
            ];
        }
    }
}

@unlink($plantedPath);

// ── Report the controls before the result ────────────────────────────────────
echo 'files scanned: '.count($files)."\n\n";
echo "controls\n";
echo '  patterns matching their own sample: '.(
    $brokenPatterns === []
        ? count($checks).'/'.count($checks)
        : 'FAILED for '.implode(', ', $brokenPatterns)
)."\n";
echo '  repository files reached by the walk:  '.(
    $walkMisses === []
        ? count($mustHaveWalked).'/'.count($mustHaveWalked)
        : 'MISSING '.implode(', ', $walkMisses)
)."\n";
echo '  planted credential detected end to end: '.($plantedFound ? 'DETECTED' : 'NOT DETECTED')."\n\n";

if ($brokenPatterns !== [] || $walkMisses !== [] || ! $plantedFound) {
    echo "RESULT: UNPROVEN\n";
    echo "The scan cannot demonstrate that it detects anything, so a clean result would\n";
    echo "mean nothing. This is a failure, not a pass.\n";
    exit(EXIT_UNPROVEN);
}

// ── Separate accepted findings from real ones ────────────────────────────────
$real = [];
$acknowledged = [];
$unverifiable = [];

foreach ($findings as $finding) {
    if (! isset($accepted[$finding['file']])) {
        $real[] = $finding;

        continue;
    }

    // An acceptance is only valid while git genuinely ignores the file. The moment
    // it is committed the acceptance stops applying and the finding is real.
    //
    // git answers 0 for ignored and 1 for not ignored. Anything else means it did
    // not answer at all — it refuses to operate on a tree owned by another user,
    // which is what happens inside a container — and an unanswered question is not
    // a yes or a no. Reporting it as a committed secret would be a false positive
    // that trains people to ignore this scan; reporting it as clean would be worse.
    // It is a broken check, so it fails as one.
    $ignoreOutput = [];
    exec('git check-ignore -q '.escapeshellarg($finding['file']).' 2>/dev/null', $ignoreOutput, $ignored);

    if ($ignored === 0) {
        $acknowledged[$finding['file']] = $accepted[$finding['file']];

        continue;
    }

    if ($ignored !== 1) {
        $unverifiable[] = $finding['file'];

        continue;
    }

    $finding['check'] .= ' (accepted path is no longer gitignored)';
    $real[] = $finding;
}

if ($unverifiable !== []) {
    echo "unverifiable
";
    foreach ($unverifiable as $file) {
        echo '  '.$file." — git could not say whether this path is ignored
";
    }
    echo "
RESULT: UNPROVEN
";
    echo "An acceptance could not be checked, so the scan cannot stand behind its own
";
    echo "result. This is a failure, not a pass.
";
    exit(EXIT_UNPROVEN);
}

if ($acknowledged !== []) {
    echo "accepted\n";
    foreach ($acknowledged as $file => $reason) {
        echo '  '.$file.' — '.$reason."\n";
    }
    echo "\n";
}

if ($real === []) {
    echo "RESULT: CLEAN (controls proven)\n";
    exit(EXIT_CLEAN);
}

echo "findings\n";
foreach ($real as $finding) {
    echo '  ['.$finding['check'].'] '.$finding['file'].' :: '.$finding['sample']."\n";
}
echo "\nRESULT: ".count($real)." finding(s)\n";
exit(EXIT_FINDINGS);
