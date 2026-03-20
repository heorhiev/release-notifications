<?php

declare(strict_types=1);

namespace App\ReleaseSummary\RuleBased;

use App\Env;
use App\ReleaseSummary\Contracts\SummaryGeneratorInterface;
use App\ReleaseSummary\DTO\ReleaseIssue;
use App\ReleaseSummary\DTO\SummaryResult;

final class RuleBasedSummaryGenerator implements SummaryGeneratorInterface
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
     * @param array<int, ReleaseIssue> $issues
     */
    public function generate(string $release, array $issues): SummaryResult
    {
        if ($issues === []) {
            return new SummaryResult(
                mode: $this->mode(),
                text: '- В этом релизе не найдено задач Jira.',
                bullets: ['В этом релизе не найдено задач Jira.'],
                meta: ['issues_count' => 0, 'provider' => 'rule'],
                rawOutput: ['provider' => 'rule', 'issues_count' => 0]
            );
        }

        $groups = $this->groupIssuesByParent($issues);
        $lines = [];

        foreach ($groups as $index => $group) {
            if ($index > 0) {
                $lines[] = '';
                $lines[] = '';
            }

            $lines[] = $group['title'];

            foreach ($group['items'] as $item) {
                $lines[] = '• ' . $item;
            }
        }

        return new SummaryResult(
            mode: $this->mode(),
            text: implode("\n", $lines),
            bullets: $lines,
            meta: [
                'provider' => 'rule',
                'issues_count' => count($issues),
                'group_count' => count($groups),
            ],
            rawOutput: [
                'groups' => $groups,
            ]
        );
    }

    public function mode(): string
    {
        return 'rule';
    }

    /**
     * @param array<int, ReleaseIssue> $issues
     * @return array<int, array{group_key:string,title:string,items:array<int, string>,issues_count:int}>
     */
    private function groupIssuesByParent(array $issues): array
    {
        $groups = [];

        foreach ($issues as $issue) {
            $groupKey = $this->resolveGroupKey($issue);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'group_key' => $groupKey,
                    'title' => $this->formatGroupTitle($issue),
                    'items' => [],
                    'issues_count' => 0,
                ];
            }

            $groups[$groupKey]['items'][] = $this->formatIssueLine($issue);
            $groups[$groupKey]['issues_count']++;
        }

        uasort(
            $groups,
            static function (array $left, array $right): int {
                if ($left['group_key'] === '__no_epic__') {
                    return 1;
                }

                if ($right['group_key'] === '__no_epic__') {
                    return -1;
                }

                return strcasecmp($left['title'], $right['title']);
            }
        );

        return array_values($groups);
    }

    private function resolveGroupKey(ReleaseIssue $issue): string
    {
        if ($issue->parentKey !== null && $issue->parentKey !== '') {
            return $issue->parentKey;
        }

        if (strcasecmp($issue->issueType, 'Epic') === 0) {
            return $issue->key;
        }

        return '__no_epic__';
    }

    private function formatGroupTitle(ReleaseIssue $issue): string
    {
        if ($issue->parentKey !== null && $issue->parentKey !== '') {
            return $this->formatLinkedGroupTitleLine(
                $issue->parentSummary ?: 'Epic без названия',
                $this->buildIssueUrl($issue->parentKey)
            );
        }

        if (strcasecmp($issue->issueType, 'Epic') === 0) {
            return $this->formatLinkedGroupTitleLine($issue->summary, $issue->url);
        }

        return '*Tasks Without Epic*';
    }

    private function formatIssueLine(ReleaseIssue $issue): string
    {
        return $this->formatIssueTitleLine($issue->summary, $issue->key, $issue->url);
    }

    private function formatLinkedGroupTitleLine(string $summary, string $url): string
    {
        $summary = trim($summary) !== '' ? trim($summary) : 'Без названия';

        return sprintf('*<%s|%s>*', trim($url), $summary);
    }

    private function formatIssueTitleLine(string $summary, string $key, string $url): string
    {
        $summary = trim($summary) !== '' ? trim($summary) : 'Без названия';

        return sprintf('%s (<%s|%s>)', $summary, trim($url), trim($key));
    }

    private function buildIssueUrl(string $issueKey): string
    {
        return $this->jiraBrowseBaseUrl . rawurlencode(trim($issueKey));
    }
}
