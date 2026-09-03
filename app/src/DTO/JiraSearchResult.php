<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class JiraSearchResult
{
    /**
     * @param array<int, array<string, mixed>> $issues
     */
    public function __construct(
        public array $issues,
        public int $total,
        public string $jql,
    ) {
    }

    /** @return array{issues:array<int, array<string, mixed>>, total:int, jql:string} */
    public function toArray(): array
    {
        return [
            'issues' => $this->issues,
            'total' => $this->total,
            'jql' => $this->jql,
        ];
    }
}
