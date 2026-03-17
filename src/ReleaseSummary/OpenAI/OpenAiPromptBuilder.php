<?php

declare(strict_types=1);

namespace App\ReleaseSummary\OpenAI;

use App\Env;
use App\ReleaseSummary\DTO\ReleaseIssue;

final class OpenAiPromptBuilder
{
    private int $maxIssues;
    private int $maxInputChars;
    private int $maxSummaryChars;
    private int $maxDescriptionChars;

    public function __construct()
    {
        $this->maxIssues = (int) (Env::get('OPENAI_SUMMARY_MAX_ISSUES', '100') ?? '100');
        $this->maxInputChars = (int) (Env::get('OPENAI_SUMMARY_MAX_INPUT_CHARS', '60000') ?? '60000');
        $this->maxSummaryChars = (int) (Env::get('OPENAI_SUMMARY_MAX_SUMMARY_CHARS', '400') ?? '400');
        $this->maxDescriptionChars = (int) (Env::get('OPENAI_SUMMARY_MAX_DESCRIPTION_CHARS', '700') ?? '700');
    }

    public function instructions(): string
    {
        $path = __DIR__ . '/Prompts/system.php';
        $prompt = require $path;

        if (!is_string($prompt) || trim($prompt) === '') {
            throw new \RuntimeException('Invalid OpenAI system prompt template');
        }

        return $prompt;
    }

    /**
     * @param array<int, ReleaseIssue> $issues
     * @return array{input:string, meta:array<string,mixed>}
     */
    public function build(string $release, array $issues): array
    {
        $issueLines = [];
        $includedIssues = 0;
        $truncated = false;
        $promptPath = __DIR__ . '/Prompts/input.php';

        foreach ($issues as $index => $issue) {
            if ($index >= $this->maxIssues) {
                $truncated = true;
                break;
            }

            $line = sprintf(
                '- %s | type=%s | status=%s | assignee=%s | labels=%s | components=%s | summary=%s | description=%s',
                $issue->key,
                $issue->issueType,
                $issue->status,
                $issue->assignee ?? 'Unassigned',
                $issue->labels !== [] ? implode(',', $issue->labels) : 'none',
                $issue->components !== [] ? implode(',', $issue->components) : 'none',
                $this->trimText($issue->summary, $this->maxSummaryChars),
                $this->trimText($issue->description ?? 'No description', $this->maxDescriptionChars)
            );

            $issueLines[] = $line;
            $issuesCount = count($issues);
            $promptCandidate = require $promptPath;

            if (!is_string($promptCandidate) || trim($promptCandidate) === '') {
                throw new \RuntimeException('Invalid OpenAI input prompt template');
            }

            if (strlen($promptCandidate) > $this->maxInputChars) {
                array_pop($issueLines);
                $truncated = true;
                break;
            }

            $includedIssues++;
        }

        $issuesCount = count($issues);
        $prompt = require $promptPath;

        if (!is_string($prompt) || trim($prompt) === '') {
            throw new \RuntimeException('Invalid OpenAI input prompt template');
        }

        return [
            'input' => $prompt,
            'meta' => [
                'total_issues_count' => $issuesCount,
                'included_issues_count' => $includedIssues,
                'omitted_issues_count' => max(0, $issuesCount - $includedIssues),
                'input_truncated' => $truncated || $includedIssues < $issuesCount,
                'max_issues' => $this->maxIssues,
                'max_input_chars' => $this->maxInputChars,
                'input_chars' => strlen($prompt),
            ],
        ];
    }

    private function trimText(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit - 3) . '...';
    }
}
