<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ReleaseSummary\Support\ReleaseIssueMapper;
use PHPUnit\Framework\TestCase;

final class ReleaseIssueMapperTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['JIRA_BASE_URL'] = 'https://jira.example.test';
    }

    protected function tearDown(): void
    {
        unset($_ENV['JIRA_BASE_URL']);
    }

    public function testMapsJiraFieldsAndAtlassianDocumentDescription(): void
    {
        $issue = (new ReleaseIssueMapper())->mapOne([
            'key' => 'PROJECT-42',
            'fields' => [
                'summary' => ' Test issue ',
                'issuetype' => ['name' => 'Story'],
                'status' => ['name' => 'Done'],
                'assignee' => ['displayName' => 'Ada'],
                'description' => [
                    'content' => [
                        ['content' => [['text' => 'First']]],
                        ['content' => [['text' => 'second']]],
                    ],
                ],
                'labels' => [' backend ', 'backend', '', 123],
                'components' => [['name' => ' API '], ['name' => 'API']],
                'parent' => ['key' => 'PROJECT-1', 'fields' => ['summary' => 'Epic']],
                'subtasks' => [
                    ['key' => 'PROJECT-43', 'fields' => ['summary' => 'Child']],
                    ['key' => 'PROJECT-43', 'fields' => ['summary' => 'Duplicate']],
                ],
            ],
        ]);

        self::assertSame('PROJECT-42', $issue->key);
        self::assertSame('Test issue', $issue->summary);
        self::assertSame('First second', $issue->description);
        self::assertSame(['backend'], $issue->labels);
        self::assertSame(['API'], $issue->components);
        self::assertSame('PROJECT-1', $issue->parentKey);
        self::assertCount(1, $issue->subTasks);
        self::assertSame('PROJECT-43', $issue->subTasks[0]->key);
        self::assertSame('Child', $issue->subTasks[0]->summary);
    }
}
