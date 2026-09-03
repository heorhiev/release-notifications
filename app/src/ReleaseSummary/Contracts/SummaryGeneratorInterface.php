<?php

declare(strict_types=1);

namespace App\ReleaseSummary\Contracts;

use App\ReleaseSummary\DTO\ReleaseIssue;
use App\ReleaseSummary\DTO\SummaryResult;

interface SummaryGeneratorInterface
{
    /**
     * @param array<int, ReleaseIssue> $issues
     */
    public function generate(string $release, array $issues): SummaryResult;

    public function mode(): string;
}

