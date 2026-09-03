<?php

declare(strict_types=1);

namespace App\ReleaseSummary\DTO;

final readonly class ReleaseSubTask
{
    public function __construct(
        public string $key,
        public string $summary,
        public string $url,
        public string $status,
        public string $issueType,
    ) {
    }
}
