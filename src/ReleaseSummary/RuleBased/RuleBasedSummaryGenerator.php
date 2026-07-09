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
                $lines[] = str_starts_with($item, '    ')
                    ? $item
                    : '• ' . $item;
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
        $issueKeys = [];
        foreach ($issues as $issue) {
            $issueKeys[$issue->key] = true;
        }
        $nestedSubTaskKeys = $this->collectNestedSubTaskKeys($issues);

        foreach ($issues as $issue) {
            if (isset($nestedSubTaskKeys[$issue->key])) {
                continue;
            }

            $groupKey = $this->resolveGroupKey($issue);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'group_key' => $groupKey,
                    'title' => $this->formatGroupTitle($issue),
                    'items' => [],
                    'entries' => [],
                    'containers' => [],
                    'issues_count' => 0,
                ];
            }

            if ($issue->containerParentKey !== null && $issue->containerParentKey !== '') {
                if (!isset($groups[$groupKey]['containers'][$issue->containerParentKey])) {
                    $groups[$groupKey]['entries'][] = [
                        'type' => 'container',
                        'key' => $issue->containerParentKey,
                    ];
                    $groups[$groupKey]['containers'][$issue->containerParentKey] = [
                        'title' => $this->formatLinkedIssueTitleLine(
                            $issue->containerParentSummary ?: 'Без названия',
                            $issue->containerParentKey,
                            $issue->containerParentUrl ?: $this->buildIssueUrl($issue->containerParentKey)
                        ),
                        'items' => [],
                    ];
                }

                $groups[$groupKey]['containers'][$issue->containerParentKey]['items'][] = '    ◦ ' . $this->formatIssueTitleLine(
                    $issue->summary,
                    $issue->key,
                    $issue->url
                );
            } else {
                $groups[$groupKey]['entries'][] = [
                    'type' => 'lines',
                    'lines' => $this->formatIssueLines($issue, $issueKeys),
                ];
            }
            $groups[$groupKey]['issues_count']++;
        }

        foreach ($groups as $groupKey => $group) {
            $items = [];
            foreach ($group['entries'] as $entry) {
                if (($entry['type'] ?? '') === 'container') {
                    $containerKey = (string) ($entry['key'] ?? '');
                    $container = $group['containers'][$containerKey] ?? null;
                    if (!is_array($container)) {
                        continue;
                    }

                    $items[] = $container['title'];
                    array_push($items, ...$container['items']);
                    continue;
                }

                if (($entry['type'] ?? '') === 'lines') {
                    array_push($items, ...($entry['lines'] ?? []));
                }
            }

            $groups[$groupKey]['items'] = $items;
            unset($groups[$groupKey]['entries'], $groups[$groupKey]['containers']);
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

                if (strcasecmp(self::extractGroupTitleLabel($left['title']), 'Bugs') === 0) {
                    return 1;
                }

                if (strcasecmp(self::extractGroupTitleLabel($right['title']), 'Bugs') === 0) {
                    return -1;
                }

                return strcasecmp($left['title'], $right['title']);
            }
        );

        return array_values($groups);
    }

    /**
     * @param array<int, ReleaseIssue> $issues
     * @return array<string, bool>
     */
    private function collectNestedSubTaskKeys(array $issues): array
    {
        $issueKeys = [];
        foreach ($issues as $issue) {
            $issueKeys[$issue->key] = true;
        }

        $subTaskKeys = [];
        foreach ($issues as $issue) {
            if (strcasecmp($issue->issueType, 'Epic') === 0) {
                continue;
            }

            foreach ($issue->subTasks as $subTask) {
                $key = $subTask['key'] ?? '';
                if ($key !== '' && isset($issueKeys[$key])) {
                    $subTaskKeys[$key] = true;
                }
            }
        }

        return $subTaskKeys;
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

    /**
     * @return array<int, string>
     */
    private function formatIssueLines(ReleaseIssue $issue, array $releaseIssueKeys): array
    {
        if (strcasecmp($issue->issueType, 'Epic') === 0) {
            return [$this->formatIssueTitleLine($issue->summary, $issue->key, $issue->url)];
        }

        $subTasks = array_values(array_filter(
            $issue->subTasks,
            static fn (array $subTask): bool => isset($releaseIssueKeys[$subTask['key'] ?? ''])
        ));

        $lines = $subTasks === []
            ? [$this->formatIssueTitleLine($issue->summary, $issue->key, $issue->url)]
            : [$this->formatLinkedIssueTitleLine($issue->summary, $issue->key, $issue->url)];

        foreach ($subTasks as $subTask) {
            $lines[] = '    ◦ ' . $this->formatIssueTitleLine(
                $subTask['summary'] ?? '',
                $subTask['key'] ?? '',
                $subTask['url'] ?? $this->buildIssueUrl($subTask['key'] ?? '')
            );
        }

        return $lines;
    }

    private function formatLinkedGroupTitleLine(string $summary, string $url): string
    {
        $summary = trim($summary) !== '' ? trim($summary) : 'Без названия';

        return sprintf('*<%s|%s>*', trim($url), $summary);
    }

    private static function extractGroupTitleLabel(string $title): string
    {
        $title = trim($title, "* \t\n\r\0\x0B");
        if (preg_match('/^<[^|>]+\\|(.+)>$/', $title, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($title);
    }

    private function formatIssueTitleLine(string $summary, string $key, string $url): string
    {
        $summary = trim($summary) !== '' ? trim($summary) : 'Без названия';

        return sprintf('<%s|%s>', trim($url), $summary);
    }

    private function formatLinkedIssueTitleLine(string $summary, string $key, string $url): string
    {
        $summary = trim($summary) !== '' ? trim($summary) : 'Без названия';

        return sprintf('*<%s|%s>*', trim($url), $summary);
    }

    private function buildIssueUrl(string $issueKey): string
    {
        return $this->jiraBrowseBaseUrl . rawurlencode(trim($issueKey));
    }
}
