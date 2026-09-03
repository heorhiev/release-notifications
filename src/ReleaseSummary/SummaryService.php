<?php

declare(strict_types=1);

namespace App\ReleaseSummary;

use App\ReleaseSummary\Contracts\SummaryServiceInterface;
use App\ReleaseSummary\DTO\SummaryResult;
use App\ReleaseSummary\RuleBased\RuleBasedSummaryGenerator;
use App\ReleaseSummary\Support\ReleaseIssueMapper;

final class SummaryService implements SummaryServiceInterface
{
    private ReleaseIssueMapper $issueMapper;
    private RuleBasedSummaryGenerator $generator;

    public function __construct(?ReleaseIssueMapper $issueMapper = null, ?RuleBasedSummaryGenerator $generator = null)
    {
        $this->issueMapper = $issueMapper ?? new ReleaseIssueMapper();
        $this->generator = $generator ?? new RuleBasedSummaryGenerator();
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     */
    public function generate(string $release, array $issues): SummaryResult
    {
        $mappedIssues = $this->issueMapper->mapMany($issues);

        return $this->generator->generate($release, $mappedIssues);
    }
}
