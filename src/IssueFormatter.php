<?php

declare(strict_types=1);

namespace App;

final class IssueFormatter
{
    private string $jiraBrowseBaseUrl;

    public function __construct()
    {
        $jiraBaseUrl = rtrim(Env::get('JIRA_BASE_URL', 'https://linksmanagement.atlassian.net') ?? 'https://linksmanagement.atlassian.net', '/');
        $this->jiraBrowseBaseUrl = $jiraBaseUrl . '/browse/';
    }

    public function formatSummarySlackMessage(string $release, string $summaryText): string
    {
        $formattedSummary = $this->isPreformattedSlackSummary($summaryText)
            ? $this->normalizeSlackText($summaryText)
            : $this->formatSlackSummaryBlocks($summaryText);

        return sprintf("*Release: %s*\n\n%s", $release, $formattedSummary);
    }

    public function formatReleaseCheckMessage(?string $releaseName = null): string
    {
        $checkText = $this->normalizeEnvMultilineText(Env::get('SLACK_RELEASE_CHECK_TEXT', '') ?? '');
        $checkText = $this->applyReleaseCheckPlaceholders($checkText, $releaseName);
        $mentions = $this->formatSlackMentions(Env::get('SLACK_MENTION_USER_IDS', '') ?? '');

        return trim(implode("\n\n", array_filter([$checkText, $mentions], static fn (string $part): bool => $part !== '')));
    }

    private function applyReleaseCheckPlaceholders(string $text, ?string $releaseName): string
    {
        $releaseName = trim((string) $releaseName);

        if ($releaseName === '') {
            return $text;
        }

        return strtr($text, [
            '{release}' => $releaseName,
        ]);
    }

    private function formatSlackSummaryBody(string $summaryText): string
    {
        $summaryText = $this->normalizeSlackText($summaryText);
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

    private function normalizeEnvMultilineText(string $text): string
    {
        return trim(str_replace(['\\r\\n', '\\n', '\\r'], "\n", $text));
    }

    private function formatSlackMentions(string $userIds): string
    {
        $ids = preg_split('/[\s,;]+/', trim($userIds)) ?: [];
        $mentions = [];

        foreach ($ids as $id) {
            $id = trim($id);
            if ($id === '') {
                continue;
            }

            if (preg_match('/^[UW][A-Z0-9]+$/', $id) !== 1) {
                continue;
            }

            $mentions[] = sprintf('<@%s>', $id);
        }

        $mentions = array_values(array_unique($mentions));
        shuffle($mentions);

        return implode(' ', $mentions);
    }

    private function isPreformattedSlackSummary(string $summaryText): bool
    {
        $summaryText = $this->normalizeSlackText($summaryText);

        return str_contains($summaryText, '(<http') || str_contains($summaryText, '(<https');
    }

    private function normalizeSlackText(string $text): string
    {
        return trim(str_replace("\r\n", "\n", $text));
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
                $paragraphs[] = implode("\n", $buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $paragraphs[] = implode("\n", $buffer);
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
