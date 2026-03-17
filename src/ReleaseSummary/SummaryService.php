<?php

declare(strict_types=1);

namespace App\ReleaseSummary;

use App\Env;
use App\Logger;
use App\ReleaseSummary\Contracts\SummaryGeneratorInterface;
use App\ReleaseSummary\DTO\SummaryResult;
use App\ReleaseSummary\Gemini\GeminiSummaryGenerator;
use App\ReleaseSummary\OpenAI\OpenAiSummaryGenerator;
use App\ReleaseSummary\RuleBased\RuleBasedSummaryGenerator;
use App\ReleaseSummary\Support\ReleaseIssueMapper;

final class SummaryService
{
    private ReleaseIssueMapper $issueMapper;

    /**
     * @var array<string, SummaryGeneratorInterface>
     */
    private array $generators;

    /**
     * @param array<int, SummaryGeneratorInterface>|null $generators
     */
    public function __construct(?ReleaseIssueMapper $issueMapper = null, ?array $generators = null)
    {
        $this->issueMapper = $issueMapper ?? new ReleaseIssueMapper();

        $defaultGenerators = [new RuleBasedSummaryGenerator()];
        if (Env::get('OPENAI_API_KEY') !== null) {
            $defaultGenerators[] = new OpenAiSummaryGenerator();
        }
        if (Env::get('GEMINI_API_KEY') !== null) {
            $defaultGenerators[] = new GeminiSummaryGenerator();
        }

        $this->generators = [];
        foreach ($generators ?? $defaultGenerators as $generator) {
            $this->generators[$generator->mode()] = $generator;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     */
    public function generate(string $release, array $issues, ?string $mode = 'rule'): SummaryResult
    {
        $selectedMode = strtolower(trim((string) ($mode ?? 'rule')));
        $generator = $this->generators[$selectedMode] ?? null;
        $mappedIssues = $this->issueMapper->mapMany($issues);

        if (!$generator instanceof SummaryGeneratorInterface) {
            if (in_array($selectedMode, ['openai', 'gemini'], true)) {
                Logger::error(sprintf('%s summary requested but not configured, using rule fallback', ucfirst($selectedMode)), [
                    'release' => $release,
                ]);

                return $this->buildRuleFallback(
                    $release,
                    $mappedIssues,
                    $selectedMode,
                    sprintf('%s summary is not configured', ucfirst($selectedMode))
                );
            }

            throw new \InvalidArgumentException(sprintf('Unsupported summary mode: %s', $mode));
        }

        if (in_array($selectedMode, ['openai', 'gemini'], true)) {
            try {
                return $generator->generate($release, $mappedIssues);
            } catch (\Throwable $exception) {
                Logger::error(sprintf('%s summary failed, using rule fallback', ucfirst($selectedMode)), [
                    'release' => $release,
                    'error' => $exception->getMessage(),
                ]);

                return $this->buildRuleFallback(
                    $release,
                    $mappedIssues,
                    $selectedMode,
                    $exception->getMessage(),
                    $exception
                );
            }
        }

        return $generator->generate($release, $mappedIssues);
    }

    /**
     * @param array<int, \App\ReleaseSummary\DTO\ReleaseIssue> $mappedIssues
     */
    private function buildRuleFallback(
        string $release,
        array $mappedIssues,
        string $requestedMode,
        string $reason,
        ?\Throwable $exception = null
    ): SummaryResult {
        $fallbackGenerator = $this->generators['rule'] ?? null;
        if (!$fallbackGenerator instanceof SummaryGeneratorInterface) {
            if ($exception instanceof \Throwable) {
                throw $exception;
            }

            throw new \RuntimeException(sprintf('%s summary is not configured.', ucfirst($requestedMode)));
        }

        $fallback = $fallbackGenerator->generate($release, $mappedIssues);

        return new SummaryResult(
            mode: $fallback->mode,
            text: $fallback->text,
            bullets: $fallback->bullets,
            meta: array_merge($fallback->meta, [
                'requested_mode' => $requestedMode,
                'fallback_used' => true,
                'fallback_reason' => $reason,
                'provider' => 'rule',
            ]),
            rawOutput: [
                'fallback' => true,
                'fallback_reason' => $reason,
                'fallback_summary' => $fallback->rawOutput,
            ]
        );
    }
}
