#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\ReleaseReportWorkflow;

$argv = $_SERVER['argv'] ?? [];
$release = null;
$latestRelease = in_array('--latest', $argv, true);

foreach ($argv as $argument) {
    if (!is_string($argument) || $argument === '' || str_starts_with($argument, '--')) {
        continue;
    }

    if ($argument === $argv[0]) {
        continue;
    }

    $release ??= $argument;
}

if (($release === null || $release === '') && !$latestRelease) {
    $latestRelease = true;
}

if ($release !== null && $latestRelease) {
    fwrite(STDERR, "Use either <release> or --latest, not both.\n");
    exit(1);
}

try {
    $workflow = new ReleaseReportWorkflow();
    $result = $latestRelease
        ? $workflow->sendLatestReleaseReport(
            true
        )
        : $workflow->sendReleaseReport(
            (string) $release,
            true
        );

    $json = json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('Unable to encode CLI response to JSON');
    }

    fwrite(STDOUT, $json . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
