#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\ReleaseReportService;

$argv = $_SERVER['argv'] ?? [];
$release = null;
$includeDescription = !in_array('--no-description', $argv, true);
$includeDepartmentGroups = in_array('--with-department-groups', $argv, true);
$summaryOnly = in_array('--summary-only', $argv, true);
$latestRelease = in_array('--latest', $argv, true);
$summaryMode = 'rule';

foreach ($argv as $argument) {
    if (!is_string($argument) || $argument === '' || str_starts_with($argument, '--')) {
        continue;
    }

    if ($argument === $argv[0]) {
        continue;
    }

    $release ??= $argument;
}

foreach ($argv as $argument) {
    if (str_starts_with((string) $argument, '--summary-mode=')) {
        $summaryMode = (string) substr((string) $argument, strlen('--summary-mode='));
    }
}

if (($release === null || $release === '') && !$latestRelease) {
    $latestRelease = true;
}

if ($release !== null && $latestRelease) {
    fwrite(STDERR, "Use either <release> or --latest, not both.\n");
    exit(1);
}

try {
    $service = new ReleaseReportService();
    $result = $latestRelease
        ? $service->sendLatestReleaseReport(
            $includeDescription,
            true,
            $summaryMode,
            $includeDepartmentGroups
        )
        : $service->sendReleaseReport(
            (string) $release,
            $includeDescription,
            true,
            $summaryMode,
            $includeDepartmentGroups
        );

    if ($summaryOnly) {
        fwrite(STDOUT, (string) ($result['summary']['text'] ?? '') . PHP_EOL);
        exit(0);
    }

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
