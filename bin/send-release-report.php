#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\IssueFormatter;
use App\EmployeeRepository;
use App\JiraClient;
use App\ReportRunRepository;
use App\SlackClient;

$argv = $_SERVER['argv'] ?? [];
$release = null;
$latestRun = in_array('--latest', $argv, true);
$preview = in_array('--preview', $argv, true);
$runId = null;

foreach ($argv as $argument) {
    if (!is_string($argument) || $argument === '' || $argument === $argv[0]) {
        continue;
    }

    if (str_starts_with($argument, '--run-id=')) {
        $runId = (int) substr($argument, strlen('--run-id='));
        continue;
    }

    if (!str_starts_with($argument, '--')) {
        $release ??= $argument;
    }
}

if ($release !== null && $latestRun) {
    fwrite(STDERR, "Use either <release> or --latest, not both.\n");
    exit(1);
}

if ($runId !== null && ($release !== null || $latestRun)) {
    fwrite(STDERR, "Use either --run-id=<id> or <release>/--latest.\n");
    exit(1);
}

try {
    $repository = new ReportRunRepository();

    if ($runId !== null) {
        $run = $repository->findRunWithIssues($runId);
    } elseif ($release !== null && $release !== '') {
        $run = $repository->findLatestRun($release, true);
    } else {
        $run = $repository->findLatestRun(null, true);
    }

    if ($run === null) {
        throw new RuntimeException('No unsent saved report run found for Slack sending.');
    }

    $run = $repository->findRunWithIssues((int) $run['id']);
    if ($run === null) {
        throw new RuntimeException('Saved report run no longer exists.');
    }

    $summaryText = trim((string) ($run['summary_text'] ?? ''));
    $releaseName = trim((string) ($run['release_name'] ?? ''));
    $releaseUrl = trim((string) ($run['release_url'] ?? ''));

    if ($summaryText === '' || $releaseName === '') {
        throw new RuntimeException('Saved report run does not contain a valid release summary.');
    }

    $jiraClient = new JiraClient();
    if ($releaseUrl === '') {
        $releaseUrl = $jiraClient->getReleaseUrlByName($releaseName) ?? '';
    }

    $nextRelease = $jiraClient->getNextReleaseName($releaseName);
    $formatter = new IssueFormatter();
    $message = $formatter->formatSummarySlackMessage($releaseName, $summaryText);
    $employeeRepository = new EmployeeRepository();
    $developersCheckMessage = $formatter->formatDevelopersCheckMessage(
        $releaseName,
        $nextRelease,
        $employeeRepository->findSlackUserIdsByRole(App\EmployeeRole::DEVELOPER)
    );
    $reportersCheckMessage = $formatter->formatReportersCheckMessage(
        $releaseName,
        $nextRelease,
        $employeeRepository->findSlackUserIdsByJiraUserIds(extractReporterJiraUserIds($run['issues'] ?? []))
    );

    if ($preview) {
        fwrite(STDOUT, $message . PHP_EOL);
        if ($developersCheckMessage !== '') {
            fwrite(STDOUT, "\n---\n\n" . $developersCheckMessage . PHP_EOL);
        }
        if ($reportersCheckMessage !== '') {
            fwrite(STDOUT, "\n---\n\n" . $reportersCheckMessage . PHP_EOL);
        }
        exit(0);
    }

    $slackClient = new SlackClient();
    $slackClient->sendMessage($message, $releaseUrl !== '' ? $releaseUrl : null);
    if ($developersCheckMessage !== '') {
        $slackClient->sendMessage($developersCheckMessage);
    }
    if ($reportersCheckMessage !== '') {
        $slackClient->sendMessage($reportersCheckMessage);
    }
    $repository->markSlackSent((int) $run['id']);

    $payload = [
        'report_run_id' => (int) $run['id'],
        'release' => $releaseName,
        'summary_mode' => (string) ($run['summary_mode'] ?? 'unknown'),
        'summary_provider' => (string) ($run['summary_provider'] ?? 'unknown'),
        'release_url' => $releaseUrl,
        'next_release' => $nextRelease,
        'sent' => true,
    ];

    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        throw new RuntimeException('Unable to encode send-release-report response to JSON');
    }

    fwrite(STDOUT, $json . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @param mixed $issues
 * @return array<int, string>
 */
function extractReporterJiraUserIds(mixed $issues): array
{
    if (!is_array($issues)) {
        return [];
    }

    $jiraUserIds = [];

    foreach ($issues as $issue) {
        if (!is_array($issue)) {
            continue;
        }

        $rawIssue = is_array($issue['raw_issue'] ?? null) ? $issue['raw_issue'] : [];
        $fields = is_array($rawIssue['fields'] ?? null) ? $rawIssue['fields'] : [];
        $reporter = is_array($fields['reporter'] ?? null) ? $fields['reporter'] : [];
        $accountId = trim((string) ($reporter['accountId'] ?? ''));

        if ($accountId !== '') {
            $jiraUserIds[] = $accountId;
        }
    }

    return array_values(array_unique($jiraUserIds));
}
