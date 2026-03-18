<?php

declare(strict_types=1);

namespace App;

use App\ReleaseDepartments\DTO\DepartmentGroup;

final class IssueFormatter
{
    private string $jiraBrowseBaseUrl;

    public function __construct()
    {
        $jiraBaseUrl = rtrim(Env::get('JIRA_BASE_URL', 'https://linksmanagement.atlassian.net') ?? 'https://linksmanagement.atlassian.net', '/');
        $this->jiraBrowseBaseUrl = $jiraBaseUrl . '/browse/';
    }

    /**
     * @param array<int, DepartmentGroup> $departmentGroups
     */
    public function formatSlackMessage(
        string $release,
        string $summaryText,
        ?string $departmentGroupsText,
        string $detailsText
    ): string
    {
        $parts = [
            sprintf("*Release %s Summary*\n\n%s", $release, $this->formatSlackSummaryBody($summaryText)),
        ];

        if ($departmentGroupsText !== null && trim($departmentGroupsText) !== '') {
            $parts[] = "*Department Groups*\n" . $departmentGroupsText;
        }

        $parts[] = "*Issue Details*\n" . $detailsText;

        return implode("\n\n", $parts);
    }

    public function formatSummarySlackMessage(string $release, string $summaryText): string
    {
        return sprintf("*Release %s Summary*\n\n%s", $release, $this->formatSlackSummaryBlocks($summaryText));
    }

    /**
     * @param array<int, DepartmentGroup> $departmentGroups
     */
    public function formatDepartmentGroups(array $departmentGroups): string
    {
        if ($departmentGroups === []) {
            return 'No department grouping available.';
        }

        $lines = [];

        foreach ($departmentGroups as $group) {
            $lines[] = sprintf('*%s* (%d issue(s))', $group->title, count($group->issues));

            foreach ($group->issues as $issue) {
                $lines[] = sprintf('- %s - %s', $this->formatIssueKeyLink($issue->key), $issue->summary);
            }

            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $issue
     */
    public function formatIssue(array $issue, bool $includeDescription): string
    {
        $key = (string) ($issue['key'] ?? 'UNKNOWN');
        $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];

        $summary = trim((string) ($fields['summary'] ?? 'No summary'));
        $status = $this->extractNestedValue($fields, ['status', 'name'], 'Unknown');
        $issueType = $this->extractNestedValue($fields, ['issuetype', 'name'], 'Task');
        $assignee = $this->extractNestedValue($fields, ['assignee', 'displayName'], 'Unassigned');

        $parts = [
            sprintf('- *%s* [%s | %s] - %s', $this->formatIssueKeyLink($key), $issueType, $status, $summary),
            sprintf('  Assignee: %s', $assignee),
        ];

        if ($includeDescription) {
            $description = $this->extractDescription($fields['description'] ?? null);
            $parts[] = '  Description: ' . ($description !== '' ? $description : 'No description');
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     */
    public function formatReleaseReport(string $release, array $issues, bool $includeDescription): string
    {
        $header = sprintf('*Release %s*\nFound %d issue(s) in Jira.', $release, count($issues));

        if ($issues === []) {
            return $header . "\nNo issues found for this release.";
        }

        $lines = [$header, ''];
        foreach ($issues as $issue) {
            $lines[] = $this->formatIssue($issue, $includeDescription);
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $path
     */
    private function extractNestedValue(array $source, array $path, string $default): string
    {
        $current = $source;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return is_string($current) && trim($current) !== '' ? $current : $default;
    }

    /**
     * Jira Cloud rich text can be large and nested. For MVP we extract readable plain text only.
     *
     * @param mixed $description
     */
    private function extractDescription(mixed $description): string
    {
        if (is_string($description)) {
            return $this->normalizeWhitespace($description);
        }

        if (!is_array($description)) {
            return '';
        }

        $chunks = [];
        $this->walkDescriptionTree($description, $chunks);

        return $this->normalizeWhitespace(implode(' ', $chunks));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string> $chunks
     */
    private function walkDescriptionTree(array $node, array &$chunks): void
    {
        if (isset($node['text']) && is_string($node['text'])) {
            $chunks[] = $node['text'];
        }

        $content = $node['content'] ?? null;
        if (!is_array($content)) {
            return;
        }

        foreach ($content as $child) {
            if (is_array($child)) {
                $this->walkDescriptionTree($child, $chunks);
            }
        }
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        if (strlen($text) <= 300) {
            return $text;
        }

        return substr($text, 0, 297) . '...';
    }

    private function formatSlackSummaryBody(string $summaryText): string
    {
        $summaryText = trim(str_replace("\r\n", "\n", $summaryText));
        if ($summaryText === '') {
            return '';
        }

        $blocks = preg_split('/\n-\s+/u', $summaryText) ?: [];
        $normalizedBlocks = [];

        foreach ($blocks as $index => $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            if ($index > 0 || str_starts_with($summaryText, '- ')) {
                $block = '- ' . ltrim($block, "- \t\n\r\0\x0B");
            }

            $normalizedBlocks[] = $block;
        }

        return implode("\n\n", $normalizedBlocks);
    }

    private function formatSlackSummaryBlocks(string $summaryText): string
    {
        $body = $this->formatSlackSummaryBody($summaryText);
        if ($body === '') {
            return '';
        }

        $blocks = preg_split('/\n\n+/u', $body) ?: [];
        $formatted = [];

        foreach ($blocks as $index => $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $line = ltrim($block, "- \t\n\r\0\x0B");

            if ($index === 0) {
                $formatted[] = "*Overview*\n" . $this->formatSlackBlockParagraphs($line);
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $title = trim($parts[0]);
                $content = $this->formatSlackBlockParagraphs(trim($parts[1]));
                $formatted[] = sprintf("*%s*\n%s", $title, $content);
                continue;
            }

            $formatted[] = $line;
        }

        return $this->linkifyIssueKeys(implode("\n\n\n", $formatted));
    }

    private function formatSlackBlockParagraphs(string $content): string
    {
        $content = trim(str_replace("\r\n", "\n", $content));
        if ($content === '') {
            return '';
        }

        $content = preg_replace('/\s+Детали:/u', "\n\nДетали:", $content) ?? $content;
        $content = preg_replace('/\s+Примеры задач:/u', "\n\nПримеры задач:", $content) ?? $content;
        $content = preg_replace('/\n{3,}/u', "\n\n", $content) ?? $content;

        $paragraphs = preg_split('/\n{2,}/u', $content) ?: [];
        $formattedParagraphs = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (str_starts_with($paragraph, 'Детали:')) {
                $detailsText = trim(substr($paragraph, strlen('Детали:')));
                $detailsParagraphs = $this->splitIntoParagraphsBySentences($detailsText, 2);

                if ($detailsParagraphs !== []) {
                    foreach ($detailsParagraphs as $detailsParagraph) {
                        $formattedParagraphs[] = $detailsParagraph;
                    }
                }

                continue;
            }

            if (str_starts_with($paragraph, 'Примеры задач:')) {
                $formattedParagraphs[] = $paragraph;
                continue;
            }

            foreach ($this->splitIntoParagraphsBySentences($paragraph, 2) as $splitParagraph) {
                $formattedParagraphs[] = $splitParagraph;
            }
        }

        return trim(implode("\n\n", $formattedParagraphs));
    }

    /**
     * @return array<int, string>
     */
    private function splitIntoParagraphsBySentences(string $text, int $sentencesPerParagraph): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        $sentences = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $sentences[] = $part;
            }
        }

        if ($sentences === []) {
            return [$text];
        }

        $paragraphs = [];
        $buffer = [];

        foreach ($sentences as $sentence) {
            $buffer[] = $sentence;

            if (count($buffer) >= $sentencesPerParagraph) {
                $paragraphs[] = implode(' ', $buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $paragraphs[] = implode(' ', $buffer);
        }

        return $paragraphs;
    }

    private function formatIssueKeyLink(string $issueKey): string
    {
        $issueKey = trim($issueKey);
        if ($issueKey === '') {
            return $issueKey;
        }

        return sprintf('<%s%s|%s>', $this->jiraBrowseBaseUrl, rawurlencode($issueKey), $issueKey);
    }

    private function linkifyIssueKeys(string $text): string
    {
        return preg_replace_callback(
            '/(?<![A-Z0-9])([A-Z][A-Z0-9]+-\d+)(?!\|)/',
            fn (array $matches): string => $this->formatIssueKeyLink($matches[1]),
            $text
        ) ?? $text;
    }
}
