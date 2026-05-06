<?php

declare(strict_types=1);

namespace App;

use App\ReleaseDepartments\DepartmentGroupingService;
use App\ReleaseSummary\SummaryService;

final class ReleaseReportService
{
    private JiraClient $jiraClient;
    private SlackClient $slackClient;
    private IssueFormatter $issueFormatter;
    private ReportRunRepository $reportRunRepository;
    private SummaryService $summaryService;
    private DepartmentGroupingService $departmentGroupingService;

    public function __construct(
        ?JiraClient $jiraClient = null,
        ?SlackClient $slackClient = null,
        ?IssueFormatter $issueFormatter = null,
        ?ReportRunRepository $reportRunRepository = null,
        ?SummaryService $summaryService = null,
        ?DepartmentGroupingService $departmentGroupingService = null
    ) {
        $this->jiraClient = $jiraClient ?? new JiraClient();
        $this->slackClient = $slackClient ?? new SlackClient();
        $this->issueFormatter = $issueFormatter ?? new IssueFormatter();
        $this->reportRunRepository = $reportRunRepository ?? new ReportRunRepository();
        $this->summaryService = $summaryService ?? new SummaryService();
        $this->departmentGroupingService = $departmentGroupingService ?? new DepartmentGroupingService();
    }

    /**
     * @return array<string, mixed>
     */
    public function sendReleaseReport(
        string $release,
        bool $includeDescription = true,
        bool $dryRun = false,
        ?string $summaryMode = 'rule',
        bool $includeDepartmentGroups = false
    ): array
    {
        $releaseUrl = $this->jiraClient->getReleaseUrlByName($release);
        $searchResult = $this->jiraClient->searchIssuesByRelease($release);
        $issues = $searchResult['issues'];
        $detailsText = $this->issueFormatter->formatReleaseReport($release, $issues, $includeDescription);
        $summary = $this->summaryService->generate($release, $issues, $summaryMode);
        $departmentGroups = $includeDepartmentGroups
            ? $this->departmentGroupingService->groupRawIssues($issues)
            : [];
        $departmentGroupsText = $includeDepartmentGroups
            ? $this->issueFormatter->formatDepartmentGroups($departmentGroups)
            : null;
        $message = $this->issueFormatter->formatSlackMessage(
            $release,
            $summary->text,
            $departmentGroupsText,
            $detailsText
        );

        if (!$dryRun) {
            $this->slackClient->sendMessage($message, $releaseUrl);
            $releaseCheckMessage = $this->issueFormatter->formatReleaseCheckMessage();

            if ($releaseCheckMessage !== '') {
                $this->slackClient->sendMessage($releaseCheckMessage);
            }
        }

        $reportRunId = $this->reportRunRepository->createRun([
            'release_name' => $release,
            'issues_count' => count($issues),
            'include_description' => $includeDescription,
            'dry_run' => $dryRun,
            'slack_sent' => !$dryRun,
            'summary_text' => $summary->text,
            'summary_mode' => $summary->mode,
            'summary_provider' => $summary->meta['provider'] ?? $summary->mode,
            'summary_model' => $summary->meta['model'] ?? null,
            'summary_fallback_used' => (bool) ($summary->meta['fallback_used'] ?? false),
            'summary_raw_output' => $summary->rawOutput,
            'message_preview' => $message,
            'jira_jql' => (string) $searchResult['jql'],
            'release_url' => $releaseUrl,
        ], $issues);

        $result = [
            'report_run_id' => $reportRunId,
            'release' => $release,
            'issues_count' => count($issues),
            'include_description' => $includeDescription,
            'dry_run' => $dryRun,
            'include_department_groups' => $includeDepartmentGroups,
            'summary' => [
                'mode' => $summary->mode,
                'text' => $summary->text,
            ],
            'release_url' => $releaseUrl,
            'sent' => !$dryRun,
        ];

        if ($includeDepartmentGroups) {
            $result['department_groups'] = array_map(
                static fn ($group): array => $group->toArray(),
                $departmentGroups
            );
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendLatestReleaseReport(
        bool $includeDescription = true,
        bool $dryRun = false,
        ?string $summaryMode = 'rule',
        bool $includeDepartmentGroups = false
    ): array {
        $release = $this->jiraClient->getLatestReleaseName();

        return $this->sendReleaseReport(
            $release,
            $includeDescription,
            $dryRun,
            $summaryMode,
            $includeDepartmentGroups
        );
    }
}
