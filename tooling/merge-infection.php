<?php

declare(strict_types=1);

// Distil and merge Infection mutation reports across DB backends.
//
// The Infection workflow runs a `shard x db` matrix (each cell mutates a
// subset of src/ against one database backend). A mutant is only a true
// test-suite gap if it survives in *every* backend that covered it — if
// any backend kills it, our suite catches it somewhere. This script
// computes that union. (Mirrors how tooling/merge-coverage.php unions the
// per-backend Clover reports, but for mutation kill-sets rather than
// line coverage.)
//
// Two modes:
//
//   distill  Reduce one cell's infection.json (which embeds the full
//            source of every mutated file — hundreds of MB) down to a
//            compact per-mutant status record, safe to upload as an
//            artifact.
//
//   merge    Union the compact records from every cell. A mutant is
//            classed as killed if any backend killed/timed-out/errored
//            it, escaped only if it survived everywhere it ran, uncovered
//            only if no backend ever covered it. Emits a Markdown
//            summary, the escaped-everywhere list, a machine-readable
//            JSON, and an optional Stryker-dashboard report.
//
// MSI math mirrors Infection's own:
//     MSI         = (killed + timeout + error) / (killed + timeout + error + escaped + uncovered)
//     covered MSI = (killed + timeout + error) / (killed + timeout + error + escaped)
//
// Usage:
//   php tooling/merge-infection.php distill <infection.json> <out.json> --db <db> --shard <shard>
//   php tooling/merge-infection.php merge <compact.json> [<compact.json> ...] \
//       [--out-summary <md>] [--out-escaped <txt>] [--out-json <json>] \
//       [--out-stryker <json>] [--source-root <dir>]

// Top-level arrays Infection writes in its JSON logger. Note the report
// uses "timeouted"/"uncovered"/"errored"; skipped + ignored mutants are
// not emitted as entries and are excluded from MSI by construction.
const STATUS_ARRAYS = ['killed', 'escaped', 'errored', 'timeouted', 'uncovered'];

// Mutant statuses that mean "the suite detected the mutation".
const DETECTED = ['killed', 'timeouted', 'errored'];

const STRYKER_STATUS = [
    'killed' => 'Killed',
    'timeout' => 'Timeout',
    'error' => 'RuntimeError',
    'escaped' => 'Survived',
    'uncovered' => 'NoCoverage',
];

$argvCopy = $argv;
array_shift($argvCopy);
$mode = array_shift($argvCopy);

if ($mode === 'distill') {
    distill($argvCopy);
} elseif ($mode === 'merge') {
    merge($argvCopy);
} else {
    fwrite(STDERR, "Usage: merge-infection.php <distill|merge> ...\n");
    exit(1);
}

/**
 * Split CLI args into positionals and `--opt value` options.
 *
 * @param  list<string>  $args
 * @return array{0: list<string>, 1: array<string, string>}
 */
function parseArgs(array $args): array
{
    /** @var list<string> $positional */
    $positional = [];
    /** @var array<string, string> $options */
    $options = [];

    for ($i = 0, $n = count($args); $i < $n; $i++) {
        $arg = $args[$i];
        if (str_starts_with($arg, '--')) {
            $key = substr($arg, 2);
            $value = $args[$i + 1] ?? '';
            $options[$key] = $value;
            $i++;

            continue;
        }
        $positional[] = $arg;
    }

    return [$positional, $options];
}

function normPath(string $path): string
{
    $marker = '/src/';
    $idx = strpos($path, $marker);

    return $idx === false ? $path : substr($path, $idx + 1);
}

/**
 * @param  array<string, mixed>  $infection
 * @return list<array{0: string, 1: int, 2: string, 3: string}>
 */
function mutantRecords(array $infection, string $array): array
{
    /** @var list<array{0: string, 1: int, 2: string, 3: string}> $records */
    $records = [];

    /** @var list<array<string, mixed>> $entries */
    $entries = is_array($infection[$array] ?? null) ? $infection[$array] : [];

    foreach ($entries as $entry) {
        /** @var array<string, mixed> $mutator */
        $mutator = is_array($entry['mutator'] ?? null) ? $entry['mutator'] : [];
        $records[] = [
            normPath((string) ($mutator['originalFilePath'] ?? '')),
            (int) ($mutator['originalStartLine'] ?? 0),
            (string) ($mutator['mutatorName'] ?? ''),
            (string) ($entry['diff'] ?? ''),
        ];
    }

    return $records;
}

/**
 * @param  list<string>  $args
 */
function distill(array $args): void
{
    [$positional, $options] = parseArgs($args);

    $infectionPath = $positional[0] ?? '';
    $outPath = $positional[1] ?? '';
    $db = $options['db'] ?? '';
    $shard = $options['shard'] ?? '';

    if ($infectionPath === '' || $outPath === '' || $db === '' || $shard === '') {
        fwrite(STDERR, "Usage: merge-infection.php distill <infection.json> <out.json> --db <db> --shard <shard>\n");
        exit(1);
    }

    $raw = file_get_contents($infectionPath);
    if ($raw === false) {
        fwrite(STDERR, "Failed to read {$infectionPath}\n");
        exit(1);
    }

    /** @var array<string, mixed> $data */
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    unset($raw);

    $out = [
        'db' => $db,
        'shard' => $shard,
        'stats' => $data['stats'] ?? new stdClass,
    ];
    $counts = [];
    foreach (STATUS_ARRAYS as $array) {
        $records = mutantRecords($data, $array);
        $out[$array] = $records;
        $counts[$array] = count($records);
    }

    file_put_contents($outPath, json_encode($out, JSON_THROW_ON_ERROR));

    printf("distilled db=%s shard=%s: %s\n", $db, $shard, json_encode($counts));
}

/**
 * @param  array{0: string, 1: int, 2: string, 3: string}  $record
 */
function mutantKey(array $record): string
{
    [$file, $line, $name, $diff] = $record;
    $diffHash = substr(sha1($diff), 0, 12);

    return implode("\x00", [$file, (string) $line, $name, $diffHash]);
}

/**
 * @param  array<string, bool>  $seen
 */
function classify(array $seen): string
{
    if (isset($seen['killed'])) {
        return 'killed';
    }
    if (isset($seen['timeouted'])) {
        return 'timeout';
    }
    if (isset($seen['errored'])) {
        return 'error';
    }
    if (isset($seen['escaped'])) {
        return 'escaped';
    }

    return 'uncovered';
}

/**
 * @param  list<string>  $args
 */
function merge(array $args): void
{
    [$inputs, $options] = parseArgs($args);

    if ($inputs === []) {
        fwrite(STDERR, "Usage: merge-infection.php merge <compact.json> [<compact.json> ...] [options]\n");
        exit(1);
    }

    /** @var array<string, array<string, bool>> $seenStatus  key => set of raw Infection statuses */
    $seenStatus = [];
    /** @var array<string, array{0: string, 1: int, 2: string, 3: string}> $detail */
    $detail = [];
    /** @var array<string, bool> $backends */
    $backends = [];

    foreach ($inputs as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            fwrite(STDERR, "Failed to read {$path}\n");
            exit(1);
        }
        /** @var array<string, mixed> $cell */
        $cell = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $backends[(string) ($cell['db'] ?? '?')] = true;

        foreach (STATUS_ARRAYS as $array) {
            /** @var list<array{0: string, 1: int, 2: string, 3: string}> $records */
            $records = is_array($cell[$array] ?? null) ? $cell[$array] : [];
            foreach ($records as $record) {
                $key = mutantKey($record);
                $seenStatus[$key][$array] = true;
                if (! isset($detail[$key])) {
                    $detail[$key] = $record;
                }
            }
        }
    }

    $counts = ['killed' => 0, 'timeout' => 0, 'error' => 0, 'escaped' => 0, 'uncovered' => 0];
    /** @var list<array{0: string, 1: int, 2: string, 3: string}> $escapedEverywhere */
    $escapedEverywhere = [];
    $divergent = 0; // killed on some backends, escaped on others

    foreach ($seenStatus as $key => $seen) {
        $bucket = classify($seen);
        $counts[$bucket]++;

        $detected = false;
        foreach (DETECTED as $status) {
            if (isset($seen[$status])) {
                $detected = true;
                break;
            }
        }
        if ($detected && isset($seen['escaped'])) {
            $divergent++;
        }
        if ($bucket === 'escaped') {
            $escapedEverywhere[] = $detail[$key];
        }
    }

    $numerator = $counts['killed'] + $counts['timeout'] + $counts['error'];
    $coveredDenom = $numerator + $counts['escaped'];
    $totalDenom = $coveredDenom + $counts['uncovered'];
    $msi = $totalDenom ? round($numerator / $totalDenom * 100, 2) : 0.0;
    $coveredMsi = $coveredDenom ? round($numerator / $coveredDenom * 100, 2) : 0.0;

    usort(
        $escapedEverywhere,
        static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]],
    );

    $backendList = array_keys($backends);
    sort($backendList);

    $summary = [
        'backends' => $backendList,
        'counts' => $counts,
        'msi' => $msi,
        'coveredMsi' => $coveredMsi,
        'divergent' => $divergent,
        'totalMutants' => $totalDenom,
    ];

    if (($options['out-json'] ?? '') !== '') {
        file_put_contents(
            $options['out-json'],
            json_encode(
                $summary + ['escapedEverywhere' => $escapedEverywhere],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ),
        );
    }

    if (($options['out-summary'] ?? '') !== '') {
        writeMarkdown($options['out-summary'], $summary);
    }

    if (($options['out-escaped'] ?? '') !== '') {
        writeEscaped($options['out-escaped'], $escapedEverywhere, $summary);
    }

    if (($options['out-stryker'] ?? '') !== '') {
        writeStryker($options['out-stryker'], $seenStatus, $detail, $options['source-root'] ?? '.');
    }

    // Always echo the headline to stdout / job log.
    printf(
        "UNION MSI=%s%%  covered-MSI=%s%%  killed=%d timeout=%d error=%d escaped=%d uncovered=%d divergent=%d backends=[%s]\n",
        $msi,
        $coveredMsi,
        $counts['killed'],
        $counts['timeout'],
        $counts['error'],
        $counts['escaped'],
        $counts['uncovered'],
        $divergent,
        implode(', ', $backendList),
    );
}

/**
 * @param  array{backends: list<string>, counts: array<string, int>, msi: float, coveredMsi: float, divergent: int, totalMutants: int}  $summary
 */
function writeMarkdown(string $path, array $summary): void
{
    $c = $summary['counts'];
    $lines = [
        '## Mutation testing — union across backends',
        '',
        'Backends merged: **'.implode(', ', $summary['backends']).'**',
        '',
        '| Metric | Value |',
        '| --- | --- |',
        "| MSI | **{$summary['msi']}%** |",
        "| Covered MSI | **{$summary['coveredMsi']}%** |",
        "| Killed | {$c['killed']} |",
        "| Timed out | {$c['timeout']} |",
        "| Errored | {$c['error']} |",
        "| Escaped (every backend) | {$c['escaped']} |",
        "| Uncovered (every backend) | {$c['uncovered']} |",
        "| Backend-divergent | {$summary['divergent']} |",
        "| Total (excl. skipped) | {$summary['totalMutants']} |",
        '',
        '_Escaped = survived in every backend that covered it (a true '.
        'test-suite gap). Backend-divergent = killed on some backends, '.
        'escaped on others (a backend-specific assertion gap)._',
        '',
    ];
    file_put_contents($path, implode("\n", $lines));
}

/**
 * @param  list<array{0: string, 1: int, 2: string, 3: string}>  $escaped
 * @param  array{backends: list<string>, counts: array<string, int>, msi: float, coveredMsi: float, divergent: int, totalMutants: int}  $summary
 */
function writeEscaped(string $path, array $escaped, array $summary): void
{
    $backends = '['.implode(', ', $summary['backends']).']';
    $lines = [
        '# Escaped in every backend ('.$backends.') — '.count($escaped).' mutants',
        '',
    ];
    foreach ($escaped as [$file, $line, $name, $diff]) {
        $lines[] = "{$file}:{$line}  [M] {$name}";
        foreach (explode("\n", trim($diff, "\n")) as $diffLine) {
            $lines[] = "    {$diffLine}";
        }
        $lines[] = '';
    }
    file_put_contents($path, implode("\n", $lines));
}

/**
 * Build a Stryker-dashboard mutation report from the union.
 *
 * Locations are minimal (start line only; column/end fabricated) because
 * Infection's JSON doesn't carry columns — the dashboard validates the
 * schema and reads the score; the line-level view stays approximate.
 *
 * @param  array<string, array<string, bool>>  $seenStatus
 * @param  array<string, array{0: string, 1: int, 2: string, 3: string}>  $detail
 */
function writeStryker(string $path, array $seenStatus, array $detail, string $sourceRoot): void
{
    /** @var array<string, array{language: string, source: string, mutants: list<array<string, mixed>>}> $files */
    $files = [];
    /** @var array<string, string> $sourceCache */
    $sourceCache = [];

    foreach ($seenStatus as $key => $seen) {
        [$file, $line, $name] = $detail[$key];
        $bucket = classify($seen);

        if (! isset($files[$file])) {
            if (! isset($sourceCache[$file])) {
                $source = '';
                $full = $sourceRoot !== '' ? rtrim($sourceRoot, '/').'/'.$file : $file;
                if (is_file($full)) {
                    $contents = file_get_contents($full);
                    $source = $contents === false ? '' : $contents;
                }
                $sourceCache[$file] = $source;
            }
            $files[$file] = ['language' => 'php', 'source' => $sourceCache[$file], 'mutants' => []];
        }

        $startLine = max($line, 1);
        $files[$file]['mutants'][] = [
            'id' => sha1($key),
            'mutatorName' => $name,
            'status' => STRYKER_STATUS[$bucket],
            'location' => [
                'start' => ['line' => $startLine, 'column' => 1],
                'end' => ['line' => $startLine + 1, 'column' => 1],
            ],
        ];
    }

    $report = [
        'schemaVersion' => '1',
        'thresholds' => ['high' => 80, 'low' => 60],
        'files' => $files === [] ? new stdClass : $files,
    ];
    file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR));
}
