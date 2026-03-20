<?php

declare(strict_types=1);

namespace App\ReleaseSummary\Support;

use App\Env;
use App\ReleaseSummary\DTO\ReleaseIssue;

final class ReleaseIssueMapper
{
    private string $jiraBrowseBaseUrl;

    public function __construct()
    {
        $jiraBaseUrl = rtrim(
            Env::get('JIRA_BASE_URL', 'https://linksmanagement.atlassian.net') ?? 'https://linksmanagement.atlassian.net',
            '/'
        );
        $this->jiraBrowseBaseUrl = $jiraBaseUrl . '/browse/';
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, ReleaseIssue>
     */
    public function mapMany(array $issues): array
    {
        return array_map(fn (array $issue): ReleaseIssue => $this->mapOne($issue), $issues);
    }

    /**
     * @param array<string, mixed> $issue
     */
    public function mapOne(array $issue): ReleaseIssue
    {
        $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];

        return new ReleaseIssue(
            key: trim((string) ($issue['key'] ?? 'UNKNOWN')),
            summary: trim((string) ($fields['summary'] ?? '')),
            url: $this->buildIssueUrl((string) ($issue['key'] ?? 'UNKNOWN')),
            issueType: $this->nestedField($fields, ['issuetype', 'name']) ?? 'Unknown',
            status: $this->nestedField($fields, ['status', 'name']) ?? 'Unknown',
            assignee: $this->nestedField($fields, ['assignee', 'displayName']),
            description: $this->extractDescriptionText($fields['description'] ?? null),
            labels: $this->stringList($fields['labels'] ?? []),
            components: $this->extractComponentNames($fields['components'] ?? []),
            parentKey: $this->nestedField($fields, ['parent', 'key']),
            parentSummary: $this->nestedField($fields, ['parent', 'fields', 'summary'])
        );
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = trim($item);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function extractComponentNames(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $result[] = $name;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $path
     */
    private function nestedField(array $source, array $path): ?string
    {
        $current = $source;

        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        if (!is_string($current)) {
            return null;
        }

        $value = trim($current);

        return $value === '' ? null : $value;
    }

    private function extractDescriptionText(mixed $description): ?string
    {
        if (is_string($description)) {
            $value = trim($description);
            return $value === '' ? null : $value;
        }

        if (!is_array($description)) {
            return null;
        }

        $chunks = [];
        $stack = [$description];

        while ($stack !== []) {
            $node = array_pop($stack);

            if (!is_array($node)) {
                continue;
            }

            if (isset($node['text']) && is_string($node['text'])) {
                $chunks[] = $node['text'];
            }

            $content = $node['content'] ?? null;
            if (is_array($content)) {
                foreach (array_reverse($content) as $child) {
                    $stack[] = $child;
                }
            }
        }

        $value = trim(preg_replace('/\s+/u', ' ', implode(' ', $chunks)) ?? '');

        return $value === '' ? null : $value;
    }

    private function buildIssueUrl(string $issueKey): string
    {
        $issueKey = trim($issueKey);

        return $issueKey === ''
            ? $this->jiraBrowseBaseUrl
            : $this->jiraBrowseBaseUrl . rawurlencode($issueKey);
    }
}
