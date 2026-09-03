<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\EpicRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class EpicRepositoryTest extends TestCase
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
            'CREATE TABLE epics (
                id BIGSERIAL PRIMARY KEY,
                jira_key VARCHAR(255) NOT NULL UNIQUE,
                priority SMALLINT NOT NULL DEFAULT 1
            )'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS epics');
        }
    }

    public function testFindsOnlyRequestedEpicsAndMapsModel(): void
    {
        $this->pdo->exec("INSERT INTO epics (jira_key, priority) VALUES ('PROJECT-1', 0), ('PROJECT-2', 5)");

        $result = (new EpicRepository($this->pdo))->findByJiraKeys([' PROJECT-2 ', 'PROJECT-2', 'UNKNOWN']);

        self::assertCount(1, $result);
        self::assertSame('PROJECT-2', $result[0]->jiraKey);
        self::assertSame(5, $result[0]->priority);
    }
}
