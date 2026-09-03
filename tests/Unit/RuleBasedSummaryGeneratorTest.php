<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\EpicRepositoryInterface;
use App\Model\Epic;
use App\ReleaseSummary\DTO\ReleaseIssue;
use App\ReleaseSummary\RuleBased\RuleBasedSummaryGenerator;
use PHPUnit\Framework\TestCase;

final class RuleBasedSummaryGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['JIRA_BASE_URL'] = 'https://jira.example.test';
    }

    protected function tearDown(): void
    {
        unset($_ENV['JIRA_BASE_URL']);
    }

    public function testReturnsExplicitMessageForEmptyRelease(): void
    {
        $result = $this->generator()->generate('empty', []);

        self::assertSame('rule', $result->mode);
        self::assertSame('- В этом релизе не найдено задач Jira.', $result->text);
        self::assertSame(0, $result->meta['issues_count']);
    }

    public function testGroupsIssuesByEpicAndPutsUngroupedTasksLast(): void
    {
        $generator = $this->generator();
        $issues = [
            $this->issue('PROJECT-2', 'Standalone'),
            $this->issue('PROJECT-10', 'Epic child', 'PROJECT-1', 'Payments'),
        ];

        $result = $generator->generate('R1', $issues);

        self::assertSame(2, $result->meta['group_count']);
        self::assertLessThan(
            strpos($result->text, '*Tasks Without Epic*'),
            strpos($result->text, 'Payments')
        );
        self::assertStringContainsString('Epic child', $result->text);
        self::assertStringContainsString('Standalone', $result->text);
    }

    public function testConfiguredLowerPriorityEpicIsPlacedLast(): void
    {
        $generator = $this->generator(['PROJECT-9' => 0]);
        $issues = [
            $this->issue('PROJECT-10', 'Normal task', 'PROJECT-1', 'Normal'),
            $this->issue('PROJECT-11', 'Bug task', 'PROJECT-9', 'Renamed bugs epic'),
        ];

        $result = $generator->generate('R1', $issues);

        self::assertLessThan(
            strpos($result->text, 'Renamed bugs epic'),
            strpos($result->text, 'Normal')
        );
    }

    public function testEqualPriorityEpicsAreOrderedByDisplayName(): void
    {
        $issues = [
            $this->issue('PROJECT-10', 'Task Z', 'PROJECT-1', 'Zulu'),
            $this->issue('PROJECT-11', 'Task A', 'PROJECT-9', 'Alpha'),
        ];

        $result = $this->generator()->generate('R1', $issues);

        self::assertLessThan(
            strpos($result->text, 'Zulu'),
            strpos($result->text, 'Alpha')
        );
    }

    /** @param array<string, int> $priorities */
    private function generator(array $priorities = []): RuleBasedSummaryGenerator
    {
        $repository = new class($priorities) implements EpicRepositoryInterface {
            /** @param array<string, int> $priorities */
            public function __construct(private array $priorities)
            {
            }

            public function findByJiraKeys(array $jiraKeys): array
            {
                $epics = [];
                foreach ($jiraKeys as $index => $jiraKey) {
                    if (array_key_exists($jiraKey, $this->priorities)) {
                        $epics[] = new Epic($index + 1, $jiraKey, $this->priorities[$jiraKey]);
                    }
                }

                return $epics;
            }
        };

        return new RuleBasedSummaryGenerator($repository);
    }

    private function issue(
        string $key,
        string $summary,
        ?string $parentKey = null,
        ?string $parentSummary = null
    ): ReleaseIssue {
        return new ReleaseIssue(
            key: $key,
            summary: $summary,
            url: 'https://jira.example.test/browse/' . $key,
            issueType: 'Story',
            status: 'Done',
            assignee: null,
            description: null,
            labels: [],
            components: [],
            parentKey: $parentKey,
            parentSummary: $parentSummary
        );
    }
}
