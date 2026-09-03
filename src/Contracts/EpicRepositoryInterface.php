<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Model\Epic;

interface EpicRepositoryInterface
{
    /**
     * @param array<int, string> $jiraKeys
     * @return array<int, Epic>
     */
    public function findByJiraKeys(array $jiraKeys): array;
}
