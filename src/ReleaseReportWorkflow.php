<?php

declare(strict_types=1);

namespace App;

use App\Client\JiraClient;
use App\Client\SlackClient;
use App\Contracts\EmployeeRepositoryInterface;
use App\Contracts\JiraClientInterface;
use App\Contracts\ReportRunRepositoryInterface;
use App\Contracts\SlackClientInterface;
use App\DTO\CreateReportRunData;
use App\Model\EmployeeRole;
use App\ReleaseSummary\Contracts\SummaryServiceInterface;
use App\ReleaseSummary\SummaryService;
use App\Repository\EmployeeRepository;
use App\Repository\ReportRunRepository;
use App\Support\IssueFormatter;
use App\Support\ReporterIdExtractor;

final class ReleaseReportWorkflow
{
    private JiraClientInterface $jiraClient;
    private SlackClientInterface $slackClient;
    private IssueFormatter $issueFormatter;
    private ReportRunRepositoryInterface $reportRunRepository;
    private SummaryServiceInterface $summaryService;
    private EmployeeRepositoryInterface $employeeRepository;
    private ReporterIdExtractor $reporterIdExtractor;

    public function __construct(
        ?JiraClientInterface $jiraClient = null,
        ?SlackClientInterface $slackClient = null,
        ?IssueFormatter $issueFormatter = null,
        ?ReportRunRepositoryInterface $reportRunRepository = null,
        ?SummaryServiceInterface $summaryService = null,
        ?EmployeeRepositoryInterface $employeeRepository = null,
        ?ReporterIdExtractor $reporterIdExtractor = null,
    ) {
        $this->jiraClient = $jiraClient ?? new JiraClient();
        $this->slackClient = $slackClient ?? new SlackClient();
        $this->issueFormatter = $issueFormatter ?? new IssueFormatter();
        $this->reportRunRepository = $reportRunRepository ?? new ReportRunRepository();
        $this->summaryService = $summaryService ?? new SummaryService();
        $this->employeeRepository = $employeeRepository ?? new EmployeeRepository();
        $this->reporterIdExtractor = $reporterIdExtractor ?? new ReporterIdExtractor();
    }

    /**
     * @return array<string, mixed>
     */
    public function sendReleaseReport(
        string $release,
        bool $dryRun = false
    ): array
    {
        $releaseUrl = $this->jiraClient->getReleaseUrlByName($release);
        $searchResult = $this->jiraClient->searchIssuesByRelease($release);
        $issues = $searchResult->issues;
        $summary = $this->summaryService->generate($release, $issues);
        $message = $this->issueFormatter->formatSummarySlackMessage($release, $summary->text);
        $nextRelease = $this->jiraClient->getNextReleaseName($release);

        if (!$dryRun) {
            $this->slackClient->sendMessage($message, $releaseUrl);
            $developersCheckMessage = $this->issueFormatter->formatDevelopersCheckMessage(
                $release,
                $nextRelease,
                $this->employeeRepository->findSlackUserIdsByRole(EmployeeRole::DEVELOPER)
            );

            if ($developersCheckMessage !== '') {
                $this->slackClient->sendMessage($developersCheckMessage);
            }

            $reportersCheckMessage = $this->issueFormatter->formatReportersCheckMessage(
                $release,
                $nextRelease,
                $this->employeeRepository->findSlackUserIdsByJiraUserIds($this->reporterIdExtractor->fromJiraIssues($issues))
            );
            if ($reportersCheckMessage !== '') {
                $this->slackClient->sendMessage($reportersCheckMessage);
            }
        }

        $reportRunId = $this->reportRunRepository->createRun(new CreateReportRunData(
            releaseName: $release,
            issuesCount: count($issues),
            includeDescription: true,
            dryRun: $dryRun,
            slackSent: !$dryRun,
            summaryText: $summary->text,
            summaryMode: $summary->mode,
            summaryProvider: (string) ($summary->meta['provider'] ?? $summary->mode),
            summaryModel: isset($summary->meta['model']) ? (string) $summary->meta['model'] : null,
            summaryFallbackUsed: (bool) ($summary->meta['fallback_used'] ?? false),
            summaryRawOutput: $summary->rawOutput,
            messagePreview: $message,
            jiraJql: $searchResult->jql,
            releaseUrl: $releaseUrl,
        ), $issues);

        $result = [
            'report_run_id' => $reportRunId,
            'release' => $release,
            'issues_count' => count($issues),
            'dry_run' => $dryRun,
            'summary' => [
                'mode' => $summary->mode,
                'text' => $summary->text,
            ],
            'release_url' => $releaseUrl,
            'next_release' => $nextRelease,
            'sent' => !$dryRun,
        ];

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendLatestReleaseReport(
        bool $dryRun = false
    ): array {
        $release = $this->jiraClient->getLatestReleaseName();

        return $this->sendReleaseReport(
            $release,
            $dryRun
        );
    }
}
