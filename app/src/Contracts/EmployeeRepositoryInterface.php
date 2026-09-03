<?php

declare(strict_types=1);

namespace App\Contracts;

interface EmployeeRepositoryInterface
{
    /** @return array<int, string> */
    public function findSlackUserIdsByJiraUserIds(array $jiraUserIds): array;

    /** @return array<int, string> */
    public function findSlackUserIdsByRole(int $role): array;
}
