<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\IssueFormatter;
use PHPUnit\Framework\TestCase;

final class IssueFormatterTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('JIRA_BASE_URL', 'https://jira.example.test');
        $this->setEnv('JIRA_PROJECT_KEY', 'TEST');
        $this->setEnv('SLACK_DEVELOPERS_CHECK_TEXT', 'Release {release}; next {next_release}; {developers}');
        $this->setEnv('SLACK_REPORTERS_CHECK_TEXT', 'Reporters for {release}: {reporters}');
        $this->setEnv('SLACK_REPORTERS_EXTRA_USER_IDS', '');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            if ($value === false) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
                continue;
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name . '=' . $value);
        }

        parent::tearDown();
    }

    public function testFormatsSummaryAndLinkifiesIssueKeys(): void
    {
        $message = (new IssueFormatter())->formatSummarySlackMessage(
            '2026 - 9',
            "Overview text.\n- Features: Delivered TEST-123."
        );

        self::assertStringStartsWith("*Release: 2026 - 9*\n\n*Overview*", $message);
        self::assertStringContainsString('<https://jira.example.test/browse/TEST-123|TEST-123>', $message);
    }

    public function testDevelopersMessageFiltersInvalidAndDuplicateSlackIds(): void
    {
        $message = (new IssueFormatter())->formatDevelopersCheckMessage(
            '2026 - 9',
            '2026 - 10',
            ['UVALID1', 'invalid', 'UVALID1', 'WVALID2']
        );

        self::assertStringContainsString('Release 2026 - 9; next 2026 - 10;', $message);
        self::assertSame(1, substr_count($message, '<@UVALID1>'));
        self::assertSame(1, substr_count($message, '<@WVALID2>'));
        self::assertStringNotContainsString('invalid', $message);
        self::assertLessThan(strpos($message, '<@WVALID2>'), strpos($message, '<@UVALID1>'));
    }

    public function testReportersMessageIncludesConfiguredExtraUsers(): void
    {
        $this->setEnv('SLACK_REPORTERS_EXTRA_USER_IDS', 'UEXTRA1');

        $message = (new IssueFormatter())->formatReportersCheckMessage('R1', 'R2', ['UREPORTER1']);

        self::assertStringContainsString('<@UREPORTER1>', $message);
        self::assertStringContainsString('<@UEXTRA1>', $message);
    }

    public function testDeveloperSeparatorIsFollowedByBlankLine(): void
    {
        $this->setEnv('SLACK_DEVELOPERS_CHECK_TEXT', '---\\n*Dear Team*');

        self::assertSame(
            "---\n\n*Dear Team*",
            (new IssueFormatter())->formatDevelopersCheckMessage()
        );
    }

    private function setEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->originalEnv)) {
            $this->originalEnv[$name] = getenv($name);
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }
}
