<?php

declare(strict_types=1);

namespace App\ReleaseSummary\DTO;

final class ReleaseIssue
{
    /**
     * @param array<int, string> $labels
     * @param array<int, string> $components
     * @param array<int, array{key:string,summary:string,url:string,status:string,issueType:string}> $subTasks
     */
    public function __construct(
        public readonly string $key,
        public readonly string $summary,
        public readonly string $url,
        public readonly string $issueType,
        public readonly string $status,
        public readonly ?string $assignee,
        public readonly ?string $description,
        public readonly array $labels,
        public readonly array $components,
        public readonly ?string $parentKey = null,
        public readonly ?string $parentSummary = null,
        public readonly array $subTasks = [],
        public readonly ?string $containerParentKey = null,
        public readonly ?string $containerParentSummary = null,
        public readonly ?string $containerParentUrl = null
    ) {
    }
}
