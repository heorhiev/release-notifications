<?php

declare(strict_types=1);

namespace App\ReleaseDepartments\DTO;

use App\ReleaseSummary\DTO\ReleaseIssue;

final class DepartmentGroup
{
    /**
     * @param array<int, ReleaseIssue> $issues
     */
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly array $issues
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'title' => $this->title,
            'count' => count($this->issues),
            'issues' => array_map(
                static fn (ReleaseIssue $issue): array => [
                    'key' => $issue->key,
                    'summary' => $issue->summary,
                    'issue_type' => $issue->issueType,
                    'status' => $issue->status,
                    'assignee' => $issue->assignee,
                ],
                $this->issues
            ),
        ];
    }
}

