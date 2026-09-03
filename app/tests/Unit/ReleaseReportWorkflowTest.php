<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\EmployeeRepositoryInterface;
use App\Contracts\JiraClientInterface;
use App\Contracts\ReportRunRepositoryInterface;
use App\Contracts\SlackClientInterface;
use App\DTO\CreateReportRunData;
use App\DTO\JiraSearchResult;
use App\ReleaseReportWorkflow;
use App\ReleaseSummary\Contracts\SummaryServiceInterface;
use App\ReleaseSummary\DTO\SummaryResult;
use PHPUnit\Framework\TestCase;

final class ReleaseReportWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['JIRA_BASE_URL'] = 'https://jira.example.test';
        $_ENV['JIRA_PROJECT_KEY'] = 'PROJECT';
    }

    protected function tearDown(): void
    {
        unset($_ENV['JIRA_BASE_URL'], $_ENV['JIRA_PROJECT_KEY']);
    }

    public function testDryRunStoresTypedReportDataWithoutSendingSlack(): void
    {
        $jira = new class implements JiraClientInterface {
            public function searchIssuesByRelease(string $release): JiraSearchResult
            {
                return new JiraSearchResult([
                    [
                        'key' => 'PROJECT-1',
                        'fields' => [
                            'summary' => 'Typed report',
                            'issuetype' => ['name' => 'Story'],
                            'status' => ['name' => 'Done'],
                            'reporter' => ['accountId' => 'jira-user'],
                        ],
                    ],
                ], 1, 'project = PROJECT');
            }

            public function getLatestReleaseName(): string
            {
                return 'R1';
            }

            public function getNextReleaseName(string $currentRelease): ?string
            {
                return 'R2';
            }

            public function getReleaseUrlByName(string $release): ?string
            {
                return 'https://jira.example.test/release/1';
            }
        };
        $slack = new class implements SlackClientInterface {
            public int $calls = 0;

            public function sendMessage(string $text, ?string $releaseUrl = null): void
            {
                $this->calls++;
            }
        };
        $repository = new class implements ReportRunRepositoryInterface {
            public ?CreateReportRunData $data = null;

            public function createRun(CreateReportRunData $runData, array $issues): int
            {
                $this->data = $runData;
                return 42;
            }
        };
        $employees = new class implements EmployeeRepositoryInterface {
            public function findSlackUserIdsByJiraUserIds(array $jiraUserIds): array
            {
                return [];
            }

            public function findSlackUserIdsByRole(int $role): array
            {
                return [];
            }
        };
        $summaryService = new class implements SummaryServiceInterface {
            public function generate(string $release, array $issues): SummaryResult
            {
                return new SummaryResult('rule', 'Summary', ['Summary'], ['provider' => 'rule']);
            }
        };

        $result = (new ReleaseReportWorkflow(
            jiraClient: $jira,
            slackClient: $slack,
            reportRunRepository: $repository,
            summaryService: $summaryService,
            employeeRepository: $employees,
        ))->sendReleaseReport('R1', true);

        self::assertSame(0, $slack->calls);
        self::assertInstanceOf(CreateReportRunData::class, $repository->data);
        self::assertSame('R1', $repository->data->releaseName);
        self::assertSame('project = PROJECT', $repository->data->jiraJql);
        self::assertTrue($repository->data->dryRun);
        self::assertSame(42, $result['report_run_id']);
        self::assertFalse($result['sent']);
    }
}
