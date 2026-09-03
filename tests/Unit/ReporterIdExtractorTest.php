<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReporterIdExtractor;
use PHPUnit\Framework\TestCase;

final class ReporterIdExtractorTest extends TestCase
{
    public function testExtractsUniqueReporterIdsFromJiraIssues(): void
    {
        $extractor = new ReporterIdExtractor();
        $issues = [
            ['fields' => ['reporter' => ['accountId' => ' jira-1 ']]],
            ['fields' => ['reporter' => ['accountId' => 'jira-1']]],
            ['fields' => ['reporter' => ['accountId' => 'jira-2']]],
            ['fields' => []],
        ];

        self::assertSame(['jira-1', 'jira-2'], $extractor->fromJiraIssues($issues));
    }

    public function testExtractsReporterIdsFromSavedRawIssues(): void
    {
        $snapshots = [[
            'raw_issue' => ['fields' => ['reporter' => ['accountId' => 'jira-saved']]],
        ]];

        self::assertSame(
            ['jira-saved'],
            (new ReporterIdExtractor())->fromReportRunSnapshots($snapshots)
        );
    }
}
