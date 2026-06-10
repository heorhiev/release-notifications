<?php

declare(strict_types=1);

namespace App;

use PDO;

final class EmployeeRepository
{
    public const ROLE_DEFAULT = EmployeeRole::DEFAULT;
    public const ROLE_DEVELOPER = EmployeeRole::DEVELOPER;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * @param array<int, string> $jiraUserIds
     * @return array<int, string>
     */
    public function findSlackUserIdsByJiraUserIds(array $jiraUserIds): array
    {
        $jiraUserIds = array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => trim($id), $jiraUserIds),
            static fn (string $id): bool => $id !== ''
        )));

        if ($jiraUserIds === []) {
            return [];
        }

        $placeholders = [];
        $parameters = [];

        foreach ($jiraUserIds as $index => $jiraUserId) {
            $placeholder = ':jira_user_id_' . $index;
            $placeholders[] = $placeholder;
            $parameters[$placeholder] = $jiraUserId;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT slack_user_id
                FROM employees
                WHERE jira_user_id IN (%s)
                ORDER BY slack_user_id ASC',
                implode(', ', $placeholders)
            )
        );

        foreach ($parameters as $placeholder => $jiraUserId) {
            $statement->bindValue($placeholder, $jiraUserId);
        }

        $statement->execute();

        $slackUserIds = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $slackUserId = trim((string) ($row['slack_user_id'] ?? ''));
            if ($slackUserId !== '') {
                $slackUserIds[] = $slackUserId;
            }
        }

        return array_values(array_unique($slackUserIds));
    }

    /**
     * @return array<int, string>
     */
    public function findSlackUserIdsByRole(int $role): array
    {
        $statement = $this->pdo->prepare(
            'SELECT slack_user_id
            FROM employees
            WHERE role = :role
            ORDER BY slack_user_id ASC'
        );
        $statement->bindValue(':role', $role, PDO::PARAM_INT);
        $statement->execute();

        $slackUserIds = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $slackUserId = trim((string) ($row['slack_user_id'] ?? ''));
            if ($slackUserId !== '') {
                $slackUserIds[] = $slackUserId;
            }
        }

        return array_values(array_unique($slackUserIds));
    }
}
