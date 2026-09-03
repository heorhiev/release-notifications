<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Model\EmployeeRole;
use App\Repository\EmployeeRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class EmployeeRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $this->pdo->exec(
            'CREATE TABLE employees (
                id BIGSERIAL PRIMARY KEY,
                jira_user_id VARCHAR(255),
                slack_user_id VARCHAR(255) NOT NULL UNIQUE,
                role SMALLINT NOT NULL DEFAULT 0,
                priority SMALLINT NOT NULL DEFAULT 0 CHECK (priority BETWEEN 0 AND 255)
            )'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS employees');
        }
    }

    public function testRoleListOrdersHigherPriorityFirstAndUsesSlackIdAsTieBreaker(): void
    {
        $this->insert('jira-low', 'ULOW', EmployeeRole::DEVELOPER, 0);
        $this->insert('jira-high-b', 'UHIGHB', EmployeeRole::DEVELOPER, 10);
        $this->insert('jira-high-a', 'UHIGHA', EmployeeRole::DEVELOPER, 10);
        $this->insert('jira-other', 'UOTHER', EmployeeRole::DEFAULT, 255);

        $result = (new EmployeeRepository($this->pdo))->findSlackUserIdsByRole(EmployeeRole::DEVELOPER);

        self::assertSame(['UHIGHA', 'UHIGHB', 'ULOW'], $result);
    }

    public function testReporterListTrimsInputDeduplicatesAndOrdersByPriority(): void
    {
        $this->insert('jira-zero', 'UZERO', EmployeeRole::DEFAULT, 0);
        $this->insert('jira-one', 'UONE', EmployeeRole::DEFAULT, 1);
        $this->insert('jira-ten', 'UTEN', EmployeeRole::DEFAULT, 10);

        $result = (new EmployeeRepository($this->pdo))->findSlackUserIdsByJiraUserIds([
            ' jira-zero ',
            'jira-one',
            'jira-ten',
            'jira-ten',
            '',
        ]);

        self::assertSame(['UTEN', 'UONE', 'UZERO'], $result);
    }

    private function insert(string $jiraUserId, string $slackUserId, int $role, int $priority): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO employees (jira_user_id, slack_user_id, role, priority)
             VALUES (:jira_user_id, :slack_user_id, :role, :priority)'
        );
        $statement->execute([
            'jira_user_id' => $jiraUserId,
            'slack_user_id' => $slackUserId,
            'role' => $role,
            'priority' => $priority,
        ]);
    }
}
