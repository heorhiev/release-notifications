<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class CreateReportRunData
{
    public function __construct(
        public string $releaseName,
        public int $issuesCount,
        public bool $includeDescription,
        public bool $dryRun,
        public bool $slackSent,
        public string $summaryText,
        public string $summaryMode,
        public ?string $summaryProvider,
        public ?string $summaryModel,
        public bool $summaryFallbackUsed,
        public mixed $summaryRawOutput,
        public string $messagePreview,
        public string $jiraJql,
        public ?string $releaseUrl,
    ) {
    }
}
