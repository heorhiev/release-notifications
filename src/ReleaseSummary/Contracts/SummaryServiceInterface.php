<?php

declare(strict_types=1);

namespace App\ReleaseSummary\Contracts;

use App\ReleaseSummary\DTO\SummaryResult;

interface SummaryServiceInterface
{
    /** @param array<int, array<string, mixed>> $issues */
    public function generate(string $release, array $issues): SummaryResult;
}
