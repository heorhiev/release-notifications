<?php

declare(strict_types=1);

namespace App\Repository;

use App\Contracts\EpicRepositoryInterface;
use App\Infrastructure\Database;
use App\Model\Epic;
use PDO;

final class EpicRepository implements EpicRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function findByJiraKeys(array $jiraKeys): array
    {
        $jiraKeys = array_values(array_unique(array_filter(
            array_map(static fn (string $key): string => trim($key), $jiraKeys),
            static fn (string $key): bool => $key !== ''
        )));

        if ($jiraKeys === []) {
            return [];
        }

        $placeholders = [];
        foreach ($jiraKeys as $index => $_jiraKey) {
            $placeholders[] = ':jira_key_' . $index;
        }

        $statement = $this->pdo->prepare(sprintf(
            'SELECT id, jira_key, priority FROM epics WHERE jira_key IN (%s)',
            implode(', ', $placeholders)
        ));

        foreach ($jiraKeys as $index => $jiraKey) {
            $statement->bindValue(':jira_key_' . $index, $jiraKey);
        }

        $statement->execute();

        return array_map(
            static fn (array $row): Epic => new Epic(
                id: (int) $row['id'],
                jiraKey: (string) $row['jira_key'],
                priority: (int) $row['priority'],
            ),
            $statement->fetchAll()
        );
    }
}
