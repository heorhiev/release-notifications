<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\JiraClient;
use App\ReleaseReportService;
use App\ReleaseSummary\SummaryService;
use App\ReportRunRepository;

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        http_response_code(500);
        echo '{"error":"Unable to encode JSON response"}';
        exit;
    }

    echo $json;
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($method === 'GET' && $path === '/health') {
    jsonResponse(['status' => 'ok']);
}

if ($method === 'POST' && $path === '/release-report') {
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '{}', true);

    if (!is_array($payload)) {
        jsonResponse(['error' => 'Invalid JSON body'], 400);
    }

    $release = trim((string) ($payload['release'] ?? ''));
    $latestRelease = array_key_exists('latest_release', $payload)
        ? (bool) $payload['latest_release']
        : false;
    $includeDescription = array_key_exists('include_description', $payload)
        ? (bool) $payload['include_description']
        : true;
    $dryRun = array_key_exists('dry_run', $payload)
        ? (bool) $payload['dry_run']
        : false;
    $summaryMode = trim((string) ($payload['summary_mode'] ?? 'rule'));
    $includeDepartmentGroups = array_key_exists('include_department_groups', $payload)
        ? (bool) $payload['include_department_groups']
        : false;

    if ($release !== '' && $latestRelease) {
        jsonResponse(['error' => 'Use either "release" or "latest_release", not both'], 422);
    }

    try {
        $service = new ReleaseReportService();
        $result = ($release === '' || $latestRelease)
            ? $service->sendLatestReleaseReport(
                $includeDescription,
                $dryRun,
                $summaryMode,
                $includeDepartmentGroups
            )
            : $service->sendReleaseReport(
                $release,
                $includeDescription,
                $dryRun,
                $summaryMode,
                $includeDepartmentGroups
            );

        jsonResponse($result, 200);
    } catch (Throwable $exception) {
        jsonResponse(['error' => $exception->getMessage()], 500);
    }
}

if ($method === 'GET' && $path === '/debug/project-versions') {
    try {
        $jiraClient = new JiraClient();
        $versions = $jiraClient->getProjectVersions();

        jsonResponse(
            [
                'count' => count($versions),
                'versions' => $versions,
            ],
            200
        );
    } catch (Throwable $exception) {
        jsonResponse(['error' => $exception->getMessage()], 500);
    }
}

if ($method === 'GET' && $path === '/debug/jira-search') {
    $release = trim((string) ($_GET['release'] ?? ''));

    if ($release === '') {
        jsonResponse(['error' => 'Query param "release" is required'], 422);
    }

    try {
        $jiraClient = new JiraClient();
        $result = $jiraClient->searchIssuesByRelease($release);

        jsonResponse($result, 200);
    } catch (Throwable $exception) {
        jsonResponse(['error' => $exception->getMessage()], 500);
    }
}

if ($method === 'GET' && $path === '/debug/summary') {
    $release = trim((string) ($_GET['release'] ?? ''));
    $summaryMode = trim((string) ($_GET['summary_mode'] ?? 'rule'));
    $latestRelease = array_key_exists('latest_release', $_GET)
        ? (bool) $_GET['latest_release']
        : false;

    try {
        $jiraClient = new JiraClient();
        $summaryService = new SummaryService();
        if ($release !== '' && $latestRelease) {
            jsonResponse(['error' => 'Use either query param "release" or "latest_release", not both'], 422);
        }

        if ($release === '' || $latestRelease) {
            $release = $jiraClient->getLatestReleaseName();
        }

        $searchResult = $jiraClient->searchIssuesByRelease($release);
        $summary = $summaryService->generate($release, $searchResult['issues'], $summaryMode);

        jsonResponse([
            'release' => $release,
            'summary_mode' => $summaryMode,
            'issues_count' => count($searchResult['issues']),
            'summary' => [
                'mode' => $summary->mode,
                'text' => $summary->text,
            ],
        ]);
    } catch (Throwable $exception) {
        jsonResponse(['error' => $exception->getMessage()], 500);
    }
}

if ($method === 'GET' && $path === '/debug/report-runs') {
    $limit = (int) ($_GET['limit'] ?? 20);

    try {
        $repository = new ReportRunRepository();
        $runs = $repository->listRuns($limit);

        jsonResponse(
            [
                'count' => count($runs),
                'runs' => $runs,
            ],
            200
        );
    } catch (Throwable $exception) {
        jsonResponse(['error' => $exception->getMessage()], 500);
    }
}

if ($method === 'GET' && preg_match('#^/debug/report-runs/(\d+)$#', (string) $path, $matches) === 1) {
    $runId = (int) $matches[1];

    try {
        $repository = new ReportRunRepository();
        $run = $repository->findRunWithIssues($runId);

        if ($run === null) {
            jsonResponse(['error' => 'Report run not found'], 404);
        }

        jsonResponse($run, 200);
    } catch (Throwable $exception) {
        jsonResponse(['error' => $exception->getMessage()], 500);
    }
}

jsonResponse(['error' => 'Not found'], 404);
