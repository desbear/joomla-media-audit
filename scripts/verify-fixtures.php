<?php

/**
 * Runs mediaaudit:scan in the LOCAL development site and compares the report of the fixtures with
 * fixtures/expected.json. Exits with 1 when they differ.
 *
 * Usage: php verify-fixtures.php [--root=/path/to/site] [--phase=1] [--report-only]
 *
 * --phase is the phase of the project that is installed. Images that are found by the extractors of
 * a later phase only are expected to be orphans until then.
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL & ~E_DEPRECATED);

$options = getopt('', ['root::', 'phase::', 'report-only']);
$root    = rtrim($options['root'] ?? \dirname(__DIR__, 3), '/');
$phase   = (int) ($options['phase'] ?? 1);

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/assert-local.php') . ' ' . escapeshellarg($root), $status);

if ($status !== 0) {
    exit($status);
}

$expected = json_decode(file_get_contents(\dirname(__DIR__) . '/fixtures/expected.json'), true);

if (!\is_array($expected)) {
    fwrite(STDERR, "Cannot read fixtures/expected.json. Run seed-fixtures.php first.\n");
    exit(1);
}

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/cli/joomla.php') . ' mediaaudit:scan --format=json'
    . ' --prefix=' . escapeshellarg($expected['prefix']) . (isset($options['report-only']) ? ' --report-only' : '') . ' 2>/dev/null';

exec($command, $lines, $status);

// PHP prints its deprecation notices before the report
$start  = array_search('{', $lines, true);
$report = $start === false ? null : json_decode(implode("\n", \array_slice($lines, $start)), true);

if ($status !== 0 || !\is_array($report)) {
    fwrite(STDERR, "mediaaudit:scan failed with status $status:\n" . implode("\n", \array_slice($lines, -15)) . "\n");
    exit(1);
}

$problems = [];
$checked  = 0;
$actual   = array_column($report['files'], null, 'path');

foreach ($expected['files'] as $path => $file) {
    $class   = ($file['since_phase'] ?? 0) > $phase ? 'orphan' : $file['class'];
    $suspect = $file['case_suspect_of'] ?? null;
    $checked++;

    if (!isset($actual[$path])) {
        $problems[] = "scenario {$file['scenario']}: $path is not in the report, expected $class";

        continue;
    }

    if ($actual[$path]['class'] !== $class) {
        $problems[] = "scenario {$file['scenario']}: $path is {$actual[$path]['class']}, expected $class";
    }

    if ($actual[$path]['case_suspect_of'] !== $suspect) {
        $problems[] = "scenario {$file['scenario']}: $path has case suspect " . json_encode($actual[$path]['case_suspect_of']) . ', expected ' . json_encode($suspect);
    }
}

foreach (array_diff_key($actual, $expected['files']) as $path => $file) {
    $problems[] = "$path is in the report as {$file['class']}, but it is not a fixture";
}

$broken = array_column($report['broken'], null, 'path');

foreach ($expected['broken'] as $path => $entry) {
    $checked++;

    if (!isset($broken[$path])) {
        $problems[] = "scenario {$entry['scenario']}: $path is not reported as broken";

        continue;
    }

    if ($broken[$path]['case_suspect'] !== $entry['case_suspect']) {
        $problems[] = "scenario {$entry['scenario']}: broken $path has case suspect " . json_encode($broken[$path]['case_suspect']) . ', expected ' . json_encode($entry['case_suspect']);
    }
}

foreach (array_diff_key($broken, $expected['broken']) as $path => $entry) {
    $problems[] = "$path is reported as broken, but it is not expected to be";
}

$sorted = static function (array $groups): array {
    $groups = array_map(static function (array $paths): array {
        sort($paths);

        return $paths;
    }, $groups);
    sort($groups);

    return $groups;
};

$checked++;

if ($sorted(array_column($report['duplicates'], 'paths')) !== $sorted($expected['duplicates'])) {
    $problems[] = 'duplicates are ' . json_encode(array_column($report['duplicates'], 'paths'), JSON_UNESCAPED_SLASHES)
        . ', expected ' . json_encode($expected['duplicates'], JSON_UNESCAPED_SLASHES);
}

$summary = $report['summary'];

printf(
    "Phase %d: %d files (%d in use, %d semi-orphan, %d orphan), %d broken, %d duplicate group(s)\n",
    $phase,
    $summary['files'],
    $summary['in_use'],
    $summary['semi_orphan'],
    $summary['orphan'],
    $summary['broken'],
    $summary['duplicate_groups']
);

if ($problems) {
    echo 'FAILED: ' . \count($problems) . " of $checked checks\n  " . implode("\n  ", $problems) . "\n";
    exit(1);
}

echo "OK: $checked checks match fixtures/expected.json\n";
