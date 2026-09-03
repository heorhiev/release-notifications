<?php

declare(strict_types=1);

namespace App\Support;

final class IssueFormatter
{
    private string $jiraBaseUrl;
    private string $jiraBrowseBaseUrl;
    private string $jiraProjectKey;

    public function __construct()
    {
        $jiraBaseUrl = rtrim(Env::require('JIRA_BASE_URL'), '/');
        $this->jiraBaseUrl = $jiraBaseUrl;
        $this->jiraBrowseBaseUrl = $jiraBaseUrl . '/browse/';
        $this->jiraProjectKey = trim(Env::require('JIRA_PROJECT_KEY'));
    }

    public function formatSummarySlackMessage(string $release, string $summaryText): string
    {
        $formattedSummary = $this->isPreformattedSlackSummary($summaryText)
            ? $this->normalizeSlackText($summaryText)
            : $this->formatSlackSummaryBlocks($summaryText);

        return sprintf("*Release: %s*\n\n%s", $release, $formattedSummary);
    }

    /**
     * @param array<int, string> $developerSlackUserIds
     */
    public function formatDevelopersCheckMessage(?string $releaseName = null, ?string $nextReleaseName = null, array $developerSlackUserIds = []): string
    {
        $checkText = $this->normalizeEnvMultilineText(Env::get('SLACK_DEVELOPERS_CHECK_TEXT', '') ?? '');
        if ($checkText === '') {
            return '';
        }

        return $this->applyReleasePlaceholders(
            $checkText,
            $releaseName,
            $nextReleaseName,
            $this->formatSlackMentions(implode(' ', $developerSlackUserIds))
        );
    }

    /**
     * @param array<int, string> $slackUserIds
     */
    public function formatReportersCheckMessage(?string $releaseName = null, ?string $nextReleaseName = null, array $slackUserIds = []): string
    {
        $checkText = $this->normalizeEnvMultilineText(Env::get('SLACK_REPORTERS_CHECK_TEXT', '') ?? '');
        if ($checkText === '') {
            return '';
        }

        $mentions = $this->formatSlackMentions(implode(' ', array_merge(
            $slackUserIds,
            $this->parseSlackUserIds(Env::get('SLACK_REPORTERS_EXTRA_USER_IDS', '') ?? '')
        )));
        $checkText = $this->applyReleasePlaceholders($checkText, $releaseName, $nextReleaseName, '', $mentions);

        if (str_contains($checkText, $mentions) || $mentions === '') {
            return trim($checkText);
        }

        return trim(implode("\n\n", array_filter([$checkText, $mentions], static fn (string $part): bool => $part !== '')));
    }

    private function applyReleasePlaceholders(string $text, ?string $releaseName, ?string $nextReleaseName, string $developers = '', string $reporters = ''): string
    {
        $releaseName = trim((string) $releaseName);
        $nextReleaseName = trim((string) $nextReleaseName);

        return strtr($text, [
            '{release}' => $releaseName,
            '{next_release}' => $nextReleaseName,
            '{url_user_tasks}' => $this->buildUserTasksUrl($releaseName),
            '{developers}' => $developers,
            '{reporters}' => $reporters,
        ]);
    }

    private function buildUserTasksUrl(string $releaseName): string
    {
        $projectKey = $this->jiraProjectKey;
        $jql = sprintf(
            "project = \"%s\"\nAND fixversion = \"%s\"\nAND reporter = currentUser()\nORDER BY key DESC, Rank DESC",
            $projectKey,
            $releaseName
        );

        return sprintf(
            '%s/jira/software/c/projects/%s/list?jql=%s',
            $this->jiraBaseUrl,
            rawurlencode($projectKey),
            rawurlencode($jql)
        );
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
        $text = trim(str_replace(['\\r\\n', '\\n', '\\r'], "\n", $text));

        return preg_replace('/^---\n(?!\n)/', "---\n\n", $text) ?? $text;
    }

    private function formatSlackMentions(string $userIds): string
    {
        $ids = $this->parseSlackUserIds($userIds);
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

        return implode(' ', array_values(array_unique($mentions)));
    }

    /**
     * @return array<int, string>
     */
    private function parseSlackUserIds(string $userIds): array
    {
        $ids = preg_split('/[\s,;]+/', trim($userIds)) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $id): string => trim($id), $ids),
            static fn (string $id): bool => $id !== ''
        ));
    }

    private function isPreformattedSlackSummary(string $summaryText): bool
    {
        $summaryText = $this->normalizeSlackText($summaryText);

        return preg_match('/<https?:\/\/[^>|]+\\|[^>]+>/u', $summaryText) === 1;
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
