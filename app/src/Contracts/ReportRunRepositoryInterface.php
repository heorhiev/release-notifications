<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\CreateReportRunData;

interface ReportRunRepositoryInterface
{
    /** @param array<int, array<string, mixed>> $issues */
    public function createRun(CreateReportRunData $runData, array $issues): int;
}
