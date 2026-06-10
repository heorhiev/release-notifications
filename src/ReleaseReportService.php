<?php

declare(strict_types=1);

namespace App;

use App\ReleaseSummary\SummaryService;

final class ReleaseReportService
{
    private JiraClient $jiraClient;
    private SlackClient $slackClient;
    private IssueFormatter $issueFormatter;
    private ReportRunRepository $reportRunRepository;
    private SummaryService $summaryService;
    private EmployeeRepository $employeeRepository;

    public function __construct(
        ?JiraClient $jiraClient = null,
        ?SlackClient $slackClient = null,
        ?IssueFormatter $issueFormatter = null,
        ?ReportRunRepository $reportRunRepository = null,
        ?SummaryService $summaryService = null,
        ?EmployeeRepository $employeeRepository = null
    ) {
        $this->jiraClient = $jiraClient ?? new JiraClient();
        $this->slackClient = $slackClient ?? new SlackClient();
        $this->issueFormatter = $issueFormatter ?? new IssueFormatter();
        $this->reportRunRepository = $reportRunRepository ?? new ReportRunRepository();
        $this->summaryService = $summaryService ?? new SummaryService();
        $this->employeeRepository = $employeeRepository ?? new EmployeeRepository();
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
        $issues = $searchResult['issues'];
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
                $this->employeeRepository->findSlackUserIdsByJiraUserIds($this->extractReporterJiraUserIds($issues))
            );
            if ($reportersCheckMessage !== '') {
                $this->slackClient->sendMessage($reportersCheckMessage);
            }
        }

        $reportRunId = $this->reportRunRepository->createRun([
            'release_name' => $release,
            'issues_count' => count($issues),
            'include_description' => true,
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
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, string>
     */
    private function extractReporterJiraUserIds(array $issues): array
    {
        $jiraUserIds = [];

        foreach ($issues as $issue) {
            $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];
            $reporter = is_array($fields['reporter'] ?? null) ? $fields['reporter'] : [];
            $accountId = trim((string) ($reporter['accountId'] ?? ''));

            if ($accountId !== '') {
                $jiraUserIds[] = $accountId;
            }
        }

        return array_values(array_unique($jiraUserIds));
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
